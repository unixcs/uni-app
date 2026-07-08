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

use app\timer\service\Order as OrderService;
use cores\BaseTimer;

/**
 * 定时任务：虚拟退款高频补偿
 * 仅负责补偿 wechat_virtual 的 reviewed+normal 服务退款单状态收敛
 * Class VirtualRefundCompensation
 * @package app\timer\controller
 */
class VirtualRefundCompensation extends BaseTimer
{
    // 当前任务唯一标识
    protected string $taskKey = 'VirtualRefundCompensation';

    // 高频补偿：每分钟执行一次
    protected int $taskExpire = 60;

    /**
     * 任务处理
     * @param array $param
     */
    public function handle(array $param)
    {
        ['storeId' => $this->storeId] = $param;
        $this->setInterval($this->storeId, $this->taskKey, $this->taskExpire, function () {
            echo $this->taskKey . PHP_EOL;
            $this->runIsolated('syncPendingVirtualRefunds', function () {
                $this->syncPendingVirtualRefunds();
            });
        });
    }

    /**
     * 虚拟支付退款高频补偿
     * @return void
     */
    private function syncPendingVirtualRefunds()
    {
        $service = new OrderService;
        $service->syncPendingVirtualRefunds($this->storeId);
    }

    /**
     * 隔离执行单个子任务，避免异常影响主循环
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
}
