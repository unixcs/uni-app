<?php
// +----------------------------------------------------------------------
// | iOS App Store 退款问询、不可逆服务冻结与状态投影
// +----------------------------------------------------------------------
declare (strict_types=1);

namespace app\common\service\order;

use app\common\enum\order\DeliveryStatus as DeliveryStatusEnum;
use app\common\enum\order\OrderStatus as OrderStatusEnum;
use app\common\enum\order\PayStatus as PayStatusEnum;
use app\common\enum\order\ReceiptStatus as ReceiptStatusEnum;
use app\common\enum\order\iosRefund\RiskSource as RiskSourceEnum;
use app\common\enum\order\iosRefund\RiskStatus as RiskStatusEnum;
use app\common\enum\order\refund\AuditStatus as AuditStatusEnum;
use app\common\enum\order\refund\RefundStatus as RefundStatusEnum;
use app\common\enum\order\refund\RefundType as RefundTypeEnum;
use app\common\enum\payment\trade\ChannelClass as ChannelClassEnum;
use app\common\enum\payment\trade\TradeStatus as TradeStatusEnum;
use app\common\model\Order as OrderModel;
use app\common\model\OrderGoods as OrderGoodsModel;
use app\common\model\OrderRefund as OrderRefundModel;
use app\common\model\PaymentIosRefundInquiry as InquiryModel;
use app\common\model\PaymentTrade as PaymentTradeModel;
use app\common\library\helper;
use think\facade\Db;

/**
 * 本服务只管理 iOS 退款风险事实；不处理积分、结算、优惠券或库存。
 */
class IosRefundRisk
{
    const STAGE_NOT_STARTED = 'NOT_STARTED';
    const STAGE_IN_PROGRESS = 'IN_PROGRESS';
    const STAGE_COMPLETED = 'COMPLETED';
    const STAGE_UNKNOWN = 'UNKNOWN';

