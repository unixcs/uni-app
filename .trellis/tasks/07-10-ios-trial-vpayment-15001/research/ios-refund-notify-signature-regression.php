<?php
/**
 * 虚拟支付通知入口签名回归：覆盖明文 signature 与安全模式 msg_signature。
 *
 * Usage: cd yoshop2.0 && php ../.trellis/tasks/07-10-ios-trial-vpayment-15001/research/ios-refund-notify-signature-regression.php
 */
declare(strict_types=1);

require dirname(__DIR__, 4) . '/yoshop2.0/vendor/autoload.php';

$app = new \think\App();
$app->initialize();
\cores\BaseModel::$storeId = 10001;
$service = new \app\api\service\Notify();
$parse = new ReflectionMethod($service, 'parseVirtualNotifyParams');
$parse->setAccessible(true);
$getConfig = new ReflectionMethod($service, 'getVirtualMessagePushConfig');
$getConfig->setAccessible(true);
$config = (array)$getConfig->invoke($service);
$token = trim((string)($config['message_push_token'] ?? ''));
$aesKey = trim((string)($config['message_push_encoding_aes_key'] ?? ''));
$appId = (string)(\app\api\model\wxapp\Setting::getConfigBasic()['app_id'] ?? '');

if ($token === '' || $aesKey === '' || $appId === '') {
    fwrite(STDERR, "SKIP is forbidden: message push token/AES key/appId must be configured\n");
    exit(2);
}

$payload = [
    'Event' => 'xpay_subscribe_ios_refund_query_notify',
    'Env' => 0,
    'pay_order_id' => 'signature-regression-order',
];
$plainBody = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$timestamp = '1783788000';
$nonce = 'ios-refund-regression';

$bindRequest = static function (array $query) use ($app): void {
    $request = new \think\Request();
    $request->withGet($query);
    $app->instance('request', $request);
};
$expectFailure = static function (string $name, callable $callback, string $messageFragment): array {
    try {
        $callback();
    } catch (\Throwable $e) {
        if (strpos($e->getMessage(), $messageFragment) === false) {
            throw new RuntimeException($name . ' failed with unexpected error: ' . $e->getMessage(), 0, $e);
        }
        return ['status' => 'rejected', 'message' => $e->getMessage()];
    }
    throw new RuntimeException($name . ' unexpectedly passed');
};

$signatureParts = [$token, $timestamp, $nonce];
sort($signatureParts, SORT_STRING);
$plainSignature = sha1(implode($signatureParts));
$bindRequest(['signature' => $plainSignature, 'timestamp' => $timestamp, 'nonce' => $nonce]);
$plainParsed = $parse->invoke($service, $plainBody, 'application/json');
if (($plainParsed['Event'] ?? '') !== $payload['Event']) {
    throw new RuntimeException('plaintext valid signature did not produce the original event');
}

$bindRequest(['signature' => 'invalid', 'timestamp' => $timestamp, 'nonce' => $nonce]);
$plainWrong = $expectFailure(
    'plaintext wrong signature',
    static fn() => $parse->invoke($service, $plainBody, 'application/json'),
    '签名校验失败'
);
$bindRequest(['timestamp' => $timestamp, 'nonce' => $nonce]);
$plainMissing = $expectFailure(
    'plaintext missing signature',
    static fn() => $parse->invoke($service, $plainBody, 'application/json'),
    '签名参数不完整'
);

$encryptor = new \EasyWeChat\Kernel\Encryptor($appId, $token, $aesKey);
$encryptedXml = $encryptor->encrypt($plainBody, $nonce, (int)$timestamp);
$outer = (array)(\app\common\library\helper::xmlToArray($encryptedXml) ?: []);
$encrypted = (string)($outer['Encrypt'] ?? '');
$msgSignature = (string)($outer['MsgSignature'] ?? '');
if ($encrypted === '' || $msgSignature === '') {
    throw new RuntimeException('failed to construct encrypted fixture');
}

$bindRequest(['msg_signature' => $msgSignature, 'timestamp' => $timestamp, 'nonce' => $nonce]);
$encryptedParsed = $parse->invoke($service, $encryptedXml, 'application/xml');
if (($encryptedParsed['Event'] ?? '') !== $payload['Event']) {
    throw new RuntimeException('safe-mode valid signature did not produce the original event');
}

$bindRequest(['msg_signature' => 'invalid', 'timestamp' => $timestamp, 'nonce' => $nonce]);
$encryptedWrong = $expectFailure(
    'safe-mode wrong signature',
    static fn() => $parse->invoke($service, $encryptedXml, 'application/xml'),
    'Invalid Signature'
);
$bindRequest(['timestamp' => $timestamp, 'nonce' => $nonce]);
$encryptedMissing = $expectFailure(
    'safe-mode missing signature',
    static fn() => $parse->invoke($service, $encryptedXml, 'application/xml'),
    '配置不完整'
);

echo json_encode([
    'plaintext_valid' => ['status' => 'accepted', 'event' => $plainParsed['Event']],
    'plaintext_wrong' => $plainWrong,
    'plaintext_missing' => $plainMissing,
    'safe_mode_valid' => ['status' => 'accepted', 'event' => $encryptedParsed['Event']],
    'safe_mode_wrong' => $encryptedWrong,
    'safe_mode_missing' => $encryptedMissing,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), PHP_EOL;
