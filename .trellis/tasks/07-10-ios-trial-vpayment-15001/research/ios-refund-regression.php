<?php
/**
 * Apple 退款成功通知本地收敛回归（所有业务写入均在事务中并最终回滚）。
 *
 * Usage:
 *   cd yoshop2.0
 *   php ../.trellis/tasks/07-10-ios-trial-vpayment-15001/research/ios-refund-regression.php --trade-id=10334
 */
declare(strict_types=1);

require dirname(__DIR__, 4) . '/yoshop2.0/vendor/autoload.php';

$app = new \think\App();
$app->initialize();
$options = getopt('', ['trade-id:']);
$tradeId = (int)($options['trade-id'] ?? 10334);
$trade = \app\common\model\PaymentTrade::detail($tradeId);
if (empty($trade) || (string)($trade['platform'] ?? '') !== 'wechat_virtual') {
    fwrite(STDERR, "trade {$tradeId} is missing or is not wechat_virtual\n");
    exit(2);
}
$orderId = (int)$trade['order_id'];
$order = \think\facade\Db::name('order')->where('order_id', $orderId)->find();
$orderGoodsId = (int)\think\facade\Db::name('order_goods')->where('order_id', $orderId)->order('order_goods_id')->value('order_goods_id');
if (empty($order) || $orderGoodsId <= 0) {
    fwrite(STDERR, "trade {$tradeId} has no usable order/order_goods\n");
    exit(2);
}

$payload = [
    'Event' => 'xpay_refund_notify',
    'Env' => (int)($trade['env'] ?? 0),
    'MchOrderId' => (string)$trade['out_trade_no'],
    'RetCode' => 0,
    'RetMsg' => 'success',
];
$service = new \app\common\service\order\Refund();

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$countServiceRefunds = static fn(): int => (int)\think\facade\Db::name('order_refund')
    ->where('order_id', $orderId)
    ->where('type', \app\common\enum\order\refund\RefundType::SERVICE)
    ->count();
$createRefund = static function (int $auditStatus, string $description, int $status = \app\common\enum\order\refund\RefundStatus::NORMAL) use ($order, $orderGoodsId): int {
    $refund = new \app\common\model\OrderRefund();
    $saved = $refund->save([
        'order_goods_id' => $orderGoodsId,
        'order_id' => (int)$order['order_id'],
        'user_id' => (int)$order['user_id'],
        'type' => \app\common\enum\order\refund\RefundType::SERVICE,
        'apply_desc' => $description,
        'audit_status' => $auditStatus,
        'status' => $status,
        'refund_money' => (string)$order['pay_price'],
        'store_id' => (int)$order['store_id'],
    ]);
    if ($saved === false) {
        throw new RuntimeException('failed to create refund fixture');
    }
    return (int)$refund['order_refund_id'];
};
$runRollbackScenario = static function (callable $scenario) use ($orderId, $tradeId, $countServiceRefunds, $assert): array {
    $beforeOrder = \think\facade\Db::name('order')->where('order_id', $orderId)->find();
    $beforeTrade = \think\facade\Db::name('payment_trade')->where('trade_id', $tradeId)->find();
    $beforeCount = $countServiceRefunds();
    \think\facade\Db::startTrans();
    try {
        $result = $scenario();
    } finally {
        \think\facade\Db::rollback();
    }
    $afterOrder = \think\facade\Db::name('order')->where('order_id', $orderId)->find();
    $afterTrade = \think\facade\Db::name('payment_trade')->where('trade_id', $tradeId)->find();
    $assert((int)$afterOrder['order_status'] === (int)$beforeOrder['order_status'], 'rollback did not restore order status');
    $assert((int)$afterTrade['trade_state'] === (int)$beforeTrade['trade_state'], 'rollback did not restore trade state');
    $assert((string)$afterTrade['payload_snapshot'] === (string)$beforeTrade['payload_snapshot'], 'rollback did not restore trade snapshot');
    $assert($countServiceRefunds() === $beforeCount, 'rollback did not restore refund rows');
    return $result;
};

