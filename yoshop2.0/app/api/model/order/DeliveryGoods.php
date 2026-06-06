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

namespace app\api\model\order;

use app\common\model\order\DeliveryGoods as DeliveryGoodsModel;

/**
 * 订单发货单模型
 * Class DeliveryGoods
 * @package app\api\model\order
 */
class DeliveryGoods extends DeliveryGoodsModel
{
    // 隐藏的字段
    protected $hidden = [
        'store_id',
        'create_time',
    ];
}