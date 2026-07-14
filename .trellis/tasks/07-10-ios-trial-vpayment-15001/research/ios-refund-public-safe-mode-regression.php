<?php
/**
 * 公网安全模式回调回归：构造未知交易的加密 Apple 退款问询，验证入口、验签解密、响应契约和 3 秒预算。
 *
 * 不打印 token/AESKey/msg_signature/密文，不修改业务数据；未知交易仅产生服务端观测日志。
 * Usage: cd yoshop2.0 && php ../.trellis/tasks/07-10-ios-trial-vpayment-15001/research/ios-refund-public-safe-mode-regression.php
 */
declare(strict_types=1);

require dirname(__DIR__, 4) . '/yoshop2.0/vendor/autoload.php';

$app = new \think\App();
$app->initialize();
\cores\BaseModel::$storeId = 10001;

$config = \app\common\model\store\Setting::getItem(\app\common\enum\Setting::VIRTUAL_PAYMENT, 10001);
$appId = trim((string)(\app\api\model\wxapp\Setting::getConfigBasic(10001)['app_id'] ?? ''));
$token = trim((string)($config['message_push_token'] ?? ''));
$aesKey = trim((string)($config['message_push_encoding_aes_key'] ?? ''));
$baseUrl = rtrim(trim((string)($config['notify_base_url'] ?? '')), '/');
if ($appId === '' || $token === '' || $aesKey === '' || $baseUrl === '') {
    throw new RuntimeException('safe-mode callback configuration incomplete');
}

$outTradeNo = 'codex-e2e-unknown-' . date('YmdHis') . '-' . bin2hex(random_bytes(4));
$before = (int)\think\facade\Db::name('payment_trade')->where('out_trade_no', '=', $outTradeNo)->count();
$plain = json_encode([
    'Event' => 'xpay_subscribe_ios_refund_query_notify',
    'Env' => (int)($config['env'] ?? 0),
    'pay_order_id' => $outTradeNo,
    'product_id' => 'codex-safe-mode-probe',
    'provide_status' => 'unknown',
    'refund_request_reason' => 'public safe-mode E2E validation',
    'refund_time' => date('c'),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
$timestamp = time();
$nonce = 'codex-' . bin2hex(random_bytes(8));
$encryptor = new \EasyWeChat\Kernel\Encryptor($appId, $token, $aesKey);
$outerXml = $encryptor->encrypt($plain, $nonce, $timestamp);
$outer = (array)(\app\common\library\helper::xmlToArray($outerXml) ?: []);
$msgSignature = trim((string)($outer['MsgSignature'] ?? ''));
if ($msgSignature === '') {
    throw new RuntimeException('failed to construct signed encrypted payload');
}

$endpoint = $baseUrl . '/notice/virtualPayment.php';
$url = $endpoint . '?' . http_build_query([
    'msg_signature' => $msgSignature,
    'timestamp' => (string)$timestamp,
    'nonce' => $nonce,
]);
$context = stream_context_create(['http' => [
    'method' => 'POST',
    'timeout' => 3,
    'ignore_errors' => true,
    'header' => "Content-Type: application/xml\r\nUser-Agent: YoShop-Codex-SafeMode-E2E/1.0\r\nConnection: close\r\n",
    'content' => $outerXml,
]]);
$started = microtime(true);
$responseBody = @file_get_contents($url, false, $context);
$elapsedMs = round((microtime(true) - $started) * 1000, 2);
$headers = $http_response_header ?? [];
$status = 0;
if (isset($headers[0]) && preg_match('/\s(\d{3})\s/', $headers[0], $matches)) {
    $status = (int)$matches[1];
}
$response = is_string($responseBody)
    ? (array)(\app\common\library\helper::xmlToArray($responseBody) ?: [])
    : [];
$after = (int)\think\facade\Db::name('payment_trade')->where('out_trade_no', '=', $outTradeNo)->count();
$evidence = (string)($response['evidence'] ?? '');
$checks = [
    'http_200' => $status === 200,
    'within_3s' => $elapsedMs < 3000,
    'result_code_zero' => (string)($response['result_code'] ?? '') === '0',
    'result_info_present' => trim((string)($response['result_info'] ?? '')) !== '',
    'evidence_present' => trim($evidence) !== '',
    'unknown_trade_evidence' => str_contains($evidence, 'local_trade=not_found_or_not_virtual'),
    'no_trade_before' => $before === 0,
    'no_trade_after' => $after === 0,
    'no_exception_leak' => !preg_match('/exception|trace|\/opt\/|sqlstate|fatal error/i', (string)$responseBody),
];

echo json_encode([
    'endpoint' => $endpoint,
    'http_status' => $status,
    'elapsed_ms' => $elapsedMs,
    'response_keys' => array_keys($response),
    'checks' => $checks,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), PHP_EOL;
if (in_array(false, $checks, true)) {
    fwrite(STDERR, 'safe-mode public E2E failed; response=' . substr((string)$responseBody, 0, 1000) . PHP_EOL);
    exit(1);
}