    /**
     * 处理一次已经通过入口签名认证的 Apple 退款问询。
     * 每次调用都会留下独立事件；相同 payload 不复用旧决定。
     * @param string $payOrderId
     * @param array $payload
     * @param float|null $startedAt
     * @return array{result_code:int,result_info:string,evidence:string}
     */
    public function handleInquiry(string $payOrderId, array $payload, ?float $startedAt = null): array
    {
        $startedAt = $startedAt ?: microtime(true);
        $receivedAt = time();
        $fingerprint = $this->fingerprint($payload);
        $candidateTrade = $payOrderId === '' ? null : PaymentTradeModel::detail(['out_trade_no' => $payOrderId]);

        if (empty($candidateTrade)) {
            return $this->recordUnboundInquiry(
                $payOrderId,
                $payload,
                $fingerprint,
                'TRADE_NOT_FOUND',
                $receivedAt,
                $startedAt
            );
        }

        return Db::transaction(function () use (
            $candidateTrade,
            $payOrderId,
            $payload,
            $fingerprint,
            $receivedAt,
            $startedAt
        ) {
            $candidateOrderId = (int)($candidateTrade['order_id'] ?? 0);
            $order = (new OrderModel)
                ->where('order_id', '=', $candidateOrderId)
                ->lock(true)
                ->find();
            if (empty($order)) {
                return $this->insertInquiryAndRespond([
                    'trade_id' => (int)($candidateTrade['trade_id'] ?? 0),
                    'store_id' => (int)($candidateTrade['store_id'] ?? 0),
                    'user_id' => (int)($candidateTrade['user_id'] ?? 0),
                ], $payOrderId, $payload, $fingerprint, 'ORDER_NOT_FOUND', self::STAGE_UNKNOWN, null, 1, $receivedAt, $startedAt);
            }

            // 固定锁顺序：order -> all relevant refunds -> final trade -> inquiry insert。
            $refunds = (new OrderRefundModel)
                ->where('order_id', '=', (int)$order['order_id'])
                ->where('type', '=', RefundTypeEnum::SERVICE)
                ->order(['order_refund_id' => 'asc'])
                ->lock(true)
                ->select();
            $finalTrade = (new PaymentTradeModel)
                ->where('trade_id', '=', (int)($order['trade_id'] ?? 0))
                ->lock(true)
                ->find();

            $bindingStatus = $this->validateFinalBinding($order, $candidateTrade, $finalTrade, $payOrderId);
            if ($bindingStatus !== 'BOUND') {
                // 绑定失败只保留零订单安全审计，不能污染候选订单的业务时间线或投影。
                return $this->insertInquiryAndRespond([
                    'order_id' => 0,
                    'trade_id' => (int)($candidateTrade['trade_id'] ?? 0),
                    'store_id' => (int)($candidateTrade['store_id'] ?? 0),
                    'user_id' => (int)($candidateTrade['user_id'] ?? 0),
                ], $payOrderId, $payload, $fingerprint, $bindingStatus, self::STAGE_UNKNOWN, null, 1, $receivedAt, $startedAt);
            }
            if (!OrderModel::isServiceOrderData($order)) {
                return $this->insertInquiryAndRespond([
                    'order_id' => (int)$order['order_id'],
                    'trade_id' => (int)$finalTrade['trade_id'],
                    'store_id' => (int)$order['store_id'],
                    'user_id' => (int)$order['user_id'],
                    'order_status' => (int)$order['order_status'],
                    'delivery_status' => (int)$order['delivery_status'],
                    'receipt_status' => (int)$order['receipt_status'],
                ], $payOrderId, $payload, $fingerprint, 'NOT_SERVICE_ORDER', self::STAGE_UNKNOWN, null, 1, $receivedAt, $startedAt);
            }

            $stage = $this->serviceStage($order);
            $refund = $refunds->isEmpty() ? null : $refunds[$refunds->count() - 1];
            if (empty($refund) && in_array($stage, [self::STAGE_NOT_STARTED, self::STAGE_IN_PROGRESS], true)) {
                $refund = $this->createInquiryRefund($order, $payload, $stage);
            }
            $auditStatus = empty($refund) ? null : (int)$refund['audit_status'];
            $resultCode = $this->decision($stage, $auditStatus);

            if (!$this->lockOrder($order, RiskSourceEnum::APPLE_INQUIRY, $receivedAt)) {
                throwError('建立Apple退款服务冻结失败');
            }
            $resultInfo = $resultCode === 0 ? '建议退款' : '建议拒绝退款';
            $evidence = $this->buildEvidence($stage, $auditStatus, $payload, $resultCode);

            $snapshot = PaymentTradeModel::decodePayloadSnapshot((string)($finalTrade['payload_snapshot'] ?? ''));
            $snapshot['ios_refund_query_notify'] = [
                'event' => (string)($payload['Event'] ?? 'xpay_subscribe_ios_refund_query_notify'),
                'received_at' => $receivedAt,
                'payload' => $payload,
            ];
            $snapshot['virtual_refund'] = array_merge((array)($snapshot['virtual_refund'] ?? []), [
                'ios_refund_required' => true,
                'ios_refund_query_received_at' => $receivedAt,
                'ios_refund_query_decision' => $resultCode === 0 ? 'suggest_refund' : 'suggest_reject',
                'order_refund_id' => (int)($refund['order_refund_id'] ?? 0),
            ]);
            if ($finalTrade->save([
                'channel_class' => ChannelClassEnum::IOS_APPLE,
                'notify_times' => (int)($finalTrade['notify_times'] ?? 0) + 1,
                'last_notify_time' => $receivedAt,
                'payload_snapshot' => helper::jsonEncode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]) === false) {
                throwError('记录Apple退款问询交易快照失败');
            }

            $this->insertInquiry([
                'order_id' => (int)$order['order_id'],
                'order_refund_id' => (int)($refund['order_refund_id'] ?? 0),
                'trade_id' => (int)$finalTrade['trade_id'],
                'store_id' => (int)$order['store_id'],
                'user_id' => (int)$order['user_id'],
                'pay_order_id' => $payOrderId,
                'fingerprint' => $fingerprint,
                'binding_status' => 'BOUND',
                'request_reason' => $this->requestReason($payload),
                'request_payload' => helper::jsonEncode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'service_stage' => $stage,
                'order_status' => (int)$order['order_status'],
                'delivery_status' => (int)$order['delivery_status'],
                'receipt_status' => (int)$order['receipt_status'],
                'audit_status' => $auditStatus,
                'result_code' => $resultCode,
                'result_info' => $resultInfo,
                'evidence' => $evidence,
                'response_ms' => $this->elapsedMs($startedAt),
                'received_at' => $receivedAt,
            ]);

            return [
                'result_code' => $resultCode,
                'result_info' => $resultInfo,
                'evidence' => $evidence,
            ];
        });
    }

