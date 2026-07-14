<?php
// +----------------------------------------------------------------------
// | iOS App Store 退款风险状态
// +----------------------------------------------------------------------
declare (strict_types=1);

namespace app\common\enum\order\iosRefund;

use app\common\enum\EnumBasics;

class RiskStatus extends EnumBasics
{
    const NONE = 0;
    const LOCKED = 10;
    const REFUNDED = 20;

    public static function data(): array
    {
        return [
            self::NONE => ['name' => '无退款风险', 'value' => self::NONE],
            self::LOCKED => ['name' => '服务已冻结', 'value' => self::LOCKED],
            self::REFUNDED => ['name' => 'App Store退款成功', 'value' => self::REFUNDED],
        ];
    }
}
