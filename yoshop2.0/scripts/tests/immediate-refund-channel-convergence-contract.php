<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use app\common\enum\payment\trade\ChannelClass;
use app\common\model\PaymentTrade;

$root = dirname(__DIR__, 2);
$paymentSource = file_get_contents($root . '/app/api/service/cashier/Payment.php');
$refundSource = file_get_contents($root . '/app/common/service/order/Refund.php');
$orderRefundSource = file_get_contents($root . '/app/api/model/OrderRefund.php');

if ($paymentSource === false || $refundSource === false || $orderRefundSource === false) {
    throw new RuntimeException('unable to read immediate-refund convergence sources');
}

$expect = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    fwrite(STDOUT, "ok {$message}\n");
};

$paymentQueryStart = strpos($paymentSource, 'private function virtualTradeQuery');
$paymentQueryEnd = strpos($paymentSource, 'private function buildVirtualTradeQueryPendingMessage', $paymentQueryStart ?: 0);
$paymentQuery = $paymentQueryStart !== false && $paymentQueryEnd !== false
    ? substr($paymentSource, $paymentQueryStart, $paymentQueryEnd - $paymentQueryStart)
    : '';
$expect($paymentQuery !== '', 'cashier virtual trade-query method is discoverable');
$expect(str_contains($paymentQuery, 'ChannelClassEnum::UNKNOWN'), 'paid cashier query distinguishes unresolved channel fact');
$expect(
    str_contains($paymentQuery, '$isOrderPaid && $channelClass !== ChannelClassEnum::UNKNOWN'),
    'cashier fast path requires both local payment and channel convergence'
);
$expect(
    strpos($paymentQuery, '$channelClass !== ChannelClassEnum::UNKNOWN') < strpos($paymentQuery, '$payment->queryOrder($payload)'),
    'unknown paid trade reaches WeChat query before returning'
);
$expect(str_contains($paymentQuery, "'query_order' => ["), 'cashier persists query evidence for channel classification');

$convergeStart = strpos($refundSource, 'public function convergeVirtualPaymentChannelForRefund');
$convergeEnd = strpos($refundSource, 'public function handle(', $convergeStart ?: 0);
$convergeMethod = $convergeStart !== false && $convergeEnd !== false
    ? substr($refundSource, $convergeStart, $convergeEnd - $convergeStart)
    : '';
$expect($convergeMethod !== '', 'refund channel preflight is a public domain operation');
$expect(str_contains($convergeMethod, 'ChannelClassEnum::UNKNOWN'), 'known channel bypass and unknown channel guard are explicit');
$expect(str_contains($convergeMethod, 'queryVirtualRefundState'), 'unknown refund channel actively queries WeChat');
$expect(str_contains($convergeMethod, 'TradeStatusEnum::SUCCESS'), 'refund preflight requires a paid-like trade');
$expect(str_contains($convergeMethod, "\$order['trade_id']"), 'refund preflight is bound to the order final trade');
$expect(str_contains($convergeMethod, 'refund_channel_preflight_resolved'), 'resolved refund channel is observable');
$expect(str_contains($convergeMethod, 'refund_channel_preflight_unresolved'), 'missing channel evidence remains fail-closed');
$expect(str_contains($convergeMethod, 'refund_channel_preflight_query_failed'), 'query failures remain observable and fail-closed');

$expect(str_contains($refundSource, "'query_result' => \$orderInfo"), 'refund query stores the order object at the classifier contract path');
$expect(str_contains($refundSource, "'query_response' => \$result"), 'refund query retains the complete upstream response for audit');

$getGoodsStart = strpos($orderRefundSource, 'public function getRefundGoods');
$applyStart = strpos($orderRefundSource, 'public function apply', $getGoodsStart ?: 0);
$getGoodsMethod = $getGoodsStart !== false && $applyStart !== false
    ? substr($orderRefundSource, $getGoodsStart, $applyStart - $getGoodsStart)
    : '';
$applyEnd = strpos($orderRefundSource, 'private function hasActiveRefundByOrderId', $applyStart ?: 0);
$applyMethod = $applyStart !== false && $applyEnd !== false
    ? substr($orderRefundSource, $applyStart, $applyEnd - $applyStart)
    : '';
$expect(str_contains($getGoodsMethod, 'convergeVirtualPaymentChannelForRefund'), 'refund entry preflights channel before apply transaction');
$expect(str_contains($getGoodsMethod, "\$refundTrade['trade_id'] !== (int)\$order['trade_id']"), 'stale virtual attempts cannot drive final refund routing');
$expect(!str_contains($applyMethod, 'convergeVirtualPaymentChannelForRefund'), 'refund transaction contains no channel-convergence network call');
$expect(
    strpos($orderRefundSource, 'convergeVirtualPaymentChannelForRefund') < strpos($orderRefundSource, 'return $this->transaction', $applyStart ?: 0),
    'channel preflight occurs before order/refund/trade row locks'
);

$nonIosSnapshot = [
    'virtual_refund' => [
        'query_result' => ['status' => 4, 'order_type' => 0],
        'query_response' => ['errcode' => 0, 'order' => ['status' => 4, 'order_type' => 0]],
    ],
];
$iosSnapshot = [
    'virtual_refund' => [
        'query_result' => ['status' => 3, 'order_type' => 7],
        'query_response' => ['errcode' => 0, 'order' => ['status' => 3, 'order_type' => 7]],
    ],
];
$unknownSnapshot = [
    'virtual_refund' => [
        'query_result' => ['status' => 3],
        'query_response' => ['errcode' => 0, 'order' => ['status' => 3]],
    ],
];
$expect(
    PaymentTrade::classifyChannelClass('wechat_virtual', $nonIosSnapshot, ChannelClass::UNKNOWN) === ChannelClass::NON_IOS,
    'refund query order_type=0 classifies non-iOS in the same request'
);
$expect(
    PaymentTrade::classifyChannelClass('wechat_virtual', $iosSnapshot, ChannelClass::UNKNOWN) === ChannelClass::IOS_APPLE,
    'refund query order_type=7 classifies Apple in the same request'
);
$expect(
    PaymentTrade::classifyChannelClass('wechat_virtual', $unknownSnapshot, ChannelClass::UNKNOWN) === ChannelClass::UNKNOWN,
    'refund query without order_type remains unknown'
);

fwrite(STDOUT, "PASS immediate refund channel convergence contract\n");