    /**
     * 风险状态只能单调推进到 LOCKED。
     */
    public static function lockOrder($order, string $source, ?int $time = null): bool
    {
        $current = (int)($order['ios_refund_risk_status'] ?? RiskStatusEnum::NONE);
        if ($current >= RiskStatusEnum::LOCKED) {
            return true;
        }
        return $order->save([
            'ios_refund_risk_status' => RiskStatusEnum::LOCKED,
            'ios_refund_risk_source' => $source,
            'ios_refund_risk_time' => $time ?: time(),
        ]) !== false;
    }

    /**
     * 风险状态只能单调推进到 REFUNDED，首次风险来源不被覆盖。
     */
    public static function markRefunded($order, string $fallbackSource = RiskSourceEnum::REFUND_NOTIFY_RECOVERY): bool
    {
        $data = ['ios_refund_risk_status' => RiskStatusEnum::REFUNDED];
        if ((int)($order['ios_refund_risk_status'] ?? 0) === RiskStatusEnum::NONE) {
            $data['ios_refund_risk_source'] = $fallbackSource;
            $data['ios_refund_risk_time'] = time();
        }
        return $order->save($data) !== false;
    }

    public static function isLocked($order): bool
    {
        return (int)($order['ios_refund_risk_status'] ?? RiskStatusEnum::NONE) >= RiskStatusEnum::LOCKED;
    }

    /**
     * 在 order -> trade 锁顺序内原子建立 provide_goods 发送权。
     * 发送权先提交则后续问询线性化在其后；退款风险先提交则不能再建立新发送权。
     * @return array{claimed:bool,state:string,reason?:string,snapshot?:array}
     */
    public static function claimProvideGoodsDispatchIfAllowed(
        int $orderId,
        int $tradeId,
        string $source
    ): array {
        return Db::transaction(function () use ($orderId, $tradeId, $source) {
            $order = (new OrderModel)
                ->where('order_id', '=', $orderId)
                ->lock(true)
                ->find();
            if (empty($order)) {
                return ['claimed' => false, 'state' => 'missing_order', 'reason' => 'missing_order'];
            }
            if ((int)($order['trade_id'] ?? 0) !== $tradeId) {
                return ['claimed' => false, 'state' => 'trade_conflict', 'reason' => 'final_trade_conflict'];
            }
            if (self::isLocked($order)) {
                return ['claimed' => false, 'state' => 'risk_locked', 'reason' => 'ios_refund_risk_locked'];
            }
            $claim = PaymentTradeModel::claimProvideGoodsDispatch($tradeId, $source);
            return is_array($claim)
                ? $claim
                : ['claimed' => false, 'state' => 'missing_trade', 'reason' => 'missing_trade'];
        });
    }

    public static function riskText(int $status): string
    {
        if ($status === RiskStatusEnum::REFUNDED) {
            return 'App Store退款成功';
        }
        if ($status === RiskStatusEnum::LOCKED) {
            return '服务已冻结';
        }
        return '无退款风险';
    }

    public static function serviceStage($order): string
    {
        if ((int)($order['order_status'] ?? 0) === OrderStatusEnum::COMPLETED
            || (int)($order['receipt_status'] ?? 0) === ReceiptStatusEnum::RECEIVED) {
            return self::STAGE_COMPLETED;
        }
        if ((int)($order['order_status'] ?? 0) !== OrderStatusEnum::NORMAL
            || (int)($order['pay_status'] ?? 0) !== PayStatusEnum::SUCCESS) {
            return self::STAGE_UNKNOWN;
        }
        if ((int)($order['delivery_status'] ?? 0) === DeliveryStatusEnum::NOT_DELIVERED) {
            return self::STAGE_NOT_STARTED;
        }
        if ((int)($order['delivery_status'] ?? 0) === DeliveryStatusEnum::DELIVERED
            && (int)($order['receipt_status'] ?? 0) === ReceiptStatusEnum::NOT_RECEIVED) {
            return self::STAGE_IN_PROGRESS;
        }
        return self::STAGE_UNKNOWN;
    }

