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

namespace app\store\controller\order;

use think\response\Json;
use think\db\exception\DbException;
use app\store\controller\Controller;
use app\store\model\Order as OrderModel;

/**
 * 订单事件控制器
 * Class Event
 * @package app\store\controller\order
 */
class Event extends Controller
{
    private function disabled(): Json
    {
        return $this->renderError('服务订单不支持地址、物流和发货操作');
    }

    /**
     * 修改订单价格
     * @param int $orderId
     * @return Json
     */
    public function updatePrice(int $orderId): Json
    {
        $model = OrderModel::detail($orderId);
        if ($model->updatePrice($this->postForm())) {
            return $this->renderSuccess('操作成功');
        }
        return $this->renderError($model->getError() ?: '操作失败');
    }

    /**
     * 修改商家备注
     * @param int $orderId
     * @return Json
     */
    public function updateRemark(int $orderId): Json
    {
        $model = OrderModel::detail($orderId);
        if ($model->updateRemark($this->postForm())) {
            return $this->renderSuccess('操作成功');
        }
        return $this->renderError($model->getError() ?: '操作失败');
    }

    /**
     * 修改收货地址
     * @param int $orderId
     * @return Json
     */
    public function updateAddress(int $orderId): Json
    {
        return $this->disabled();
    }

    /**
     * 小票打印
     * @param int $orderId
     * @return Json
     * @throws DbException
     * @throws \cores\exception\BaseException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function printer(int $orderId): Json
    {
        return $this->disabled();
    }

    /**
     * 审核：用户取消订单
     * @param $orderId
     * @return Json
     */
    public function confirmCancel($orderId): Json
    {
        $model = OrderModel::detail($orderId);
        if ($model->confirmCancel($this->postForm())) {
            return $this->renderSuccess('操作成功');
        }
        return $this->renderError($model->getError() ?: '操作失败');
    }

    /**
     * 删除订单记录
     * @param int $orderId
     * @return Json
     */
    public function delete(int $orderId): Json
    {
        $model = OrderModel::detail($orderId);
        if ($model->setDelete()) {
            return $this->renderSuccess('删除成功');
        }
        return $this->renderError($model->getError() ?: '操作失败');
    }

    /**
     * 开始服务
     * @param int $orderId
     * @return Json
     */
    public function startService(int $orderId): Json
    {
        $model = OrderModel::detail($orderId);
        if ($model->startService()) {
            return $this->renderSuccess('操作成功');
        }
        return $this->renderError($model->getError() ?: '操作失败');
    }

    /**
     * 完成服务
     * @param int $orderId
     * @return Json
     */
    public function completeService(int $orderId): Json
    {
        $model = OrderModel::detail($orderId);
        if ($model->completeService()) {
            return $this->renderSuccess('操作成功');
        }
        return $this->renderError($model->getError() ?: '操作失败');
    }

    /**
     * 服务开始前退款
     * @param int $orderId
     * @return Json
     */
    public function refundBeforeService(int $orderId): Json
    {
        $model = OrderModel::detail($orderId);
        if ($model->refundBeforeService()) {
            return $this->renderSuccess('操作成功');
        }
        return $this->renderError($model->getError() ?: '操作失败');
    }
}
