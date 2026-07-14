<?php
/**
 * Apple 退款问询回归（重复三次、未知交易、环境错配、JSON/XML 回包；写入最终回滚）。
 * Usage: cd yoshop2.0 && php ../.trellis/tasks/07-10-ios-trial-vpayment-15001/research/ios-refund-query-regression.php --trade-id=10334
 */
declare(strict_types=1);
require dirname(__DIR__, 4) . '/yoshop2.0/vendor/autoload.php';
$app = new \think\App();
$app->initialize();
$options = getopt('', ['trade-id:']);
$tradeId = (int)($options['trade-id'] ?? 10334);
$trade = \app\api\model\PaymentTrade::detail($tradeId);
if (empty($trade) || (string)($trade['platform'] ?? '') !== 'wechat_virtual') {
    fwrite(STDERR, "trade {$tradeId} is missing or is not wechat_virtual\n");
    exit(2);
}
$service = new \app\api\service\Notify();
$handler = new ReflectionMethod($service, 'handleVirtualIosRefundQueryNotify');
$handler->setAccessible(true);
$responseBuilder = new ReflectionMethod($service, 'buildVirtualIosRefundQueryResponse');
$responseBuilder->setAccessible(true);
$payload = [
    'Event' => 'xpay_subscribe_ios_refund_query_notify',
    'Env' => (int)($trade['env'] ?? 0),
    'pay_order_id' => (string)$trade['out_trade_no'],
    'product_id' => 'vip1',
    'refund_time' => '2026-07-12 00:00:00',
    'provide_status' => 'PROVIDED',
    'refund_request_reason' => '用户通过 App Store 申请退款',
];
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};
$beforeSnapshot = (string)$trade['payload_snapshot'];
\think\facade\Db::startTrans();
try {
    $started = microtime(true);
    $decisions = [];
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        $decision = $handler->invoke($service, $payload);
        $assert((int)($decision['result_code'] ?? -1) === 0, "attempt {$attempt} did not suggest refund");
        $assert(trim((string)($decision['result_info'] ?? '')) !== '', "attempt {$attempt} has empty result_info");
        $assert(trim((string)($decision['evidence'] ?? '')) !== '', "attempt {$attempt} has empty evidence");
        $decisions[] = $decision;
    }
    $elapsedMs = round((microtime(true) - $started) * 1000, 2);
    $assert($elapsedMs < 3000, "three local query attempts exceeded 3 seconds: {$elapsedMs}ms");

    $unknown = $handler->invoke($service, array_merge($payload, ['pay_order_id' => 'unknown-ios-refund-query']));
    $assert((int)($unknown['result_code'] ?? -1) === 0, 'unknown local trade did not fail open');
    $assert(strpos((string)$unknown['evidence'], 'local_trade=not_found_or_not_virtual') !== false, 'unknown trade evidence is not explicit');

    $envMismatchRejected = false;
    try {
        $handler->invoke($service, array_merge($payload, ['Env' => (int)$payload['Env'] === 0 ? 1 : 0]));
    } catch (\Throwable $e) {
        $envMismatchRejected = strpos($e->getMessage(), '环境不匹配') !== false;
    }
    $assert($envMismatchRejected, 'environment mismatch was not rejected by the handler');

    $json = $responseBuilder->invoke($service, 0, '建议退款', (string)$decisions[0]['evidence'], 'json');
    $jsonData = json_decode($json, true);
    $assert(is_array($jsonData) && (int)($jsonData['result_code'] ?? -1) === 0 && !empty($jsonData['evidence']), 'JSON response contract is invalid');
    $xml = $responseBuilder->invoke($service, 0, '建议退款', (string)$decisions[0]['evidence'], 'xml');
    $xmlData = (array)(\app\common\library\helper::xmlToArray($xml) ?: []);
    $assert((int)($xmlData['result_code'] ?? -1) === 0 && !empty($xmlData['evidence']), 'XML response contract is invalid');

    $updated = \app\api\model\PaymentTrade::detail($tradeId);
    $snapshot = \app\api\model\PaymentTrade::decodePayloadSnapshot((string)$updated['payload_snapshot']);
    $assert(($snapshot['ios_refund_query_notify']['event'] ?? '') === $payload['Event'], 'query event was not recorded');
    $assert(($snapshot['virtual_refund']['ios_refund_query_decision'] ?? '') === 'suggest_refund', 'query decision was not projected');
    $assert(!empty($snapshot['virtual_refund']['ios_refund_required']), 'iOS refund requirement was not projected');

    $result = [
        'trade_id' => $tradeId,
        'repeat_attempts' => count($decisions),
        'three_attempts_elapsed_ms' => $elapsedMs,
        'decision' => $decisions[0],
        'unknown_trade' => $unknown,
        'env_mismatch_rejected' => $envMismatchRejected,
        'json_contract' => $jsonData,
        'xml_contract' => $xmlData,
        'snapshot_decision' => $snapshot['virtual_refund']['ios_refund_query_decision'] ?? null,
    ];
} catch (\Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
} finally {
    \think\facade\Db::rollback();
}
$after = \app\api\model\PaymentTrade::detail($tradeId);
$assert((string)$after['payload_snapshot'] === $beforeSnapshot, 'rollback did not restore query snapshot');
$result['rollback_verified'] = true;
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), PHP_EOL;