    /**
     * 构建后端统一投影。调用方可传入批量加载的 latest inquiry，避免列表 N+1。
     * @param mixed $order
     * @param mixed $trade
     * @param mixed $refund
     * @param array|null $latestInquiry
     */
    public static function buildProjection($order, $trade = null, $refund = null, ?array $latestInquiry = null): array
    {
        $orderData = empty($order) ? [] : (is_array($order) ? $order : $order->toArray());
        $tradeData = empty($trade) ? [] : (is_array($trade) ? $trade : $trade->toArray());
        $refundData = empty($refund) ? [] : (is_array($refund) ? $refund : $refund->toArray());
        $riskStatus = (int)($orderData['ios_refund_risk_status'] ?? RiskStatusEnum::NONE);
        $snapshot = PaymentTradeModel::decodePayloadSnapshot((string)($tradeData['payload_snapshot'] ?? ''));
        $isIos = $riskStatus > RiskStatusEnum::NONE
            || (!empty($tradeData) && PaymentTradeModel::isIosAppleVirtualTrade($tradeData, $snapshot));
        if ($latestInquiry === null && !empty($orderData['ios_refund_latest_inquiry'])) {
            $latestInquiry = (array)$orderData['ios_refund_latest_inquiry'];
        }
        if ($latestInquiry === null && !empty($orderData['order_id']) && $riskStatus > RiskStatusEnum::NONE) {
            $latest = InquiryModel::latestByOrderId((int)$orderData['order_id']);
            $latestInquiry = $latest ? $latest->toArray() : null;
        }

        if (!empty($latestInquiry)) {
            $latestInquiry = InquiryModel::project((array)$latestInquiry);
        }
        $auditStatus = array_key_exists('audit_status', $refundData) ? (int)$refundData['audit_status'] : null;
        $refundStatus = array_key_exists('status', $refundData) ? (int)$refundData['status'] : null;
        $state = '';
        $text = '';
        $guidance = '';
        if ($isIos) {
            if ($riskStatus === RiskStatusEnum::REFUNDED || $refundStatus === RefundStatusEnum::COMPLETED) {
                $state = 'refunded';
                $text = '已退款';
                $guidance = '退款已完成，最终到账时间以 Apple 和原支付方式处理结果为准。';
            } elseif ($auditStatus === AuditStatusEnum::WAIT) {
                $state = 'merchant_review_waiting';
                $text = '退款申请已提交，等待商家审核中，请耐心等候';
                $guidance = '商家审核期间无需重复提交；原订单已停止履约。';
            } elseif ($auditStatus === AuditStatusEnum::REJECTED || $refundStatus === RefundStatusEnum::REJECTED) {
                $state = 'merchant_rejected';
                $text = '商家已驳回您的退款申请，如有疑问请联系客服';
                $guidance = !empty($latestInquiry)
                    ? '已检测到 App Store 退款申请。商家驳回不代表 Apple 已拒绝，原订单已停止履约，最终结果以 Apple 为准。'
                    : '商家驳回不解除服务冻结；如有疑问请联系客服。';
            } elseif (!empty($latestInquiry) && (int)($latestInquiry['result_code'] ?? 1) === 0) {
                $state = 'waiting_app_store_refund';
                $text = '等待 App Store 退款处理';
                $guidance = '服务端已向 Apple 建议退款，最终结果和到账时间以 Apple 为准。';
            } elseif ($auditStatus === AuditStatusEnum::REVIEWED) {
                $isAutoBeforeService = (string)($orderData['ios_refund_risk_source'] ?? '') === RiskSourceEnum::LOCAL_APPLY
                    && self::serviceStage($orderData) === self::STAGE_NOT_STARTED;
                if (!empty($latestInquiry)) {
                    $state = 'merchant_approved_reapply_required';
                    $text = '商家已同意退款，请重新前往 App Store 尝试提交申请';
                    $guidance = 'Apple 是否允许再次申请以及最终是否退款，以 Apple 实际处理结果为准。';
                } elseif ($isAutoBeforeService) {
                    $state = 'local_refund_submitted';
                    $text = '退款申请已提交，请前往 App Store 申请退款';
                    $guidance = '请按页面教程前往 Apple 官方退款渠道继续申请。';
                } else {
                    $state = 'merchant_approved_apply_required';
                    $text = '商家已同意退款，请前往 App Store 申请退款';
                    $guidance = '请前往 Apple 官方渠道提交申请，最终结果以 Apple 为准。';
                }
            } elseif (!empty($latestInquiry)) {
                $state = (string)($latestInquiry['service_stage'] ?? '') === self::STAGE_COMPLETED
                    ? 'apple_inquiry_rejected'
                    : 'risk_locked_review_unavailable';
                $text = 'App Store退款申请已拦截，服务已冻结';
                $guidance = '服务端已建议拒绝，但 Apple 最终决定仍未知；原订单不会恢复履约。';
            } elseif ($riskStatus === RiskStatusEnum::LOCKED) {
                $state = 'risk_locked_review_unavailable';
                $text = '服务已冻结';
                $guidance = '订单存在 App Store 退款风险，原订单不会恢复履约。';
            }
        }

        return [
            'ios_apple_refund_required' => $isIos,
            'ios_refund_risk_status' => $riskStatus,
            'ios_refund_risk_text' => self::riskText($riskStatus),
            'ios_refund_inquiry_received' => !empty($latestInquiry),
            'latest_ios_refund_inquiry' => $latestInquiry,
            'merchant_refund_review_status' => $auditStatus,
            'refund_display_state' => $state,
            'refund_display_state_text' => $text,
            'refund_guidance' => $guidance,
            'refund_entry_mode' => $isIos ? 'app_store_guided' : 'developer_refund',
            'can_cancel' => !$isIos && $refundStatus === RefundStatusEnum::NORMAL && $auditStatus === AuditStatusEnum::WAIT,
        ];
    }

