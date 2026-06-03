<?php
// +----------------------------------------------------------------------
// | 萤火商城系统 [ 致力于通过产品和服务，帮助商家高效化开拓市场 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2017~2025 https://www.yiovo.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed 这不是一个自由软件，不允许对程序代码以任何形式任何目的的再发行
// +----------------------------------------------------------------------
// | Author: 萤火科技 <admin@yiovo.com>
// +----------------------------------------------------------------------
declare (strict_types=1);

namespace app\common\enum\order;

use app\common\enum\EnumBasics;

/**
 * 枚举类：订单类型
 * Class DataType
 * @package app\common\enum\order
 */
class DataType extends EnumBasics
{
    // 全部
    const ALL = 'all';

    // 待联系
    const CONTACT = 'contact';

    // 服务中
    const DELIVERY = 'delivery';

    // 服务中（服务语义主key）
    const IN_SERVICE = 'in_service';

    // 待收货(旧兼容)
    const RECEIPT = 'receipt';

    // 待付款
    const PAY = 'pay';

    // 已完成
    const COMPLETE = 'complete';

    // 退款处理中
    const APPLY_CANCEL = 'apply_cancel';

    // 已关闭/已取消
    const CANCEL = 'cancel';

    // 已退款
    const REFUND = 'refund';

    /**
     * 获取枚举数据
     * @return array
     */
    public static function data(): array
    {
        return [
            self::ALL => [
                'name' => '全部',
                'value' => self::ALL,
            ],
            self::DELIVERY => [
                'name' => '服务中',
                'value' => self::DELIVERY,
            ],
            self::IN_SERVICE => [
                'name' => '服务中',
                'value' => self::IN_SERVICE,
            ],
            self::CONTACT => [
                'name' => '待联系',
                'value' => self::CONTACT,
            ],
            self::RECEIPT => [
                'name' => '待收货',
                'value' => self::RECEIPT,
            ],
            self::PAY => [
                'name' => '待付款',
                'value' => self::PAY,
            ],
            self::COMPLETE => [
                'name' => '已完成',
                'value' => self::COMPLETE,
            ],
            self::APPLY_CANCEL => [
                'name' => '退款处理中',
                'value' => self::APPLY_CANCEL,
            ],
            self::CANCEL => [
                'name' => '已关闭',
                'value' => self::CANCEL,
            ],
            self::REFUND => [
                'name' => '已退款',
                'value' => self::REFUND,
            ],
        ];
    }
}
