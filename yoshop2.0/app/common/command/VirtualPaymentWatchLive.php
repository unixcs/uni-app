<?php
// +----------------------------------------------------------------------
// | Watch new real virtual-payment attempts and capture evidence
// +----------------------------------------------------------------------
declare (strict_types=1);

namespace app\common\command;

use app\api\model\wxapp\Setting as WxappSettingModel;
use app\common\enum\Client as ClientEnum;
use app\common\enum\order\DeliveryStatus as DeliveryStatusEnum;
use app\common\enum\order\OrderStatus as OrderStatusEnum;
use app\common\enum\order\PayStatus as PayStatusEnum;
use app\common\enum\order\ReceiptStatus as ReceiptStatusEnum;
use app\common\enum\Setting as SettingEnum;
use app\common\enum\payment\trade\TradeStatus as TradeStatusEnum;
use app\common\library\wechat\VirtualPayment as WechatVirtualPayment;
use app\common\model\Goods as GoodsModel;
use app\common\model\store\Setting as StoreSettingModel;
use app\common\model\User as UserModel;
use app\common\model\UserOauth as UserOauthModel;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\facade\Db;

class VirtualPaymentWatchLive extends Command
{
    private const DEFAULT_STORE_ID = 10001;
    private const DEFAULT_USER_MOBILE = '19900000000';
    private const DEFAULT_TIMEOUT_SECONDS = 180;
    private const DEFAULT_POLL_INTERVAL_MS = 2000;
    private const DEFAULT_SETTLE_SECONDS = 45;
    private const DEFAULT_EVIDENCE_DIR = '/opt/yoshop/yoshop2.0/runtime/codex-evidence/virtual-payment-watch-live';

    protected function configure()
    {
        $this->setName('virtual-payment:watch-live')
            ->addOption('store-id', null, Option::VALUE_OPTIONAL, 'Store ID', (string)self::DEFAULT_STORE_ID)
            ->addOption('goods-id', null, Option::VALUE_OPTIONAL, 'Virtual-payment service goods ID', '')
            ->addOption('user-mobile', null, Option::VALUE_OPTIONAL, 'Watch for this mall user mobile', self::DEFAULT_USER_MOBILE)
            ->addOption('since-trade-id', null, Option::VALUE_OPTIONAL, 'Only capture trades newer than this trade_id', '0')
            ->addOption('timeout-seconds', null, Option::VALUE_OPTIONAL, 'Watch timeout in seconds', (string)self::DEFAULT_TIMEOUT_SECONDS)
            ->addOption('poll-interval-ms', null, Option::VALUE_OPTIONAL, 'Poll interval in milliseconds', (string)self::DEFAULT_POLL_INTERVAL_MS)
            ->addOption('settle-seconds', null, Option::VALUE_OPTIONAL, 'After capture, continue observing settlement for this many seconds', (string)self::DEFAULT_SETTLE_SECONDS)
            ->addOption('probe-remote-query', null, Option::VALUE_NONE, 'Probe WeChat xpay/query_order for the captured trade')
            ->addOption('evidence-dir', null, Option::VALUE_OPTIONAL, 'Evidence output directory', self::DEFAULT_EVIDENCE_DIR)
            ->setDescription('Watch for a new real wechat_virtual trade and capture live evidence');
    }