try {
    $baselineCount = $countServiceRefunds();
    $assert($baselineCount === 0, 'fixture requires an order without existing service refunds');

    $autoCreateAndDuplicate = $runRollbackScenario(static function () use ($service, $tradeId, $payload, $countServiceRefunds, $orderId, $assert): array {
        $first = $service->finalizeVirtualRefundByTrade($tradeId, $payload);
        $afterFirstCount = $countServiceRefunds();
        $second = $service->finalizeVirtualRefundByTrade($tradeId, $payload);
        $afterSecondCount = $countServiceRefunds();
        $refund = \think\facade\Db::name('order_refund')->where('order_refund_id', (int)($first['order_refund_id'] ?? 0))->find();
        $insideOrder = \think\facade\Db::name('order')->where('order_id', $orderId)->find();
        $insideTrade = \think\facade\Db::name('payment_trade')->where('trade_id', $tradeId)->find();
        $snapshot = \app\common\model\PaymentTrade::decodePayloadSnapshot((string)$insideTrade['payload_snapshot']);
        $assert(($first['status'] ?? '') === 'completed' && ($first['mode'] ?? '') === 'refund_notify', 'first notify did not complete auto-created refund');
        $assert(($second['status'] ?? '') === 'completed' && ($second['mode'] ?? '') === 'already_completed', 'duplicate notify was not idempotent');
        $assert($afterFirstCount === 1 && $afterSecondCount === 1, 'duplicate notify created duplicate refund rows');
        $assert((int)$refund['status'] === \app\common\enum\order\refund\RefundStatus::COMPLETED, 'auto-created refund is not completed');
        $assert((int)$insideOrder['order_status'] === \app\common\enum\order\OrderStatus::CANCELLED, 'order did not converge to cancelled/refunded');
        $assert((int)$insideTrade['trade_state'] === \app\common\enum\payment\trade\TradeStatus::REFUND, 'trade did not converge to refund');
        $assert(($snapshot['virtual_refund']['status'] ?? '') === 'completed', 'snapshot did not converge to completed');
        return ['first' => $first, 'second' => $second, 'refund_count' => $afterSecondCount];
    });

    $reuseWaiting = $runRollbackScenario(static function () use ($createRefund, $service, $tradeId, $payload, $countServiceRefunds, $assert): array {
        $refundId = $createRefund(\app\common\enum\order\refund\AuditStatus::WAIT, 'waiting record fixture');
        $result = $service->finalizeVirtualRefundByTrade($tradeId, $payload);
        $refund = \think\facade\Db::name('order_refund')->where('order_refund_id', $refundId)->find();
        $assert((int)($result['order_refund_id'] ?? 0) === $refundId, 'notify did not reuse the unique waiting refund');
        $assert(($result['status'] ?? '') === 'completed', 'waiting refund did not complete');
        $assert((int)$refund['audit_status'] === \app\common\enum\order\refund\AuditStatus::REVIEWED, 'waiting refund was not promoted to reviewed');
        $assert((int)$refund['status'] === \app\common\enum\order\refund\RefundStatus::COMPLETED, 'reused refund is not completed');
        $assert($countServiceRefunds() === 1, 'reuse scenario created an extra refund');
        return ['result' => $result, 'reused_refund_id' => $refundId, 'refund_count' => $countServiceRefunds()];
    });

    $completedWithoutBinding = $runRollbackScenario(static function () use ($createRefund, $service, $tradeId, $payload, $countServiceRefunds, $orderId, $assert): array {
        $refundId = $createRefund(
            \app\common\enum\order\refund\AuditStatus::REVIEWED,
            'completed row without snapshot binding',
            \app\common\enum\order\refund\RefundStatus::COMPLETED
        );
        $result = $service->finalizeVirtualRefundByTrade($tradeId, $payload);
        $insideOrder = \think\facade\Db::name('order')->where('order_id', $orderId)->find();
        $insideTrade = \think\facade\Db::name('payment_trade')->where('trade_id', $tradeId)->find();
        $assert((int)($result['order_refund_id'] ?? 0) === $refundId, 'completed refund without snapshot binding was not reused');
        $assert(($result['mode'] ?? '') === 'already_completed', 'completed refund did not use already_completed mode');
        $assert($countServiceRefunds() === 1, 'completed refund duplicate notify created a second row');
        $assert((int)$insideOrder['order_status'] === \app\common\enum\order\OrderStatus::CANCELLED, 'completed refund did not repair order convergence');
        $assert((int)$insideTrade['trade_state'] === \app\common\enum\payment\trade\TradeStatus::REFUND, 'completed refund did not repair trade convergence');
        return ['result' => $result, 'reused_refund_id' => $refundId, 'refund_count' => $countServiceRefunds()];
    });

    $ambiguousCompleted = $runRollbackScenario(static function () use ($createRefund, $service, $tradeId, $payload, $countServiceRefunds, $assert): array {
        $firstId = $createRefund(
            \app\common\enum\order\refund\AuditStatus::REVIEWED,
            'ambiguous completed fixture A',
            \app\common\enum\order\refund\RefundStatus::COMPLETED
        );
        $secondId = $createRefund(
            \app\common\enum\order\refund\AuditStatus::REVIEWED,
            'ambiguous completed fixture B',
            \app\common\enum\order\refund\RefundStatus::COMPLETED
        );
        $result = $service->finalizeVirtualRefundByTrade($tradeId, $payload);
        $assert(($result['status'] ?? '') === 'ambiguous_completed_refund_binding', 'multiple completed refunds were guessed instead of rejected');
        $assert($countServiceRefunds() === 2, 'ambiguous completed scenario unexpectedly created a third refund');
        return ['result' => $result, 'candidate_ids' => [$firstId, $secondId], 'refund_count' => $countServiceRefunds()];
    });

    $ambiguous = $runRollbackScenario(static function () use ($createRefund, $service, $tradeId, $payload, $countServiceRefunds, $assert): array {
        $firstId = $createRefund(\app\common\enum\order\refund\AuditStatus::WAIT, 'ambiguous fixture A');
        $secondId = $createRefund(\app\common\enum\order\refund\AuditStatus::WAIT, 'ambiguous fixture B');
        $result = $service->finalizeVirtualRefundByTrade($tradeId, $payload);
        $assert(($result['status'] ?? '') === 'ambiguous_refund_binding', 'ambiguous refunds were guessed instead of rejected');
        $assert($countServiceRefunds() === 2, 'ambiguous scenario unexpectedly mutated refund row count');
        return ['result' => $result, 'candidate_ids' => [$firstId, $secondId], 'refund_count' => $countServiceRefunds()];
    });

    echo json_encode([
        'trade_id' => $tradeId,
        'order_id' => $orderId,
        'auto_create_and_duplicate' => $autoCreateAndDuplicate,
        'reuse_waiting_refund' => $reuseWaiting,
        'completed_without_snapshot_binding' => $completedWithoutBinding,
        'ambiguous_completed_refunds' => $ambiguousCompleted,
        'ambiguous_refunds' => $ambiguous,
        'rollback_verified' => true,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), PHP_EOL;
} catch (\Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
