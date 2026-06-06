<?php
// +----------------------------------------------------------------------
// | 商城系统 [ 致力于通过产品和服务，帮助商家高效化开拓市场 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2017~2025 https://www.example.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed 这不是一个自由软件，不允许对程序代码以任何形式任何目的的再发行
// +----------------------------------------------------------------------
// | Author: 项目团队 <admin@example.com>
// +----------------------------------------------------------------------
declare (strict_types=1);

namespace app\common\enum;

/**
 * 枚举类：订单优惠方式
 * Class DiscountType
 * @package app\common\enum
 */
class DiscountType extends EnumBasics
{
    // 积分抵扣
    const POINTS = 'points';

    // 会员等级折扣
    const GRADE = 'grade';

    // 优惠券
    const COUPON = 'coupon';

    // 满额包邮
    const FULL_FREE = 'full-free';

    /**
     * 获取全部类型
     * @return array
     */
    public static function data(): array
    {
        return [
            self::POINTS => [
                'name' => '积分抵扣',
                'value' => self::POINTS,
            ],
            self::GRADE => [
                'name' => '会员等级折扣',
                'value' => self::GRADE,
            ],
            self::COUPON => [
                'name' => '优惠券',
                'value' => self::COUPON,
            ],
            self::FULL_FREE => [
                'name' => '满额包邮',
                'value' => self::FULL_FREE,
            ]
        ];
    }
}