    protected function execute(Input $input, Output $output)
    {
        $storeId = (int)$input->getOption('store-id');
        $goodsId = (int)$input->getOption('goods-id');
        $userMobile = trim((string)$input->getOption('user-mobile'));
        $sinceTradeId = max(0, (int)$input->getOption('since-trade-id'));
        $timeoutSeconds = max(1, (int)$input->getOption('timeout-seconds'));
        $pollIntervalMs = max(200, (int)$input->getOption('poll-interval-ms'));
        $settleSeconds = max(0, (int)$input->getOption('settle-seconds'));
        $probeRemoteQuery = (bool)$input->getOption('probe-remote-query');
        $evidenceDir = rtrim((string)$input->getOption('evidence-dir'), '/');

        $report = [
            'run_at' => date('c'),
            'watch_started_at' => date('c'),
            'store_id' => $storeId,
            'goods_id' => $goodsId,
            'user_mobile' => $userMobile,
            'since_trade_id' => $sinceTradeId,
            'timeout_seconds' => $timeoutSeconds,
            'poll_interval_ms' => $pollIntervalMs,
            'settle_seconds' => $settleSeconds,
            'probe_remote_query' => $probeRemoteQuery,
            'watch' => [],
            'captured_trade' => null,
            'captured_order' => null,
            'captured_refund' => null,
            'remote_query_probe' => null,
            'settlement_observation' => [],
            'summary' => [
                'ok' => false,
                'message' => '',
            ],
        ];

        try {
            if ($goodsId <= 0) {
                throw new \RuntimeException('--goods-id is required for live acceptance watching');
            }
            $user = $this->resolveUser($storeId, $userMobile);
            $goods = $this->resolveGoods($storeId, $goodsId);
            $watchStartTime = time();
            $baselineTradeId = $sinceTradeId > 0
                ? $sinceTradeId
                : $this->findCurrentLatestTradeId($storeId, (int)$user['user_id'], (string)$goods['vp_product_id']);
            $report['watch'] = [
                'user_id' => (int)$user['user_id'],
                'goods_id' => (int)$goods['goods_id'],
                'product_id' => (string)$goods['vp_product_id'],
                'baseline_trade_id' => $baselineTradeId,
                'watch_start_time' => $watchStartTime,
            ];

            $deadline = microtime(true) + $timeoutSeconds;
            $capturedTrade = null;
            while (microtime(true) < $deadline) {
                $capturedTrade = $this->findLatestTrade(
                    $storeId,
                    (int)$user['user_id'],
                    (string)$goods['vp_product_id'],
                    $baselineTradeId,
                    $watchStartTime
                );
                if (!empty($capturedTrade)) {
                    break;
                }
                usleep(min($pollIntervalMs, max(200, $timeoutSeconds * 1000)) * 1000);
            }

            if (empty($capturedTrade)) {
                $report['summary'] = [
                    'ok' => false,
                    'message' => sprintf('no new wechat_virtual trade found within %d seconds', $timeoutSeconds),
                ];
                $file = $this->writeEvidence($evidenceDir, $report);
                $output->writeln('evidence: ' . $file);
                $output->writeln('<error>' . $report['summary']['message'] . '</error>');
                return 1;
            }

            $report['captured_trade'] = $this->buildTradePayload($capturedTrade);
            $report['captured_order'] = $this->fetchOrderPayload($storeId, (int)$capturedTrade['order_id']);
            $report['captured_refund'] = $this->fetchRefundPayload($storeId, (int)$capturedTrade['order_id']);
            $report['captured_order_trades'] = $this->fetchOrderTrades((int)$capturedTrade['order_id']);

            if ($probeRemoteQuery) {
                $report['remote_query_probe'] = $this->probeRemoteQuery($storeId, $capturedTrade);
            }
            if ($settleSeconds > 0) {
                $report['settlement_observation'] = $this->observeSettlement(
                    $storeId,
                    (int)$capturedTrade['trade_id'],
                    $pollIntervalMs,
                    $settleSeconds,
                    $probeRemoteQuery
                );
            }

            $finalTrade = !empty($report['settlement_observation']['final_trade'])
                ? $report['settlement_observation']['final_trade']
                : $report['captured_trade'];
            $finalOrder = !empty($report['settlement_observation']['final_order'])
                ? $report['settlement_observation']['final_order']
                : $report['captured_order'];
            $finalOrderTrades = !empty($report['settlement_observation']['final_order_trades'])
                ? $report['settlement_observation']['final_order_trades']
                : $report['captured_order_trades'];
            $latestRemote = $this->pickLatestRemoteProbe($report);
            $isPaid = $this->isTradeAndOrderPaid($finalTrade, $finalOrder, $finalOrderTrades)
                && (!$probeRemoteQuery || $this->isRemotePaid($latestRemote));
            $report['summary'] = [
                'ok' => $isPaid,
                'message' => sprintf(
                    '%s trade_id=%d out_trade_no=%s order_id=%d',
                    $isPaid ? 'captured paid trade' : 'captured unpaid trade',
                    (int)$finalTrade['trade_id'],
                    (string)$finalTrade['out_trade_no'],
                    (int)$finalTrade['order_id']
                ),
            ];
            $file = $this->writeEvidence($evidenceDir, $report);
            $output->writeln(sprintf(
                '%s trade_id=%d out_trade_no=%s order_id=%d',
                $isPaid ? 'captured paid trade' : 'captured unpaid trade',
                (int)$finalTrade['trade_id'],
                (string)$finalTrade['out_trade_no'],
                (int)$finalTrade['order_id']
            ));
            $output->writeln('evidence: ' . $file);
            if (!$isPaid) {
                $output->writeln('<comment>local order/trade have not converged to paid yet</comment>');
            }
            if (!empty($latestRemote['result']['errcode']) || !empty($latestRemote['exception'])) {
                $output->writeln('<comment>remote query did not confirm a paid order yet</comment>');
            } elseif ($probeRemoteQuery && !$this->isRemotePaid($latestRemote)) {
                $status = (int)($latestRemote['result']['order']['status'] ?? -1);
                $output->writeln(sprintf('<comment>remote query returned non-paid status=%d</comment>', $status));
            }
            return $isPaid ? 0 : 1;
        } catch (\Throwable $e) {
            $report['summary'] = [
                'ok' => false,
                'message' => $e->getMessage(),
            ];
            $file = $this->writeEvidence($evidenceDir, $report);
            $output->writeln('evidence: ' . $file);
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return 1;
        }
    }

