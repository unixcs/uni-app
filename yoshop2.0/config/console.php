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
        'service-order:history-cleanup' => \app\common\command\ServiceOrderHistoryCleanup::class,
        'service-order:e2e' => \app\common\command\ServiceOrderE2eAutomation::class,
        'virtual-payment:sandbox-check' => \app\common\command\VirtualPaymentSandboxCheck::class,
        'virtual-payment:local-e2e' => \app\common\command\VirtualPaymentLocalE2e::class,
        'virtual-payment:oauth-audit' => \app\common\command\VirtualPaymentOauthAudit::class,
        'virtual-payment:watch-live' => \app\common\command\VirtualPaymentWatchLive::class,
    ],
];
