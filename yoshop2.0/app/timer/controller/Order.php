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

namespace app\timer\controller;

use cores\BaseTimer;
use app\timer\model\Setting as SettingModel;
use app\timer\service\Order as OrderService;

/**
 * 定时任务：商城订单
 * Class Order
 * @package app\timer\controller
 */
class Order extends BaseTimer
{
    // 当前任务唯一标识
    protected string $taskKey = 'Order';

    // 任务执行间隔时长 (单位:秒)
    protected int $taskExpire = 60 * 10;

    /**
     * 任务处理
     * @param array $param
     */
    public function handle(array $param)
    {
        ['storeId' => $this->storeId] = $param;
        $this->setInterval($this->storeId, $this->taskKey, $this->taskExpire, function () {
            echo $this->taskKey . PHP_EOL;
            $this->runIsolated('syncVirtualTradeStates', function () {
                $this->syncVirtualTradeStates();
            });
            $this->runIsolated('closeEvent', function () {
                $this->closeEvent();
            });
            $this->runIsolated('syncVirtualRefunds', function () {
                $this->syncVirtualRefunds();
            });
            $this->runIsolated('syncVirtualProvideGoods', function () {
                $this->syncVirtualProvideGoods();
            });
            $this->runIsolated('settledEvent', function () {
                $this->settledEvent();
            });
        });
    }

    /**
     * 未支付订单自动关闭
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    private function closeEvent()
    {
        // 自动关闭订单的有效期
        $closeHours = (int)$this->getTradeSetting()['closeHours'];
        // 执行自动关闭
        if ($closeHours > 0) {
            $service = new OrderService;
            $service->closeEvent($this->storeId, $closeHours);
        }
    }

    /**
     * 已完成订单自动结算
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    private function settledEvent()
    {
        // 取消n天以前的的未付款订单
        $refundDays = (int)$this->getTradeSetting()['refund_days'];
        // 执行自动确认收货
        if ($refundDays > 0) {
            $service = new OrderService;
            $service->settledEvent($this->storeId, $refundDays);
        }
    }

    /**
     * 虚拟支付退款状态收敛
     * @return void
     */
    private function syncVirtualRefunds()
    {
        $service = new OrderService;
        $service->syncVirtualRefunds($this->storeId);
    }

    /**
     * 虚拟支付支付状态收敛
     * @return void
     */
    private function syncVirtualTradeStates()
    {
        $service = new OrderService;
        $service->syncVirtualTradeStates($this->storeId);
    }

    /**
     * 虚拟支付履约通知补偿
     * @return void
     */
    private function syncVirtualProvideGoods()
    {
        $service = new OrderService;
        $service->syncVirtualProvideGoods($this->storeId);
    }

    /**
     * 隔离执行单个子任务，避免互相拖垮
     * @param string $name
     * @param callable $callback
     * @return void
     */
    private function runIsolated(string $name, callable $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            log_record([
                'name' => '定时任务异常',
                'task' => $this->taskKey,
                'method' => $name,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 获取商城交易设置
     * @return array|mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    private function getTradeSetting()
    {
        return SettingModel::getItem('trade', $this->storeId)['order'];
    }
}