    private function resolveUser(int $storeId, string $mobile): array
    {
        $user = UserModel::detail(['store_id' => $storeId, 'mobile' => $mobile]);
        if (empty($user)) {
            throw new \RuntimeException(sprintf('no user found for mobile %s', $mobile));
        }
        return $user->toArray();
    }

    private function resolveGoods(int $storeId, int $goodsId): array
    {
        $goods = Db::name('goods')->where('store_id', '=', $storeId)->where('goods_id', '=', $goodsId)->find();
        if (empty($goods) || trim((string)($goods['vp_product_id'] ?? '')) === '') {
            throw new \RuntimeException('no virtual-payment goods found to watch');
        }
        return $goods;
    }

    private function findCurrentLatestTradeId(int $storeId, int $userId, string $productId): int
    {
        return (int)(Db::name('payment_trade')
            ->where('store_id', '=', $storeId)
            ->where('user_id', '=', $userId)
            ->where('platform', '=', 'wechat_virtual')
            ->where('product_id', '=', $productId)
            ->max('trade_id') ?: 0);
    }

    private function findLatestTrade(int $storeId, int $userId, string $productId, int $sinceTradeId, int $watchStartTime): array
    {
        return Db::name('payment_trade')
            ->where('store_id', '=', $storeId)
            ->where('user_id', '=', $userId)
            ->where('platform', '=', 'wechat_virtual')
            ->where('product_id', '=', $productId)
            ->where('trade_id', '>', $sinceTradeId)
            ->where('create_time', '>=', max(0, $watchStartTime - 1))
            ->order('trade_id', 'desc')
            ->find() ?: [];
    }

