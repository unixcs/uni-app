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

namespace app\api\model\recharge;

use app\common\model\recharge\OrderPlan as OrderPlanModel;

/**
 * 用户充值订单套餐快照模型
 * Class OrderPlan
 * @package app\api\model\recharge
 */
class OrderPlan extends OrderPlanModel
{
    /**
     * 新增记录
     * @param $orderId
     * @param $data
     * @return bool|false
     */
    public function add($orderId, $data): bool
    {
        return $this->save([
            'order_id' => $orderId,
            'plan_id' => $data['plan_id'],
            'plan_name' => $data['plan_name'],
            'money' => $data['money'],
            'gift_money' => $data['gift_money'],
            'store_id' => self::$storeId
        ]);
    }
}