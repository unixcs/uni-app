<?php
// +----------------------------------------------------------------------
// | iOS App Store 退款风险历史回填（默认 dry-run）
// +----------------------------------------------------------------------
declare (strict_types=1);

namespace app\common\command;

use app\common\enum\order\iosRefund\RiskSource as RiskSourceEnum;
use app\common\enum\order\iosRefund\RiskStatus as RiskStatusEnum;
use app\common\enum\order\refund\AuditStatus as AuditStatusEnum;
use app\common\enum\order\refund\RefundType as RefundTypeEnum;
use app\common\enum\payment\trade\ChannelClass as ChannelClassEnum;
use app\common\model\Order as OrderModel;
use app\common\model\OrderRefund as OrderRefundModel;
use app\common\model\PaymentIosRefundInquiry as InquiryModel;
use app\common\model\PaymentTrade as PaymentTradeModel;
use app\common\service\order\IosRefundRisk as IosRefundRiskService;
use app\common\service\order\Refund as RefundService;
use app\common\library\helper;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\facade\Db;

class VirtualPaymentIosRefundRiskBackfill extends Command
{
    protected function configure()
    {
        $this->setName('virtual-payment:ios-refund-risk-backfill')
            ->addOption('apply', null, Option::VALUE_NONE, 'Persist changes; default is dry-run')
            ->addOption('from-trade-id', null, Option::VALUE_OPTIONAL, 'Start after this trade_id', '0')
            ->addOption('limit', null, Option::VALUE_OPTIONAL, 'Maximum iOS trades; 0 means unlimited', '0')
            ->addOption('batch-size', null, Option::VALUE_OPTIONAL, 'Rows per batch', '200')
            ->setDescription('Backfill irreversible iOS refund risk from local refunds and authenticated callback snapshots');
    }