    private function buildTradePayload(array $trade): array
    {
        return [
            'trade_id' => (int)$trade['trade_id'],
            'order_id' => (int)$trade['order_id'],
            'order_no' => (string)$trade['order_no'],
            'user_id' => (int)$trade['user_id'],
            'out_trade_no' => (string)$trade['out_trade_no'],
            'platform' => (string)$trade['platform'],
            'product_id' => (string)$trade['product_id'],
            'env' => (int)$trade['env'],
            'goods_price' => (int)$trade['goods_price'],
            'trade_state' => (int)$trade['trade_state'],
            'notify_times' => (int)$trade['notify_times'],
            'last_notify_time' => (int)$trade['last_notify_time'],
            'create_time' => (int)$trade['create_time'],
            'update_time' => (int)$trade['update_time'],
            'payload_snapshot_raw' => (string)($trade['payload_snapshot'] ?? ''),
            'payload_snapshot_json_valid' => $this->isValidJson((string)($trade['payload_snapshot'] ?? '')),
            'payload_snapshot' => $this->decodePayloadSnapshot((string)($trade['payload_snapshot'] ?? '')),
        ];
    }

    private function fetchTradePayload(int $tradeId): ?array
    {
        $trade = Db::name('payment_trade')->where('trade_id', '=', $tradeId)->find();
        return empty($trade) ? null : $this->buildTradePayload($trade);
    }

    private function fetchOrderPayload(int $storeId, int $orderId): ?array
    {
        $order = Db::name('order')
            ->where('store_id', '=', $storeId)
            ->where('order_id', '=', $orderId)
            ->find();
        if (empty($order)) {
            return null;
        }
        return [
            'order_id' => (int)$order['order_id'],
            'order_no' => (string)$order['order_no'],
            'user_id' => (int)$order['user_id'],
            'pay_status' => (int)$order['pay_status'],
            'order_status' => (int)$order['order_status'],
            'delivery_status' => (int)$order['delivery_status'],
            'receipt_status' => (int)$order['receipt_status'],
            'trade_id' => (int)$order['trade_id'],
            'buyer_remark' => (string)$order['buyer_remark'],
            'create_time' => (int)$order['create_time'],
            'update_time' => (int)$order['update_time'],
        ];
    }

    private function fetchOrderTrades(int $orderId): array
    {
        $rows = Db::name('payment_trade')
            ->where('order_id', '=', $orderId)
            ->order('trade_id', 'asc')
            ->select()
            ->toArray();
        return array_map(fn(array $row): array => $this->buildTradePayload($row), $rows);
    }

    private function fetchRefundPayload(int $storeId, int $orderId): ?array
    {
        $refund = Db::name('order_refund')
            ->where('store_id', '=', $storeId)
            ->where('order_id', '=', $orderId)
            ->order('order_refund_id', 'desc')
            ->find();
        if (empty($refund)) {
            return null;
        }
        return [
            'order_refund_id' => (int)$refund['order_refund_id'],
            'status' => (int)$refund['status'],
            'audit_status' => (int)$refund['audit_status'],
            'refund_money' => (string)$refund['refund_money'],
        ];
    }

    private function observeSettlement(int $storeId, int $tradeId, int $pollIntervalMs, int $settleSeconds, bool $probeRemoteQuery): array
    {
        $observation = [
            'settle_seconds' => $settleSeconds,
            'snapshots' => [],
            'final_trade' => null,
            'final_order' => null,
            'final_order_trades' => [],
            'final_refund' => null,
            'final_remote_query' => null,
        ];
        $deadline = microtime(true) + $settleSeconds;
        while (true) {
            $trade = $this->fetchTradePayload($tradeId);
            if ($trade === null) {
                break;
            }
            $order = $this->fetchOrderPayload($storeId, (int)$trade['order_id']);
            $orderTrades = $this->fetchOrderTrades((int)$trade['order_id']);
            $focusTrade = $this->pickFocusTrade($trade, $order, $orderTrades);
            $refund = $this->fetchRefundPayload($storeId, (int)$trade['order_id']);
            $remote = null;
            if ($probeRemoteQuery) {
                $remote = $this->probeRemoteQuery($storeId, $focusTrade);
            }
            $snapshot = [
                'captured_at' => date('c'),
                'trade' => $focusTrade,
                'captured_trade' => $trade,
                'order' => $order,
                'order_trades' => $orderTrades,
                'refund' => $refund,
                'remote_query' => $remote,
            ];
            $observation['snapshots'][] = $snapshot;
            $observation['final_trade'] = $focusTrade;
            $observation['final_order'] = $order;
            $observation['final_order_trades'] = $orderTrades;
            $observation['final_refund'] = $refund;
            $observation['final_remote_query'] = $remote;

            if ($this->isTradeAndOrderPaid($focusTrade, $order, $orderTrades) && (!$probeRemoteQuery || $this->isRemotePaid($remote))) {
                break;
            }
            if (microtime(true) >= $deadline) {
                break;
            }
            usleep($pollIntervalMs * 1000);
        }
        return $observation;
    }

