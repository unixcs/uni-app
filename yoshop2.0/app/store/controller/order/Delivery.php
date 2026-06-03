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

namespace app\store\controller\order;

use think\response\Json;
use app\store\controller\Controller;
use app\store\model\order\Delivery as DeliveryModel;
use app\store\service\order\EOrder as EOrderService;
use app\store\service\order\Delivery as DeliveryService;

/**
 * 订单发货管理
 * Class Delivery
 * @package app\store\controller\order
 */
class Delivery extends Controller
{
    private function disabled(): Json
    {
        return $this->renderError('服务订单不支持发货和物流管理');
    }

    /**
     * 订单发货记录
     * @return Json
     */
    public function list(): Json
    {
        return $this->disabled();
    }

    /**
     * 发货单详情
     * @param int $deliveryId 发货单ID
     * @return Json
     */
    public function detail(int $deliveryId): Json
    {
        return $this->disabled();
    }

    /**
     * 确认发货 (手动录入)
     * @param int $orderId 订单ID
     * @return Json
     */
    public function delivery(int $orderId): Json
    {
        return $this->disabled();
    }

    /**
     * 查询指定发货单的物流跟踪信息
     * @param int $deliveryId 发货单ID
     * @return Json
     * @throws \cores\exception\BaseException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function traces(int $deliveryId): Json
    {
        return $this->disabled();
    }
}