    private function validateFinalBinding($order, $candidateTrade, $finalTrade, string $payOrderId): string
    {
        if (empty($finalTrade)) {
            return 'FINAL_TRADE_NOT_FOUND';
        }
        if ((int)$candidateTrade['trade_id'] !== (int)$finalTrade['trade_id']) {
            return 'NON_FINAL_TRADE';
        }
        if ((string)($finalTrade['platform'] ?? '') !== 'wechat_virtual') {
            return 'PLATFORM_CONFLICT';
        }
        // Apple 问询只属于 iOS Apple 渠道；不能让一个合法的 Android/非 Apple
        // 虚拟支付单因为伪造或误路由的问询事件被错误冻结或创建服务退款单。
        if (!PaymentTradeModel::isIosAppleVirtualTrade($finalTrade)) {
            return 'NON_IOS_TRADE';
        }
        if (!in_array((int)($finalTrade['trade_state'] ?? 0), [TradeStatusEnum::SUCCESS, TradeStatusEnum::REFUND], true)) {
            return 'TRADE_STATE_CONFLICT';
        }
        if ((int)($order['pay_status'] ?? 0) !== PayStatusEnum::SUCCESS) {
            return 'PAY_STATUS_CONFLICT';
        }
        if ((string)($finalTrade['out_trade_no'] ?? '') !== $payOrderId) {
            return 'PAY_ORDER_CONFLICT';
        }
        if ((int)$finalTrade['order_id'] !== (int)$order['order_id']) {
            return 'ORDER_CONFLICT';
        }
        if ((int)$finalTrade['store_id'] !== (int)$order['store_id']) {
            return 'STORE_CONFLICT';
        }
        if ((int)$finalTrade['user_id'] !== (int)$order['user_id']) {
            return 'USER_CONFLICT';
        }
        if ((string)($finalTrade['order_no'] ?? '') !== ''
            && (string)$finalTrade['order_no'] !== (string)$order['order_no']) {
            return 'ORDER_NO_CONFLICT';
        }
        return 'BOUND';
    }

    private function createInquiryRefund($order, array $payload, string $stage)
    {
        $orderGoodsId = (int)(new OrderGoodsModel)
            ->where('order_id', '=', (int)$order['order_id'])
            ->order(['order_goods_id' => 'asc'])
            ->value('order_goods_id');
        if ($orderGoodsId <= 0) {
            throwError('Apple退款问询对应的订单商品不存在');
        }
        $refund = new OrderRefundModel;
        if ($refund->save([
            'order_id' => (int)$order['order_id'],
            'order_goods_id' => $orderGoodsId,
            'user_id' => (int)$order['user_id'],
            'type' => RefundTypeEnum::SERVICE,
            'apply_desc' => $this->requestReason($payload) ?: 'App Store退款申请',
            'audit_status' => $stage === self::STAGE_NOT_STARTED ? AuditStatusEnum::REVIEWED : AuditStatusEnum::WAIT,
            'status' => RefundStatusEnum::NORMAL,
            'store_id' => (int)$order['store_id'],
        ]) === false) {
            throwError('创建Apple退款跟踪记录失败');
        }
        return $refund;
    }