    private function isTradeAndOrderPaid(?array $trade, ?array $order, array $orderTrades = []): bool
    {
        $linkedTradeId = (int)($order['trade_id'] ?? 0);
        $linkedTrade = null;
        foreach ($orderTrades as $item) {
            if ((int)($item['trade_id'] ?? 0) === $linkedTradeId) {
                $linkedTrade = $item;
                break;
            }
        }
        $effectiveTrade = $linkedTrade ?: $trade;
        return !empty($trade)
            && !empty($order)
            && !empty($effectiveTrade)
            && (int)($effectiveTrade['trade_state'] ?? 0) === TradeStatusEnum::SUCCESS
            && (int)($order['pay_status'] ?? 0) === PayStatusEnum::SUCCESS
            && (int)($order['trade_id'] ?? 0) === (int)($effectiveTrade['trade_id'] ?? 0)
            && (int)($order['order_status'] ?? 0) === OrderStatusEnum::NORMAL
            && (int)($order['delivery_status'] ?? 0) === DeliveryStatusEnum::NOT_DELIVERED
            && (int)($order['receipt_status'] ?? 0) === ReceiptStatusEnum::NOT_RECEIVED;
    }

    private function isRemotePaid(?array $remote): bool
    {
        if (empty($remote) || !empty($remote['exception']) || !empty($remote['error'])) {
            return false;
        }
        return (int)($remote['result']['errcode'] ?? -1) === 0
            && (int)($remote['result']['order']['status'] ?? -1) === 2;
    }

    private function pickLatestRemoteProbe(array $report): ?array
    {
        $settlement = (array)($report['settlement_observation'] ?? []);
        if (!empty($settlement['final_remote_query'])) {
            return (array)$settlement['final_remote_query'];
        }
        if (!empty($report['remote_query_probe'])) {
            return (array)$report['remote_query_probe'];
        }
        return null;
    }

    private function pickFocusTrade(array $capturedTrade, ?array $order, array $orderTrades): array
    {
        $linkedTradeId = (int)($order['trade_id'] ?? 0);
        if ($linkedTradeId > 0) {
            foreach ($orderTrades as $trade) {
                if ((int)($trade['trade_id'] ?? 0) === $linkedTradeId) {
                    return $trade;
                }
            }
        }
        return $capturedTrade;
    }

