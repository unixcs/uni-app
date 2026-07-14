<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use app\api\model\OrderRefund as ApiOrderRefund;
use app\api\model\User as ApiUser;
use app\api\service\User as ApiUserService;
use app\common\enum\order\iosRefund\RiskStatus;
use app\common\enum\order\refund\AuditStatus;
use app\common\enum\order\refund\RefundStatus;
use app\common\enum\order\refund\RefundType;
use app\common\enum\payment\trade\ChannelClass;
use app\common\model\Order;
use app\common\model\OrderGoods;
use app\common\model\OrderRefund;
use app\common\model\PaymentIosRefundInquiry;
use app\common\model\PaymentTrade;
use app\common\service\order\IosRefundRisk;
use app\store\model\Order as StoreOrder;
use app\store\model\OrderRefund as StoreOrderRefund;
use cores\BaseModel;
use think\App;
use think\facade\Db;

(new App())->initialize();

function expectNonIos($expected, $actual, string $case): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            '%s expected=%s actual=%s',
            $case,
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
    fwrite(STDOUT, "ok {$case}\n");
}

$trade = null;
$candidates = (new PaymentTrade)
    ->where('platform', '=', 'wechat_virtual')
    ->where('channel_class', '=', ChannelClass::NON_IOS)
    ->where('trade_state', 'in', [20, 30])
    ->order(['trade_id' => 'desc'])
    ->limit(100)
    ->select();
foreach ($candidates as $candidate) {
    $candidateOrder = Order::detail((int)$candidate['order_id']);
    if (!empty($candidateOrder)
        && (int)$candidateOrder['trade_id'] === (int)$candidate['trade_id']
        && Order::isServiceOrderData($candidateOrder)) {
        $trade = $candidate;
        break;
    }
}
if (empty($trade)) {
    throw new RuntimeException('no non-iOS service-order fixture available');
}

$orderId = (int)$trade['order_id'];
$tradeId = (int)$trade['trade_id'];
$order = Order::detail($orderId);
$goods = (new OrderGoods)
    ->where('order_id', '=', $orderId)
    ->order(['order_goods_id' => 'asc'])
    ->find();
if (empty($order) || empty($goods)) {
    throw new RuntimeException('non-iOS order/goods fixture unavailable');
}

$storeId = (int)$order['store_id'];
$userId = (int)$order['user_id'];
$goodsId = (int)$goods['order_goods_id'];
BaseModel::$storeId = $storeId;
$loginUser = ApiUser::detail($userId);
if (empty($loginUser)) {
    throw new RuntimeException('non-iOS API user fixture unavailable');
}
$currentUser = new ReflectionProperty(ApiUserService::class, 'currentLoginUser');
$currentUser->setAccessible(true);
$currentUser->setValue(null, $loginUser);

