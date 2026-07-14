<?php
// +----------------------------------------------------------------------
// | iOS App Store 退款风险来源
// +----------------------------------------------------------------------
declare (strict_types=1);

namespace app\common\enum\order\iosRefund;

final class RiskSource
{
    const LOCAL_APPLY = 'LOCAL_APPLY';
    const APPLE_INQUIRY = 'APPLE_INQUIRY';
    const REFUND_NOTIFY_RECOVERY = 'REFUND_NOTIFY_RECOVERY';

    private function __construct()
    {
    }
}
