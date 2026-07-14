<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use app\common\enum\order\refund\RefundStatus;
use app\common\enum\payment\trade\ChannelClass;
use app\common\model\PaymentTrade;

function assertSameValue($expected, $actual, string $case): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, sprintf("FAIL %s: expected %s, got %s\n", $case, var_export($expected, true), var_export($actual, true)));
        exit(1);
    }
    fwrite(STDOUT, "ok {$case}\n");
}

$iosEmptySnapshot = ['platform' => 'wechat_virtual', 'channel_class' => ChannelClass::IOS_APPLE, 'payload_snapshot' => '{}'];
assertSameValue(true, PaymentTrade::isIosAppleVirtualTrade($iosEmptySnapshot), 'persisted IOS_APPLE is positive refund evidence');
assertSameValue(ChannelClass::IOS_APPLE, PaymentTrade::classifyChannelClass('wechat_virtual', [], ChannelClass::IOS_APPLE), 'IOS_APPLE never downgrades');
assertSameValue(ChannelClass::IOS_APPLE, PaymentTrade::classifyChannelClass('wechat_virtual', ['query_order' => ['result' => ['order' => ['order_type' => 7]]]], ChannelClass::UNKNOWN), 'order_type 7 classifies iOS');
assertSameValue(ChannelClass::NON_IOS, PaymentTrade::classifyChannelClass('wechat_virtual', ['query_order' => ['result' => ['order' => ['order_type' => 6]]]], ChannelClass::UNKNOWN), 'explicit non-7 classifies non-iOS');
assertSameValue(ChannelClass::NON_IOS, PaymentTrade::classifyChannelClass('wechat_virtual', ['query_order' => ['result' => ['order' => ['order_type' => 0]]]], ChannelClass::UNKNOWN), 'order_type 0 is valid non-iOS evidence');
assertSameValue(ChannelClass::UNKNOWN, PaymentTrade::classifyChannelClass('wechat_virtual', [], ChannelClass::UNKNOWN), 'missing evidence remains UNKNOWN');

$inquiryTrade = $iosEmptySnapshot;
$inquiryTrade['payload_snapshot'] = json_encode(['ios_refund_query_notify' => ['received_at' => 1]], JSON_UNESCAPED_UNICODE);
foreach ([RefundStatus::REJECTED, RefundStatus::CANCELLED, RefundStatus::NORMAL] as $status) {
    $projection = PaymentTrade::buildVirtualRefundProjection($inquiryTrade, ['order_refund_id' => 1, 'status' => $status]);
    assertSameValue('等待 App Store 退款处理', $projection['refund_display_state_text'], "inquiry overrides local status {$status}");
}
$cancelledWithoutInquiry = PaymentTrade::buildVirtualRefundProjection($iosEmptySnapshot, ['order_refund_id' => 1, 'status' => RefundStatus::CANCELLED]);
assertSameValue('已取消', $cancelledWithoutInquiry['refund_display_state_text'], 'cancelled local record is not active');
$rejectedWithoutInquiry = PaymentTrade::buildVirtualRefundProjection($iosEmptySnapshot, ['order_refund_id' => 1, 'status' => RefundStatus::REJECTED]);
assertSameValue('退款已拒绝', $rejectedWithoutInquiry['refund_display_state_text'], 'rejected local record is not active');

$completed = PaymentTrade::buildVirtualRefundProjection($inquiryTrade, ['order_refund_id' => 1, 'status' => RefundStatus::COMPLETED]);
assertSameValue('已退款', $completed['refund_display_state_text'], 'completed overrides inquiry');

$localTrade = $iosEmptySnapshot;
$local = PaymentTrade::buildVirtualRefundProjection($localTrade, ['order_refund_id' => 1, 'status' => RefundStatus::NORMAL]);
assertSameValue('退款申请已提交，请前往 App Store 申请退款', $local['refund_display_state_text'], 'local submit before inquiry');

fwrite(STDOUT, "PASS ios payment channel contracts\n");