Db::startTrans();
try {
    Db::name('order_refund')
        ->where('order_id', '=', $orderId)
        ->where('type', '=', RefundType::SERVICE)
        ->delete();
    Db::name('payment_ios_refund_inquiry')->where('order_id', '=', $orderId)->delete();
    Db::name('order')->where('order_id', '=', $orderId)->update([
        'order_status' => 10,
        'pay_status' => 20,
        'delivery_status' => 20,
        'receipt_status' => 10,
        'ios_refund_risk_status' => RiskStatus::NONE,
        'ios_refund_risk_source' => '',
        'ios_refund_risk_time' => 0,
    ]);
    Db::name('payment_trade')->where('trade_id', '=', $tradeId)->update([
        'platform' => 'wechat_virtual',
        'channel_class' => ChannelClass::NON_IOS,
        'trade_state' => 20,
        'payload_snapshot' => json_encode([
            'query_order' => ['result' => ['order' => ['order_type' => 6]]],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);

    $trade = PaymentTrade::detail($tradeId);
    expectNonIos(false, PaymentTrade::isIosAppleVirtualTrade($trade), 'fixture is explicitly non-iOS');

    // Execute both normal service-operation success paths with no active refund.
    // Use a non-virtual platform for this fixture so completeService cannot dispatch a real provider call.
    Db::name('payment_trade')->where('trade_id', '=', $tradeId)->update([
        'platform' => 'wechat',
        'channel_class' => ChannelClass::NON_IOS,
    ]);
    Db::name('order')->where('order_id', '=', $orderId)->update([
        'order_status' => 10,
        'pay_status' => 20,
        'delivery_status' => 10,
        'receipt_status' => 10,
        'ios_refund_risk_status' => RiskStatus::NONE,
    ]);
    $serviceOrder = StoreOrder::detail($orderId);
    expectNonIos(true, (bool)$serviceOrder->startService(), 'risk NONE startService success path');
    expectNonIos(20, (int)Order::detail($orderId)['delivery_status'], 'startService writes delivery history');

    Db::name('order')->where('order_id', '=', $orderId)->update([
        'order_status' => 10,
        'delivery_status' => 20,
        'receipt_status' => 10,
    ]);
    $serviceOrder = StoreOrder::detail($orderId);
    expectNonIos(true, (bool)$serviceOrder->completeService(), 'risk NONE completeService success path');
    expectNonIos(30, (int)Order::detail($orderId)['order_status'], 'completeService writes order completion');
    expectNonIos(20, (int)Order::detail($orderId)['receipt_status'], 'completeService writes receipt history');

    // Return to an in-progress, non-Apple virtual fixture for the legacy refund-flow assertions.
    Db::name('payment_trade')->where('trade_id', '=', $tradeId)->update([
        'platform' => 'wechat_virtual',
        'channel_class' => ChannelClass::NON_IOS,
    ]);
    Db::name('order')->where('order_id', '=', $orderId)->update([
        'order_status' => 10,
        'pay_status' => 20,
        'delivery_status' => 20,
        'receipt_status' => 10,
        'ios_refund_risk_status' => RiskStatus::NONE,
    ]);

    $nonIosInquiry = (new IosRefundRisk)->handleInquiry((string)$trade['out_trade_no'], [
        'Event' => 'xpay_subscribe_ios_refund_query_notify',
        'pay_order_id' => (string)$trade['out_trade_no'],
        'refund_request_reason' => 'NON_IOS_INQUIRY_MUST_NOT_LOCK',
    ]);
    expectNonIos(1, (int)$nonIosInquiry['result_code'], 'non-iOS inquiry fails closed');
    expectNonIos(RiskStatus::NONE, (int)Order::detail($orderId)['ios_refund_risk_status'], 'non-iOS inquiry does not establish Apple risk');
    expectNonIos(0, (new OrderRefund)
        ->where('order_id', '=', $orderId)
        ->where('type', '=', RefundType::SERVICE)
        ->count(), 'non-iOS inquiry creates no service refund');
    expectNonIos(1, (new PaymentIosRefundInquiry)
        ->where('order_id', '=', 0)
        ->where('request_reason', '=', 'NON_IOS_INQUIRY_MUST_NOT_LOCK')
        ->count(), 'non-iOS inquiry is retained only as zero-order audit');

    $firstApply = (new ApiOrderRefund)->apply($goodsId, [
        'content' => 'NON_IOS_SERVICE_REFUND_REGRESSION_FIRST',
    ]);
    expectNonIos(true, (bool)$firstApply, 'non-iOS in-progress service refund apply succeeds');

    $firstRefund = (new OrderRefund)
        ->where('order_id', '=', $orderId)
        ->where('type', '=', RefundType::SERVICE)
        ->order(['order_refund_id' => 'desc'])
        ->find();
    expectNonIos(true, !empty($firstRefund), 'non-iOS apply creates service refund');
    expectNonIos(AuditStatus::WAIT, (int)$firstRefund['audit_status'], 'non-iOS in-progress apply waits for merchant');
    expectNonIos(RefundStatus::NORMAL, (int)$firstRefund['status'], 'non-iOS refund starts active');
    expectNonIos(RiskStatus::NONE, (int)Order::detail($orderId)['ios_refund_risk_status'], 'non-iOS apply does not establish Apple risk');

    $projection = IosRefundRisk::buildProjection(Order::detail($orderId), PaymentTrade::detail($tradeId), $firstRefund);
    expectNonIos(false, (bool)$projection['ios_apple_refund_required'], 'non-iOS projection is not App Store guided');
    expectNonIos('developer_refund', (string)$projection['refund_entry_mode'], 'non-iOS keeps developer refund mode');
    expectNonIos(true, (bool)$projection['can_cancel'], 'non-iOS active refund keeps cancellation capability');

    $storeRefund = StoreOrderRefund::detail((int)$firstRefund['order_refund_id']);
    $rejected = $storeRefund->audit([
        'audit_status' => AuditStatus::REJECTED,
        'refuse_desc' => 'non-iOS regression rejection',
    ]);
    expectNonIos(true, (bool)$rejected, 'non-iOS merchant rejection uses legacy branch');
    $firstRefund = OrderRefund::detail((int)$firstRefund['order_refund_id']);
    expectNonIos(AuditStatus::REJECTED, (int)$firstRefund['audit_status'], 'non-iOS rejection stores audit result');
    expectNonIos(RefundStatus::REJECTED, (int)$firstRefund['status'], 'non-iOS rejection closes first refund');
    expectNonIos(RiskStatus::NONE, (int)Order::detail($orderId)['ios_refund_risk_status'], 'non-iOS rejection does not create Apple risk');

    $secondApply = (new ApiOrderRefund)->apply($goodsId, [
        'content' => 'NON_IOS_SERVICE_REFUND_REGRESSION_SECOND',
    ]);
    expectNonIos(true, (bool)$secondApply, 'non-iOS can reapply after terminal rejection');
    $refunds = (new OrderRefund)
        ->where('order_id', '=', $orderId)
        ->where('type', '=', RefundType::SERVICE)
        ->order(['order_refund_id' => 'asc'])
        ->select();
    expectNonIos(2, $refunds->count(), 'non-iOS legacy reapply creates a new refund row');
    expectNonIos(AuditStatus::WAIT, (int)$refunds[1]['audit_status'], 'non-iOS reapply returns to merchant review');
    expectNonIos(0, (new PaymentIosRefundInquiry)->where('order_id', '=', $orderId)->count(), 'non-iOS flow creates no Apple inquiry history');
    expectNonIos(RiskStatus::NONE, (int)Order::detail($orderId)['ios_refund_risk_status'], 'non-iOS flow remains outside Apple risk state machine');

    fwrite(STDOUT, "PASS non-iOS service refund regression contracts\n");
} finally {
    Db::rollback();
}
