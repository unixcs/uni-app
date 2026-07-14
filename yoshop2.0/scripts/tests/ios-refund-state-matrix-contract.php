<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use app\api\model\OrderRefund as ApiOrderRefund;
use app\api\model\User as ApiUser;
use app\api\service\User as ApiUserService;
use app\common\enum\order\iosRefund\RiskSource;
use app\common\enum\order\iosRefund\RiskStatus;
use app\common\enum\order\refund\AuditStatus;
use app\common\enum\order\refund\RefundStatus;
use app\common\enum\order\refund\RefundType;
use app\common\model\Order;
use app\common\model\OrderGoods;
use app\common\model\OrderRefund;
use app\common\model\PaymentIosRefundInquiry;
use app\common\model\PaymentTrade;
use app\common\service\order\IosRefundRisk;
use app\common\service\order\Refund as RefundService;
use app\store\model\Order as StoreOrder;
use cores\BaseModel;
use think\App;
use think\facade\Db;

(new App())->initialize();

if (getenv('IOS_REFUND_MATRIX_TEST') !== '1') {
    throw new RuntimeException('refusing to mutate a fixture without IOS_REFUND_MATRIX_TEST=1');
}

$assertions = 0;
function expectMatrix($expected, $actual, string $case): void
{
    global $assertions;
    $assertions++;
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            '%s expected=%s actual=%s',
            $case,
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}

$trade = (new PaymentTrade)
    ->where('platform', '=', 'wechat_virtual')
    ->where('channel_class', '=', 20)
    ->where('trade_state', 'in', [20, 30])
    ->order(['trade_id' => 'desc'])
    ->find();
if (empty($trade)) {
    throw new RuntimeException('no iOS Apple fixture trade available');
}
$order = Order::detail((int)$trade['order_id']);
$goods = (new OrderGoods)
    ->where('order_id', '=', (int)$trade['order_id'])
    ->order(['order_goods_id' => 'asc'])
    ->find();
if (empty($order)
    || empty($goods)
    || (int)$order['trade_id'] !== (int)$trade['trade_id']
    || !Order::isServiceOrderData($order)) {
    throw new RuntimeException('bound iOS service-order fixture unavailable');
}

$orderId = (int)$order['order_id'];
$tradeId = (int)$trade['trade_id'];
$goodsId = (int)$goods['order_goods_id'];
$storeId = (int)$order['store_id'];
$userId = (int)$order['user_id'];
$payOrderId = (string)$trade['out_trade_no'];
$onlyCase = trim((string)getenv('IOS_REFUND_MATRIX_CASE'));
$markerPrefix = 'IOS_REFUND_MATRIX_' . getmypid() . '_';