    private function probeRemoteQuery(int $storeId, array $trade): array
    {
        $userId = (int)$trade['user_id'];
        $env = (int)$trade['env'];
        $outTradeNo = (string)$trade['out_trade_no'];
        $oauth = UserOauthModel::getOauth($userId, ClientEnum::MP_WEIXIN);
        $openid = (string)($oauth['oauth_id'] ?? '');
        $report = [
            'out_trade_no' => $outTradeNo,
            'user_id' => $userId,
            'openid_present' => $openid !== '',
            'env' => $env,
        ];
        if ($openid === '') {
            $report['error'] = 'missing MP-WEIXIN openid';
            return $report;
        }

        $config = StoreSettingModel::getItem(SettingEnum::VIRTUAL_PAYMENT, $storeId);
        $wxapp = WxappSettingModel::getConfigBasic($storeId);
        $appId = (string)($wxapp['app_id'] ?? '');
        $appSecret = (string)($wxapp['app_secret'] ?? '');
        $appKey = $env === GoodsModel::VP_ENV_SANDBOX
            ? (string)($config['sandbox_app_key'] ?? '')
            : (string)($config['production_app_key'] ?? '');
        if ($appId === '' || $appSecret === '' || $appKey === '') {
            $report['error'] = 'missing app_id/app_secret/app_key';
            return $report;
        }

        $payload = [
            'openid' => $openid,
            'env' => $env,
            'order_id' => $outTradeNo,
        ];
        $report['payload'] = [
            'openid_masked' => substr($openid, 0, 6) . '***' . substr($openid, -4),
            'env' => $env,
            'order_id' => $outTradeNo,
        ];
        try {
            $payment = new WechatVirtualPayment($appId, $appSecret, $appKey);
            $report['result'] = $payment->queryOrder($payload);
            $report['interpretation'] = $this->interpretRemoteQueryProbe((array)$report['result']);
        } catch (\Throwable $e) {
            $report['exception'] = [
                'class' => get_class($e),
                'message' => $e->getMessage(),
            ];
        }
        return $report;
    }

    private function decodePayloadSnapshot(string $snapshot): array
    {
        if ($snapshot === '') {
            return [];
        }
        $data = json_decode($snapshot, true);
        return is_array($data) ? $data : [];
    }

    private function isValidJson(string $value): bool
    {
        if ($value === '') {
            return true;
        }
        json_decode($value, true);
        return json_last_error() === JSON_ERROR_NONE;
    }

    private function interpretRemoteQueryProbe(array $result): array
    {
        $errCode = (int)($result['errcode'] ?? -1);
        $errMsg = (string)($result['errmsg'] ?? '');
        $status = (int)($result['order']['status'] ?? -1);
        if ($errCode === 0) {
            if ($status === 2) {
                return [
                    'phase' => 'remote_paid',
                    'message' => 'WeChat remote order exists and is paid',
                ];
            }
            if ($status === 6) {
                return [
                    'phase' => 'remote_order_closed',
                    'message' => 'WeChat remote order exists but status=6 means the order was closed before payment success; prioritize DevTools automator residue, preview-vs-experience runtime differences, and sandbox test-account/product whitelist checks',
                ];
            }
            if ($status >= 0) {
                return [
                    'phase' => 'remote_order_exists_not_paid',
                    'message' => sprintf('WeChat remote order exists but status=%d is not the paid state', $status),
                ];
            }
            return [
                'phase' => 'remote_order_payload_missing_status',
                'message' => 'WeChat remote query returned errcode=0 but no recognized order status',
            ];
        }
        if ($errCode === 268490002 && str_contains($errMsg, '数据不存在')) {
            return [
                'phase' => 'remote_order_not_created_yet',
                'message' => 'WeChat remote query says the order does not exist yet; this is consistent with a backend-generated trade that has not been launched/accepted by wx.requestVirtualPayment on the client',
            ];
        }
        return [
            'phase' => 'remote_query_error',
            'message' => sprintf('WeChat remote query returned errcode=%d errmsg=%s', $errCode, $errMsg),
        ];
    }

    private function writeEvidence(string $dir, array $report): string
    {
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('failed to create evidence dir: %s', $dir));
        }
        $file = $dir . '/watch-' . date('YmdHis') . '-' . substr(str_replace('.', '', (string)microtime(true)), -6) . '.json';
        $json = json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false || file_put_contents($file, $json) === false) {
            throw new \RuntimeException(sprintf('failed to write evidence file: %s', $file));
        }
        return $file;
    }
}
