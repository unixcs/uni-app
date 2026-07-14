<?php
// +----------------------------------------------------------------------
// | 商城系统 [ 致力于通过产品和服务，帮助商家高效化开拓市场 ]
// +----------------------------------------------------------------------
declare (strict_types=1);

namespace app\common\enum\payment\trade;

use app\common\enum\EnumBasics;

/**
 * 枚举类：第三方支付交易记录 - 支付渠道分类
 *
 * 该字段是实际支付交易的可查询投影。UNKNOWN 不能等同于非 iOS，
 * IOS_APPLE 一旦由强证据确认后不得降级。
 */
class ChannelClass extends EnumBasics
{
    const UNKNOWN = 0;
    const NON_IOS = 10;
    const IOS_APPLE = 20;

    public static function data(): array
    {
        return [
            self::UNKNOWN => [
                'name' => '渠道待确认',
                'value' => self::UNKNOWN,
            ],
            self::NON_IOS => [
                'name' => '非 iOS 订单',
                'value' => self::NON_IOS,
            ],
            self::IOS_APPLE => [
                'name' => 'iOS 订单',
                'value' => self::IOS_APPLE,
            ],
        ];
    }
}