$baseSnapshot = PaymentTrade::decodePayloadSnapshot((string)($trade['payload_snapshot'] ?? ''));
unset($baseSnapshot['ios_refund_query_notify'], $baseSnapshot['provide_goods']);
if (isset($baseSnapshot['virtual_refund']) && is_array($baseSnapshot['virtual_refund'])) {
    unset($baseSnapshot['virtual_refund']['duplicate_payment']);
}
$baseSnapshotJson = json_encode($baseSnapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($baseSnapshotJson === false) {
    throw new RuntimeException('failed to encode fixture trade snapshot');
}

BaseModel::$storeId = $storeId;
$loginUser = ApiUser::detail($userId);
if (empty($loginUser)) {
    throw new RuntimeException('API user fixture unavailable');
}
$currentUser = new ReflectionProperty(ApiUserService::class, 'currentLoginUser');
$currentUser->setAccessible(true);
$currentUser->setValue(null, $loginUser);

$stageRows = [
    IosRefundRisk::STAGE_NOT_STARTED => [
        'order_status' => 10,
        'pay_status' => 20,
        'delivery_status' => 10,
        'receipt_status' => 10,
    ],
    IosRefundRisk::STAGE_IN_PROGRESS => [
        'order_status' => 10,
        'pay_status' => 20,
        'delivery_status' => 20,
        'receipt_status' => 10,
    ],
    IosRefundRisk::STAGE_COMPLETED => [
        'order_status' => 30,
        'pay_status' => 20,
        'delivery_status' => 20,
        'receipt_status' => 20,
    ],
    IosRefundRisk::STAGE_UNKNOWN => [
        'order_status' => 20,
        'pay_status' => 20,
        'delivery_status' => 10,
        'receipt_status' => 10,
    ],
];
$auditRows = [
    'NONE' => null,
    'WAIT' => AuditStatus::WAIT,
    'REVIEWED' => AuditStatus::REVIEWED,
    'REJECTED' => AuditStatus::REJECTED,
];
$riskRows = [
    'NONE' => RiskStatus::NONE,
    'LOCKED' => RiskStatus::LOCKED,
    'REFUNDED' => RiskStatus::REFUNDED,
];

/**
 * Reset one synthetic state vector inside the enclosing rollback-only transaction.
 * The axes are deliberately configured independently so monotonic/fail-closed behavior
 * is exercised even for historical or out-of-order states.
 */
$configureCase = static function (string $stage, ?int $auditStatus, int $riskStatus) use (
    $stageRows,
    $orderId,
    $tradeId,
    $goodsId,
    $storeId,
    $userId,
    $baseSnapshotJson
): int {
    Db::name('order_refund')
        ->where('order_id', '=', $orderId)
        ->where('type', '=', RefundType::SERVICE)
        ->delete();

    $source = '';
    if ($riskStatus === RiskStatus::LOCKED) {
        $source = RiskSource::APPLE_INQUIRY;
    } elseif ($riskStatus === RiskStatus::REFUNDED) {
        $source = RiskSource::REFUND_NOTIFY_RECOVERY;
    }
    Db::name('order')->where('order_id', '=', $orderId)->update(array_merge(
        $stageRows[$stage],
        [
            'ios_refund_risk_status' => $riskStatus,
            'ios_refund_risk_source' => $source,
            'ios_refund_risk_time' => $riskStatus === RiskStatus::NONE ? 0 : time(),
        ]
    ));
    Db::name('payment_trade')->where('trade_id', '=', $tradeId)->update([
        'platform' => 'wechat_virtual',
        'channel_class' => 20,
        'trade_state' => 20,
        'payload_snapshot' => $baseSnapshotJson,
    ]);

    if ($auditStatus === null) {
        return 0;
    }
    $refund = new OrderRefund;
    $refundStatus = $riskStatus === RiskStatus::REFUNDED
        ? RefundStatus::COMPLETED
        : ($auditStatus === AuditStatus::REJECTED ? RefundStatus::REJECTED : RefundStatus::NORMAL);
    if ($refund->save([
        'order_goods_id' => $goodsId,
        'order_id' => $orderId,
        'user_id' => $userId,
        'type' => RefundType::SERVICE,
        'apply_desc' => 'Mock Apple matrix fixture',
        'audit_status' => $auditStatus,
        'status' => $refundStatus,
        'refund_money' => '0.00',
        'store_id' => $storeId,
    ]) === false) {
        throw new RuntimeException('failed to create matrix refund fixture');
    }
    return (int)$refund['order_refund_id'];
};

$expectedDecision = static function (string $stage, ?int $auditStatus): int {
    if ($stage === IosRefundRisk::STAGE_NOT_STARTED) {
        return $auditStatus === null || $auditStatus === AuditStatus::REVIEWED ? 0 : 1;
    }
    if ($stage === IosRefundRisk::STAGE_IN_PROGRESS) {
        return $auditStatus === AuditStatus::REVIEWED ? 0 : 1;
    }
    return 1;
};

$expectedAuditAfterInquiry = static function (string $stage, ?int $auditStatus): ?int {
    if ($auditStatus !== null) {
        return $auditStatus;
    }
    if ($stage === IosRefundRisk::STAGE_NOT_STARTED) {
        return AuditStatus::REVIEWED;
    }
    if ($stage === IosRefundRisk::STAGE_IN_PROGRESS) {
        return AuditStatus::WAIT;
    }
    return null;
};

Db::startTrans();
$executed = 0;
try {
    foreach ($stageRows as $stage => $_stageData) {
        foreach ($auditRows as $auditName => $auditStatus) {
            foreach ($riskRows as $riskName => $riskStatus) {
                $caseId = $stage . '__' . $auditName . '__' . $riskName;
                if ($onlyCase !== '' && $onlyCase !== $caseId) {
                    continue;
                }
                $executed++;
                $label = '[' . $caseId . '] ';
                $reason = $markerPrefix . $caseId;

                // 1) Real local-apply API: only NONE risk + no prior refund + refundable service stage may create a row.
                $configureCase($stage, $auditStatus, $riskStatus);
                $applyExpected = $riskStatus === RiskStatus::NONE
                    && $auditStatus === null
                    && in_array($stage, [IosRefundRisk::STAGE_NOT_STARTED, IosRefundRisk::STAGE_IN_PROGRESS], true);
                $applySucceeded = false;
                try {
                    $applySucceeded = (bool)(new ApiOrderRefund)->apply($goodsId, [
                        'content' => $reason . '_LOCAL_APPLY',
                    ]);
                } catch (Throwable $e) {
                    $applySucceeded = false;
                }
                expectMatrix($applyExpected, $applySucceeded, $label . 'local apply policy');
                $applyRefundCount = (new OrderRefund)
                    ->where('order_id', '=', $orderId)
                    ->where('type', '=', RefundType::SERVICE)
                    ->count();
                expectMatrix($auditStatus === null ? ($applyExpected ? 1 : 0) : 1, $applyRefundCount, $label . 'local apply row count');
                if ($applyExpected) {
                    expectMatrix(RiskStatus::LOCKED, (int)Order::detail($orderId)['ios_refund_risk_status'], $label . 'local apply locks atomically');
                }

                // 2) Pre-inquiry service action projection uses stage + active refund + risk independently.
                $configureCase($stage, $auditStatus, $riskStatus);
                $storeOrder = StoreOrder::detail($orderId);
                $flags = $storeOrder->getBackendActionFlagsAttr(null, $storeOrder->getData());
                $terminalOrAbsentRefund = $auditStatus === null || $auditStatus === AuditStatus::REJECTED;
                $startExpected = $stage === IosRefundRisk::STAGE_NOT_STARTED
                    && $riskStatus === RiskStatus::NONE
                    && $terminalOrAbsentRefund;
                $completeExpected = $stage === IosRefundRisk::STAGE_IN_PROGRESS
                    && $riskStatus === RiskStatus::NONE
                    && $terminalOrAbsentRefund;
                expectMatrix($startExpected, (bool)$flags['can_start_service'], $label . 'start-service policy');
                expectMatrix($completeExpected, (bool)$flags['can_complete_service'], $label . 'complete-service policy');
                expectMatrix(false, (bool)$flags['can_cancel_refund'], $label . 'iOS cancellation disabled');

                // 3) Atomic provide_goods dispatch claim is available only before risk is established.
                $dispatch = IosRefundRisk::claimProvideGoodsDispatchIfAllowed($orderId, $tradeId, 'matrix_contract');
                expectMatrix($riskStatus === RiskStatus::NONE, (bool)$dispatch['claimed'], $label . 'provide-goods claim policy');

                // 4) Two identical authenticated Mock Apple inquiries must append two attempts,
                // recompute from current state, preserve one refund row, and advance risk monotonically.
                $configureCase($stage, $auditStatus, $riskStatus);
                $payload = [
                    'Event' => 'xpay_subscribe_ios_refund_query_notify',
                    'pay_order_id' => $payOrderId,
                    'refund_request_reason' => $reason,
                    'mock' => true,
                ];
                $service = new IosRefundRisk;
                $expectedResult = $expectedDecision($stage, $auditStatus);
                $first = $service->handleInquiry($payOrderId, $payload);
                $second = $service->handleInquiry($payOrderId, $payload);
                expectMatrix($expectedResult, (int)$first['result_code'], $label . 'first inquiry decision');
                expectMatrix($expectedResult, (int)$second['result_code'], $label . 'repeated inquiry recomputes');
                expectMatrix(true, trim((string)$first['evidence']) !== '', $label . 'inquiry evidence');

                $inquiries = (new PaymentIosRefundInquiry)
                    ->where('order_id', '=', $orderId)
                    ->where('request_reason', '=', $reason)
                    ->order(['inquiry_id' => 'asc'])
                    ->select();
                expectMatrix(2, $inquiries->count(), $label . 'append-only inquiry attempts');
                expectMatrix($stage, (string)$inquiries[0]['service_stage'], $label . 'service snapshot');
                $expectedAudit = $expectedAuditAfterInquiry($stage, $auditStatus);
                $actualAudit = $inquiries[0]['audit_status'] === null ? null : (int)$inquiries[0]['audit_status'];
                expectMatrix($expectedAudit, $actualAudit, $label . 'audit snapshot');

                $expectedRisk = $riskStatus === RiskStatus::REFUNDED ? RiskStatus::REFUNDED : RiskStatus::LOCKED;
                $afterInquiryOrder = Order::detail($orderId);
                expectMatrix($expectedRisk, (int)$afterInquiryOrder['ios_refund_risk_status'], $label . 'monotonic inquiry risk');
                expectMatrix($stage, IosRefundRisk::serviceStage($afterInquiryOrder), $label . 'inquiry preserves service history');

                $refundsAfterInquiry = (new OrderRefund)
                    ->where('order_id', '=', $orderId)
                    ->where('type', '=', RefundType::SERVICE)
                    ->order(['order_refund_id' => 'asc'])
                    ->select();
                $expectedRefundCount = ($auditStatus !== null
                    || in_array($stage, [IosRefundRisk::STAGE_NOT_STARTED, IosRefundRisk::STAGE_IN_PROGRESS], true)) ? 1 : 0;
                expectMatrix($expectedRefundCount, $refundsAfterInquiry->count(), $label . 'inquiry refund idempotency');

                // A real backend service request after inquiry must fail even if the original stage was actionable.
                $postInquiryStoreOrder = StoreOrder::detail($orderId);
                expectMatrix(false, $postInquiryStoreOrder->startService(), $label . 'post-inquiry start rejected');
                $postInquiryStoreOrder = StoreOrder::detail($orderId);
                expectMatrix(false, $postInquiryStoreOrder->completeService(), $label . 'post-inquiry completion rejected');

                // Re-applying after any authenticated inquiry is forbidden and cannot add a second refund row.
                $beforeRepeatApplyCount = $refundsAfterInquiry->count();
                $repeatApplySucceeded = false;
                try {
                    $repeatApplySucceeded = (bool)(new ApiOrderRefund)->apply($goodsId, [
                        'content' => $reason . '_REPEAT_APPLY',
                    ]);
                } catch (Throwable $e) {
                    $repeatApplySucceeded = false;
                }
                expectMatrix(false, $repeatApplySucceeded, $label . 'post-inquiry repeat apply rejected');
                expectMatrix($beforeRepeatApplyCount, (new OrderRefund)
                    ->where('order_id', '=', $orderId)
                    ->where('type', '=', RefundType::SERVICE)
                    ->count(), $label . 'repeat apply creates no row');

                // 5) Trusted Mock Apple success notification is the terminal money fact.
                // It must converge every prior vector and remain idempotent on duplicate delivery.
                $notify = [
                    'Event' => 'xpay_refund_notify',
                    'RetCode' => '0',
                    'MchOrderId' => $payOrderId,
                    'mock' => true,
                ];
                $refundService = new RefundService;
                $firstNotify = $refundService->finalizeVirtualRefundByTrade($tradeId, $notify);
                expectMatrix('completed', (string)$firstNotify['status'], $label . 'refund notify completion');
                $afterNotifyOrder = Order::detail($orderId);
                $afterNotifyTrade = PaymentTrade::detail($tradeId);
                $afterNotifyRefunds = (new OrderRefund)
                    ->where('order_id', '=', $orderId)
                    ->where('type', '=', RefundType::SERVICE)
                    ->order(['order_refund_id' => 'asc'])
                    ->select();
                expectMatrix(20, (int)$afterNotifyOrder['order_status'], $label . 'refund notify closes order');
                expectMatrix(RiskStatus::REFUNDED, (int)$afterNotifyOrder['ios_refund_risk_status'], $label . 'refund notify terminal risk');
                expectMatrix(30, (int)$afterNotifyTrade['trade_state'], $label . 'refund notify terminal trade');
                expectMatrix(1, $afterNotifyRefunds->count(), $label . 'refund notify has one tracking row');
                expectMatrix(RefundStatus::COMPLETED, (int)$afterNotifyRefunds[0]['status'], $label . 'refund notify completes tracking row');

                $secondNotify = $refundService->finalizeVirtualRefundByTrade($tradeId, $notify);
                expectMatrix('completed', (string)$secondNotify['status'], $label . 'duplicate notify idempotent');
                expectMatrix(1, (new OrderRefund)
                    ->where('order_id', '=', $orderId)
                    ->where('type', '=', RefundType::SERVICE)
                    ->count(), $label . 'duplicate notify creates no row');
                expectMatrix(RiskStatus::REFUNDED, (int)Order::detail($orderId)['ios_refund_risk_status'], $label . 'terminal risk cannot regress');

                fwrite(STDOUT, "PASS {$caseId}\n");
            }
        }
    }

    if ($onlyCase !== '' && $executed === 0) {
        throw new RuntimeException('unknown IOS_REFUND_MATRIX_CASE: ' . $onlyCase);
    }
    expectMatrix($onlyCase === '' ? 48 : 1, $executed, 'matrix executed case count');
    fwrite(STDOUT, sprintf("PASS ios-refund-state-matrix cases=%d assertions=%d\n", $executed, $assertions));
} finally {
    Db::rollback();
}
