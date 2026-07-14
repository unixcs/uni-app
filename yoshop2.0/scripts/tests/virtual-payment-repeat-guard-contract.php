<?php
declare(strict_types=1);

$paymentFile = dirname(__DIR__, 2) . '/app/api/service/cashier/Payment.php';
$tradeFile = dirname(__DIR__, 2) . '/app/api/model/PaymentTrade.php';
$paymentSource = file_get_contents($paymentFile);
$tradeSource = file_get_contents($tradeFile);

if ($paymentSource === false || $tradeSource === false) {
    throw new RuntimeException('unable to read payment guard sources');
}

$expect = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    fwrite(STDOUT, "ok {$message}\n");
};

$expect(str_contains($paymentSource, "'state' => self::VIRTUAL_PAYMENT_STATE_CREATED"), 'new virtual attempts declare created state');
$expect(str_contains($paymentSource, 'resolveExistingVirtualPaymentAttempt'), 'orderPay preflights the latest virtual attempt');
$expect(str_contains($paymentSource, 'VIRTUAL_PAYMENT_STATE_CONFIRMING'), 'pending or unknown old trades return confirming state');
$expect(str_contains($paymentSource, 'VIRTUAL_PAYMENT_STATE_PAID'), 'paid old trades return paid state');
$expect(str_contains($paymentSource, 'getLatestVirtualTradeSnapshotByOrderId'), 'preflight reads the latest virtual trade');
$expect(str_contains($tradeSource, '支付结果确认中，请勿重复支付'), 'row-lock record path rejects concurrent virtual attempts');
$expect(str_contains($tradeSource, 'TradeStatusEnum::CLOSED'), 'explicitly closed attempts remain retryable');
$expect(str_contains($paymentSource, 'SELECT GET_LOCK(:lock_name, :lock_timeout)'), 'order-scoped advisory lock serializes virtual payment creation');
$expect(str_contains($paymentSource, 'SELECT RELEASE_LOCK(:lock_name)'), 'advisory lock is explicitly released');
$expect(str_contains($paymentSource, 'repeat_guard_lock_unavailable'), 'lock acquisition failure is traced and fails closed');
$expect(str_contains($paymentSource, '$this->pendingTradeQuery = $status !== self::VIRTUAL_ORDER_STATUS_CLOSED;'), 'unknown remote states remain pending unless explicitly closed');

$lockMethodStart = strpos($paymentSource, 'private function orderPayWithVirtualCreateLock');
$nextMethodStart = strpos($paymentSource, 'private function recordPaymentTrade', $lockMethodStart ?: 0);
$lockMethod = $lockMethodStart !== false && $nextMethodStart !== false
    ? substr($paymentSource, $lockMethodStart, $nextMethodStart - $lockMethodStart)
    : '';
$expect($lockMethod !== '', 'virtual create lock method is discoverable');
$expect(!str_contains($lockMethod, 'Db::transaction'), 'remote WeChat creation is not wrapped in a database transaction');
$expect(strpos($lockMethod, 'GET_LOCK') < strpos($lockMethod, 'executeOrderPay($extra, true)'), 'lock is acquired before virtual preflight/create/record');

fwrite(STDOUT, "PASS backend virtual payment repeat guard contract\n");