    private function decision(string $stage, ?int $auditStatus): int
    {
        if ($stage === self::STAGE_NOT_STARTED) {
            return $auditStatus === null || $auditStatus === AuditStatusEnum::REVIEWED ? 0 : 1;
        }
        if ($stage === self::STAGE_IN_PROGRESS) {
            return $auditStatus === AuditStatusEnum::REVIEWED ? 0 : 1;
        }
        return 1;
    }

    private function buildEvidence(string $stage, ?int $auditStatus, array $payload, int $resultCode): string
    {
        $auditText = $auditStatus === AuditStatusEnum::REVIEWED
            ? '商家已同意'
            : ($auditStatus === AuditStatusEnum::REJECTED ? '商家已驳回' : '待商家审核');
        $reason = $this->requestReason($payload);
        $parts = [
            '服务状态=' . $stage,
            '商家审核=' . $auditText,
            '建议=' . ($resultCode === 0 ? '退款' : '拒绝退款'),
        ];
        if ($reason !== '') {
            $parts[] = '用户原因=' . mb_substr($reason, 0, 200);
        }
        return implode('；', $parts);
    }

    private function recordUnboundInquiry(
        string $payOrderId,
        array $payload,
        string $fingerprint,
        string $bindingStatus,
        int $receivedAt,
        float $startedAt
    ): array {
        return $this->insertInquiryAndRespond([], $payOrderId, $payload, $fingerprint, $bindingStatus, self::STAGE_UNKNOWN, null, 1, $receivedAt, $startedAt);
    }

    private function insertInquiryAndRespond(
        array $binding,
        string $payOrderId,
        array $payload,
        string $fingerprint,
        string $bindingStatus,
        string $stage,
        ?int $auditStatus,
        int $resultCode,
        int $receivedAt,
        float $startedAt
    ): array {
        $resultInfo = '建议拒绝退款';
        $evidence = '交易或订单绑定校验未通过(' . $bindingStatus . ')，无法确认退款申请属于当前有效服务订单';
        $this->insertInquiry(array_merge([
            'order_id' => 0,
            'order_refund_id' => 0,
            'trade_id' => 0,
            'store_id' => 0,
            'user_id' => 0,
            'pay_order_id' => $payOrderId,
            'fingerprint' => $fingerprint,
            'binding_status' => $bindingStatus,
            'request_reason' => $this->requestReason($payload),
            'request_payload' => helper::jsonEncode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'service_stage' => $stage,
            'order_status' => 0,
            'delivery_status' => 0,
            'receipt_status' => 0,
            'audit_status' => $auditStatus,
            'result_code' => $resultCode,
            'result_info' => $resultInfo,
            'evidence' => $evidence,
            'response_ms' => $this->elapsedMs($startedAt),
            'received_at' => $receivedAt,
        ], $binding));
        return [
            'result_code' => $resultCode,
            'result_info' => $resultInfo,
            'evidence' => $evidence,
        ];
    }

    private function insertInquiry(array $data): void
    {
        $inquiry = new InquiryModel;
        if ($inquiry->save($data) === false) {
            throwError('记录Apple退款问询失败');
        }
    }

    private function requestReason(array $payload): string
    {
        foreach (['refund_request_reason', 'RefundRequestReason', 'reason', 'Reason'] as $key) {
            if (isset($payload[$key]) && trim((string)$payload[$key]) !== '') {
                return trim((string)$payload[$key]);
            }
        }
        return '';
    }

    private function fingerprint(array $payload): string
    {
        $normalized = $this->sortRecursive($payload);
        return hash('sha256', helper::jsonEncode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function sortRecursive(array $value): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->sortRecursive($item);
            }
        }
        if (!empty($value) && array_keys($value) !== range(0, count($value) - 1)) {
            ksort($value);
        }
        return $value;
    }

    private function elapsedMs(float $startedAt): int
    {
        return max(0, (int)round((microtime(true) - $startedAt) * 1000));
    }
}
