<?php
// +----------------------------------------------------------------------
// | 控制台配置
// +----------------------------------------------------------------------
return [
    // 指令定义
    'commands' => [
        // 定时任务
        'timer' => \app\timer\command\Timer::class,
        'inspect:service-orders' => \app\common\command\InspectServiceOrders::class,
        'service-order:e2e' => \app\common\command\ServiceOrderE2eAutomation::class,
    ],
];