    protected function execute(Input $input, Output $output)
    {
        $apply = (bool)$input->getOption('apply');
        $cursor = max(0, (int)$input->getOption('from-trade-id'));
        $limit = max(0, (int)$input->getOption('limit'));
        $batchSize = min(1000, max(1, (int)$input->getOption('batch-size')));
        $summary = [
            'mode' => $apply ? 'apply' : 'dry-run',
            'inspected' => 0,
            'eligible' => 0,
            'changed' => 0,
            'unchanged' => 0,
            'ignored_ios_flag_only' => 0,
            'migrated_inquiries' => 0,
            'conflicts' => 0,
            'errors' => 0,
            'last_trade_id' => $cursor,
        ];

        while ($limit === 0 || $summary['inspected'] < $limit) {
            $take = $limit === 0 ? $batchSize : min($batchSize, $limit - $summary['inspected']);
            $trades = (new PaymentTradeModel)
                ->where('trade_id', '>', $cursor)
                ->where('platform', '=', 'wechat_virtual')
                ->where('channel_class', '=', ChannelClassEnum::IOS_APPLE)
                ->order(['trade_id' => 'asc'])
                ->limit($take)
                ->select();
            if ($trades->isEmpty()) {
                break;
            }
            foreach ($trades as $trade) {
                $tradeId = (int)$trade['trade_id'];
                $cursor = $tradeId;
                $summary['last_trade_id'] = $tradeId;
                $summary['inspected']++;
                try {
                    $result = $this->inspectTrade($trade, $apply);
                    foreach (['eligible', 'changed', 'unchanged', 'ignored_ios_flag_only', 'migrated_inquiries', 'conflicts'] as $key) {
                        $summary[$key] += (int)($result[$key] ?? 0);
                    }
                    if (!empty($result['change'])) {
                        $output->writeln(helper::jsonEncode($result['change'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                    }
                } catch (\Throwable $e) {
                    $summary['errors']++;
                    $output->warning(sprintf('trade_id=%d error=%s', $tradeId, $e->getMessage()));
                }
            }
        }

        $output->writeln(helper::jsonEncode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        // 绑定冲突是需要人工核对的安全跳过，不是命令执行错误。
        return $summary['errors'] === 0 ? 0 : 1;
    }

    private function inspectTrade(PaymentTradeModel $trade, bool $apply): array
    {
        $order = OrderModel::detail((int)$trade['order_id']);
        if (empty($order)
            || (int)$order['trade_id'] !== (int)$trade['trade_id']
            || (int)$order['store_id'] !== (int)$trade['store_id']
            || (int)$order['user_id'] !== (int)$trade['user_id']) {
            return ['conflicts' => 1];
        }
        $snapshot = PaymentTradeModel::decodePayloadSnapshot((string)($trade['payload_snapshot'] ?? ''));
        $localRefund = (new OrderRefundModel)
            ->where('order_id', '=', (int)$order['order_id'])
            ->where('type', '=', RefundTypeEnum::SERVICE)
            ->order(['order_refund_id' => 'asc'])
            ->find();
        $inquirySnapshot = (array)($snapshot['ios_refund_query_notify'] ?? []);
        $refundNotify = (array)($snapshot['refund_notify'] ?? []);
        $refundPayload = (array)($refundNotify['payload'] ?? []);
        $hasTrustedSuccess = (string)($refundNotify['event'] ?? '') === 'xpay_refund_notify'
            && RefundService::isSuccessfulVirtualRefundNotify($refundPayload);
        $hasInquiry = (string)($inquirySnapshot['event'] ?? '') === 'xpay_subscribe_ios_refund_query_notify';
        $hasLocalRefund = !empty($localRefund);
        if (!$hasTrustedSuccess && !$hasInquiry && !$hasLocalRefund) {
            $virtualRefund = (array)($snapshot['virtual_refund'] ?? []);
            return [
                'ignored_ios_flag_only' => !empty($virtualRefund['ios_refund_required']) ? 1 : 0,
                'unchanged' => 1,
            ];
        }

        $target = $hasTrustedSuccess ? RiskStatusEnum::REFUNDED : RiskStatusEnum::LOCKED;
        $source = $hasLocalRefund
            ? RiskSourceEnum::LOCAL_APPLY
            : ($hasInquiry ? RiskSourceEnum::APPLE_INQUIRY : RiskSourceEnum::REFUND_NOTIFY_RECOVERY);
        $current = (int)($order['ios_refund_risk_status'] ?? RiskStatusEnum::NONE);
        $needsRiskChange = $target > $current;
        $migrationKey = $hasInquiry ? 'legacy-ios-inquiry-trade-' . (int)$trade['trade_id'] : null;
        $hasMigratedInquiry = $migrationKey !== null
            && (new InquiryModel)->where('migration_key', '=', $migrationKey)->count() > 0;
        $needsInquiry = $migrationKey !== null && !$hasMigratedInquiry;
        $change = [
            'order_id' => (int)$order['order_id'],
            'trade_id' => (int)$trade['trade_id'],
            'risk_from' => $current,
            'risk_to' => max($current, $target),
            'source' => $source,
            'migrate_inquiry' => $needsInquiry,
        ];
        if ($apply && ($needsRiskChange || $needsInquiry)) {
            Db::transaction(function () use ($order, $trade, $target, $source, $needsInquiry, $migrationKey, $inquirySnapshot, $localRefund) {
                $lockedOrder = (new OrderModel)->where('order_id', '=', (int)$order['order_id'])->lock(true)->find();
                $refunds = (new OrderRefundModel)
                    ->where('order_id', '=', (int)$lockedOrder['order_id'])
                    ->where('type', '=', RefundTypeEnum::SERVICE)
                    ->order(['order_refund_id' => 'asc'])
                    ->lock(true)
                    ->select();
                $lockedTrade = (new PaymentTradeModel)->where('trade_id', '=', (int)$trade['trade_id'])->lock(true)->find();
                if (empty($lockedTrade) || (int)$lockedOrder['trade_id'] !== (int)$lockedTrade['trade_id']) {
                    throw new \RuntimeException('final trade binding changed during backfill');
                }
                $riskSaved = $target === RiskStatusEnum::REFUNDED
                    ? IosRefundRiskService::markRefunded($lockedOrder, $source)
                    : IosRefundRiskService::lockOrder($lockedOrder, $source);
                if (!$riskSaved) {
                    throw new \RuntimeException('failed to persist iOS refund risk during backfill');
                }
                if ($needsInquiry && (new InquiryModel)->where('migration_key', '=', $migrationKey)->count() === 0) {
                    $payload = (array)($inquirySnapshot['payload'] ?? []);
                    $refund = !$refunds->isEmpty() ? $refunds[$refunds->count() - 1] : $localRefund;
                    $decision = (string)($lockedTrade['payload_snapshot'] ?? '');
                    $decoded = PaymentTradeModel::decodePayloadSnapshot($decision);
                    $suggestion = (string)($decoded['virtual_refund']['ios_refund_query_decision'] ?? 'suggest_reject');
                    $inquiry = new InquiryModel;
                    if ($inquiry->save([
                        'order_id' => (int)$lockedOrder['order_id'],
                        'order_refund_id' => (int)($refund['order_refund_id'] ?? 0),
                        'trade_id' => (int)$lockedTrade['trade_id'],
                        'store_id' => (int)$lockedOrder['store_id'],
                        'user_id' => (int)$lockedOrder['user_id'],
                        'pay_order_id' => (string)$lockedTrade['out_trade_no'],
                        'fingerprint' => hash('sha256', helper::jsonEncode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
                        'migration_key' => $migrationKey,
                        'binding_status' => 'BOUND_LEGACY',
                        'request_reason' => (string)($payload['refund_request_reason'] ?? ''),
                        'request_payload' => helper::jsonEncode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        'service_stage' => IosRefundRiskService::serviceStage($lockedOrder),
                        'order_status' => (int)$lockedOrder['order_status'],
                        'delivery_status' => (int)$lockedOrder['delivery_status'],
                        'receipt_status' => (int)$lockedOrder['receipt_status'],
                        'audit_status' => !empty($refund) ? (int)$refund['audit_status'] : null,
                        'result_code' => $suggestion === 'suggest_refund' ? 0 : 1,
                        'result_info' => $suggestion === 'suggest_refund' ? '建议退款' : '建议拒绝退款',
                        'evidence' => '历史认证问询快照迁移',
                        'response_ms' => 0,
                        'received_at' => (int)($inquirySnapshot['received_at'] ?? time()),
                    ]) === false) {
                        throw new \RuntimeException('failed to persist migrated Apple inquiry');
                    }
                }
            });
        }
        return [
            'eligible' => 1,
            'changed' => ($needsRiskChange || $needsInquiry) ? 1 : 0,
            'unchanged' => ($needsRiskChange || $needsInquiry) ? 0 : 1,
            'migrated_inquiries' => $needsInquiry ? 1 : 0,
            'change' => ($needsRiskChange || $needsInquiry) ? $change : null,
        ];
    }
}
