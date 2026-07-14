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

namespace app\api\service;

use app\api\model\Payment as PaymentModel;
use app\api\model\PaymentTrade as PaymentTradeModel;
use app\api\model\PaymentTemplate as PaymentTemplateModel;
use app\api\model\wxapp\Setting as WxappSettingModel;
use app\api\service\order\PaySuccess as OrderPaySuccessService;
use app\api\service\recharge\PaySuccess as RechargePaySuccessService;
use app\common\service\order\Refund as OrderRefundService;
use app\common\service\order\IosRefundRisk as IosRefundRiskService;
use app\common\enum\OrderType as OrderTypeEnum;
use app\common\enum\Setting as SettingEnum;
use app\common\enum\payment\Method as PaymentMethodEnum;
use app\common\library\Log;
use app\common\library\helper;
use app\common\library\payment\gateway\Driver;
use app\common\library\payment\Facade as PaymentFacade;
use app\common\library\payment\gateway\driver\wechat\V3 as WechatPaymentV3;
use app\common\model\store\Setting as StoreSettingModel;
use cores\exception\BaseException;
use EasyWeChat\Kernel\Encryptor as WechatEncryptor;

/**
 * 服务类：第三方支付异步通知
 * Class Notify
 * @package app\api\service
 */
class Notify
{
    private const VIRTUAL_EVENT_PAY = 'xpay_goods_deliver_notify';
    private const VIRTUAL_EVENT_REFUND = 'xpay_refund_notify';
    private const VIRTUAL_EVENT_IOS_REFUND_QUERY = 'xpay_subscribe_ios_refund_query_notify';
    private const VIRTUAL_PLATFORM = 'wechat_virtual';

    // 异步通知的请求参数 (由第三方支付发送)
    private array $notifyParams;

    // 当前请求是否使用微信消息推送安全模式；仅非空业务回包需要沿用该模式加密。
    private bool $virtualNotifyEncrypted = false;
    private string $virtualNotifyNonce = '';

    /**
     * 微信消息推送 URL 验证
     * @return string
     */
    public function virtualPaymentVerify(): string
    {
        $config = $this->getVirtualMessagePushConfig();
        $token = trim((string)($config['message_push_token'] ?? ''));
        $signature = (string)request()->param('signature', '');
        $timestamp = (string)request()->param('timestamp', '');
        $nonce = (string)request()->param('nonce', '');
        $echoStr = (string)request()->param('echostr', '');
        if ($token === '' || $signature === '' || $timestamp === '' || $nonce === '' || $echoStr === '') {
            throwError('虚拟支付消息推送 URL 验证参数不完整');
        }
        $this->assertPlaintextSignature($token, $signature, $timestamp, $nonce);
        return $echoStr;
    }

    /**
     * 支付成功异步通知 (微信支付V2)
     * @return string
     */
    public function wechatV2(): string
    {
        try {
            // 获取第三方交易记录
            $tradeInfo = $this->getTradeInfo(PaymentMethodEnum::WECHAT, 'v2');
            // 构建支付模块
            $Payment = $this->getPayment($tradeInfo);
            // 验证异步通知参数是否合法
            if (!$Payment->notify()) {
                throwError($Payment->getError() ?: '微信支付V2异步通知验证未通过');
            }
            // 订单支付成功事件
            $this->orderPaySuccess($tradeInfo, $Payment->getNotifyParams());
        } catch (\Throwable $e) {
            // 记录错误日志
            Log::append('Notify-wechat', ['errMessage' => $e->getMessage()]);
        }
        return isset($Payment) ? $Payment->getNotifyResponse() : 'FAIL';
    }

    /**
     * 支付成功异步通知 (微信支付V3)
     * @return string
     */
    public function wechatV3(): string
    {
        try {
            // 微信支付V3异步回调验证
            $this->wechatV3Notify();
            // 获取异步回调的请求参数
            $notifyParams = $this->getNotifyParams();
            // 获取第三方交易记录
            $tradeInfo = PaymentTradeModel::detailByOutTradeNo($notifyParams['outTradeNo']);
            // 订单支付成功事件
            $this->orderPaySuccess($tradeInfo, $notifyParams);
        } catch (\Throwable $e) {
            // 记录错误日志
            Log::append('Notify-wechat', ['errMessage' => $e->getMessage()]);
        }
        return '';
    }

    /**
     * 微信支付V3异步回调验证
     * @return void
     * @throws BaseException
     */
    private function wechatV3Notify()
    {
        // 通过微信支付v3平台证书序号或微信支付公钥ID 获取支付模板
        $platformCertificateSerialOrPublicKeyId = \request()->header('wechatpay-serial');
        $templateInfo = PaymentTemplateModel::findByWechatpaySerial($platformCertificateSerialOrPublicKeyId);
        empty($templateInfo) && throwError("未找到该平台证书序号或微信支付公钥ID：$platformCertificateSerialOrPublicKeyId");
        // 从支付模板中取出v3apikey 用于解密异步通知的密文
        $apiV3Key = $templateInfo['config']['wechat']['mchType'] === 'provider'
            ? $templateInfo['config']['wechat']['provider']['spApiKey']
            : $templateInfo['config']['wechat']['normal']['apiKey'];
        // 获取「微信支付平台证书」或者「微信支付平台公钥」的路径
        $platformCertificateOrPublicKeyFilePath = $this->getPlatformCertificateOrPublicKeyFilePath($templateInfo);
        // 验证异步通知参数是否合法
        $V3 = new WechatPaymentV3();
        if (!$V3->notify($apiV3Key, $platformCertificateOrPublicKeyFilePath)) {
            throwError($V3->getError() ?: '异步通知验证未通过');
        }
        // 获取异步回调的请求参数
        $this->notifyParams = $V3->getNotifyParams();
    }

    /**
     * 获取异步回调的请求参数
     * @return array
     */
    public function getNotifyParams(): array
    {
        return $this->notifyParams;
    }

    /**
     * 获取「微信支付平台证书」或者「微信支付平台公钥」的路径
     * @param $templateInfo
     * @return false|string
     */
    private function getPlatformCertificateOrPublicKeyFilePath($templateInfo)
    {
        // $fileName = $templateInfo['config']['wechat'][$templateInfo['config']['wechat']['mchType']]['platformCert'];
        $mchType = $templateInfo['config']['wechat']['mchType'];
        $field1 = $mchType == 'normal' ? 'signatureMethod' : 'spSignatureMethod';
        $field2 = $mchType == 'normal' ? 'publicKey' : 'spPublicKey';
        $fileName = $templateInfo['config']['wechat'][$mchType][$field1] == 'publicKey'
            ? $templateInfo['config']['wechat'][$mchType][$field2]
            : $templateInfo['config']['wechat'][$mchType]['platformCert'];
        return PaymentTemplateModel::realPathCertFile(
            PaymentMethodEnum::WECHAT, $fileName, $templateInfo['store_id']
        );
    }

    /**
     * 支付成功异步通知 (支付宝)
     * @return string
     */
    public function alipay(): string
    {
        try {
            // 获取第三方交易记录
            $tradeInfo = $this->getTradeInfo(PaymentMethodEnum::ALIPAY);
            // 构建支付模块
            $Payment = $this->getPayment($tradeInfo);
            // 验证异步通知参数是否合法
            if (!$Payment->notify()) {
                throwError($Payment->getError() ?: '支付宝异步通知验证未通过');
            }
            // 订单支付成功事件
            $this->orderPaySuccess($tradeInfo, $Payment->getNotifyParams());
        } catch (\Throwable $e) {
            // 记录错误日志
            Log::append('Notify-alipay', ['errMessage' => $e->getMessage()]);
        }
        return isset($Payment) ? $Payment->getNotifyResponse() : 'FAIL';
    }

    /**
     * 微信虚拟支付推送
     * @return string
     */
    public function virtualPayment(): string
    {
        $responseFormat = 'json';
        $event = '';
        try {
            [$notifyParams, $responseFormat] = $this->getVirtualNotifyParams();
            $event = (string)($notifyParams['Event'] ?? '');
            if ($event === self::VIRTUAL_EVENT_PAY) {
                $this->handleVirtualPayNotify($notifyParams);
            } elseif ($event === self::VIRTUAL_EVENT_REFUND) {
                $this->handleVirtualRefundNotify($notifyParams);
            } elseif ($event === self::VIRTUAL_EVENT_IOS_REFUND_QUERY) {
                $response = $this->handleVirtualIosRefundQueryNotify($notifyParams);
                return $this->buildVirtualIosRefundQueryResponse(
                    (int)$response['result_code'],
                    (string)$response['result_info'],
                    (string)$response['evidence'],
                    $responseFormat
                );
            } else {
                throwError('未知的虚拟支付推送事件: ' . $event);
            }
            return $this->buildVirtualNotifyResponse(0, 'success', $responseFormat);
        } catch (\Throwable $e) {
            Log::append('Notify-virtualPayment', ['event' => $event, 'errMessage' => $e->getMessage()]);
            if ($event === self::VIRTUAL_EVENT_IOS_REFUND_QUERY) {
                // 异常时必须安全拒绝。不能在风险锁/问询记录未落库时向 Apple 建议退款。
                return $this->buildVirtualIosRefundQueryResponse(
                    1,
                    '建议拒绝退款',
                    '服务端暂时无法完成订单、审核与服务状态校验，建议本次拒绝并等待后续认证问询重试',
                    $responseFormat
                );
            }
            return $this->buildVirtualNotifyResponse(1, $e->getMessage(), $responseFormat);
        }
    }

    /**
     * 订单支付成功事件
     * @param PaymentTradeModel $tradeInfo
     * @param array $paymentData 第三方支付异步回调的
     */
    private function orderPaySuccess(PaymentTradeModel $tradeInfo, array $paymentData): void
    {
        // 记录日志
        Log::append('Notify-orderPaySuccess', [
            'orderType' => OrderTypeEnum::data()[$tradeInfo['order_type']]['name'],
            'tradeInfo' => $tradeInfo->toArray(),
        ]);
        try {
            // 订单支付成功业务处理 (商城订单)
            if ($tradeInfo['order_type'] == OrderTypeEnum::ORDER) {
                $service = new OrderPaySuccessService;
                $service->setOrderNo($tradeInfo['order_no'])
                    ->setMethod($tradeInfo['pay_method'])
                    ->setTradeId($tradeInfo['trade_id'])
                    ->setPaymentData($paymentData)
                    ->handle();
            }
            // 订单支付成功业务处理 (余额充值订单)
            if ($tradeInfo['order_type'] == OrderTypeEnum::RECHARGE) {
                $service = new RechargePaySuccessService;
                $service->setOrderNo($tradeInfo['order_no'])
                    ->setMethod($tradeInfo['pay_method'])
                    ->setTradeId($tradeInfo['trade_id'])
                    ->setPaymentData($paymentData)
                    ->handle();
            }
            Log::append('Notify-orderPaySuccess', ['message' => '订单支付成功']);
        } catch (\Throwable $e) {
            // 记录错误日志
            Log::append('Notify-orderPaySuccess', ['errMessage' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * 获取虚拟支付推送参数
     * @return array{0:array,1:string}
     */
    private function getVirtualNotifyParams(): array
    {
        $request = request();
        // 统一从框架请求读取 body，生产上仍对应 php://input；也让已认证入口可在本地做无网络合同回归。
        $rawBody = $request->getInput();
        $contentType = strtolower((string)$request->header('content-type'));
        $responseFormat = str_contains($contentType, 'xml') ? 'xml' : 'json';
        return [$this->parseVirtualNotifyParams($rawBody, $contentType), $responseFormat];
    }

    /**
     * 解析并验签虚拟支付推送参数。
     * 独立于 php://input，便于对明文/安全模式入口做无网络回归验证。
     * @param string $rawBody
     * @param string $contentType
     * @return array
     */
    private function parseVirtualNotifyParams(string $rawBody, string $contentType): array
    {
        $data = $this->decodeVirtualNotifyBody($rawBody, $contentType);
        if (!is_array($data)) {
            throwError('虚拟支付推送报文异常');
        }
        $this->virtualNotifyEncrypted = !empty($data['Encrypt']);
        $this->virtualNotifyNonce = (string)request()->param('nonce', '');
        if ($this->virtualNotifyEncrypted) {
            // decrypt() 同时校验 msg_signature、appId 与密文完整性；解密后的业务体不再含 Encrypt，
            // 不能继续按明文 signature 分支重复校验。
            $data = $this->decryptVirtualNotifyPayloadIfNeeded($data);
        } else {
            $this->verifyVirtualNotifySource($data);
        }
        if (empty($data['Event'])) {
            throwError('虚拟支付推送报文异常');
        }
        return $data;
    }

    /**
     * 处理虚拟支付成功推送
     * @param array $notifyParams
     * @return void
     */
    private function handleVirtualPayNotify(array $notifyParams): void
    {
        $outTradeNo = (string)($notifyParams['OutTradeNo'] ?? '');
        if ($outTradeNo === '') {
            throwError('虚拟支付成功推送缺少 OutTradeNo');
        }
        $tradeInfo = PaymentTradeModel::detailByOutTradeNo($outTradeNo);
        if ((string)$tradeInfo['platform'] !== self::VIRTUAL_PLATFORM) {
            throwError('支付单平台不匹配');
        }
        $this->assertVirtualNotifyEnv($tradeInfo, $notifyParams);
        PaymentTradeModel::recordNotify(
            (int)$tradeInfo['trade_id'],
            'pay_notify',
            (string)($notifyParams['Event'] ?? ''),
            $notifyParams
        );
        $wechatPayInfo = (array)($notifyParams['WeChatPayInfo'] ?? []);
        $paymentData = [
            'tradeNo' => (string)($wechatPayInfo['TransactionId'] ?? $notifyParams['TransactionId'] ?? $outTradeNo),
            'outTradeNo' => $outTradeNo,
            'orderStatus' => 2,
            'raw' => $notifyParams,
        ];
        $this->orderPaySuccess($tradeInfo, $paymentData);
    }

    /**
     * 处理虚拟支付退款推送
     * @param array $notifyParams
     * @return void
     */
    private function handleVirtualRefundNotify(array $notifyParams): void
    {
        $outTradeNo = (string)($notifyParams['MchOrderId'] ?? '');
        if ($outTradeNo === '') {
            throwError('虚拟支付退款推送缺少 MchOrderId');
        }
        $tradeInfo = PaymentTradeModel::detailByOutTradeNo($outTradeNo);
        if ((string)$tradeInfo['platform'] !== self::VIRTUAL_PLATFORM) {
            throwError('退款单平台不匹配');
        }
        $this->assertVirtualNotifyEnv($tradeInfo, $notifyParams);
        PaymentTradeModel::recordNotify(
            (int)$tradeInfo['trade_id'],
            'refund_notify',
            (string)($notifyParams['Event'] ?? ''),
            $notifyParams
        );
        if (!OrderRefundService::isSuccessfulVirtualRefundNotify($notifyParams)) {
            $this->logVirtualRefundObservation('refund_notify_nonzero_retcode', [
                'event' => (string)($notifyParams['Event'] ?? ''),
                'trade_id' => (int)$tradeInfo['trade_id'],
                'order_id' => (int)$tradeInfo['order_id'],
                'out_trade_no' => (string)$tradeInfo['out_trade_no'],
                'ret_code' => (string)($notifyParams['RetCode'] ?? ''),
                'ret_msg' => (string)($notifyParams['RetMsg'] ?? ''),
            ]);
            return;
        }
        $refundService = new OrderRefundService();
        $result = $refundService->finalizeVirtualRefundByTrade((int)$tradeInfo['trade_id'], $notifyParams);
        $this->logVirtualRefundObservation('refund_notify_finalize_result', [
            'event' => (string)($notifyParams['Event'] ?? ''),
            'trade_id' => (int)$tradeInfo['trade_id'],
            'order_id' => (int)$tradeInfo['order_id'],
            'out_trade_no' => (string)$tradeInfo['out_trade_no'],
            'ret_code' => (string)($notifyParams['RetCode'] ?? ''),
            'result_status' => (string)($result['status'] ?? ''),
            'result_mode' => (string)($result['mode'] ?? ''),
            'order_refund_id' => (int)($result['order_refund_id'] ?? 0),
            'message' => (string)($result['message'] ?? ''),
        ]);
        if ((string)($result['status'] ?? '') !== 'completed') {
            $status = (string)($result['status'] ?? 'unknown');
            $message = (string)($result['message'] ?? '');
            throwError('虚拟支付退款通知待重试: ' . $status . ($message !== '' ? (' - ' . $message) : ''));
        }
    }


    /**
     * 处理 Apple iOS 虚拟支付退款问询推送。
     * Apple 支付不支持开发者主动退款，开发者在该事件中只能给出是否建议退款的参考意见。
     * 为了保障用户在 App Store 发起退款后不被本系统误拦截，默认在无明确拒退依据时返回 result_code=0。
     * @param array $notifyParams
     * @return array{result_code:int,result_info:string,evidence:string}
     */
    private function handleVirtualIosRefundQueryNotify(array $notifyParams): array
    {
        $payOrderId = $this->getFirstString($notifyParams, [
            'pay_order_id',
            'PayOrderId',
            'PayOrderID',
            'MchOrderId',
            'OutTradeNo',
        ]);
        $startedAt = microtime(true);
        $result = (new IosRefundRiskService)->handleInquiry($payOrderId, $notifyParams, $startedAt);
        $this->logVirtualRefundObservation('ios_refund_query_decision', [
            'event' => (string)($notifyParams['Event'] ?? self::VIRTUAL_EVENT_IOS_REFUND_QUERY),
            'pay_order_id' => $payOrderId,
            'refund_request_reason' => $this->getFirstString($notifyParams, ['refund_request_reason', 'RefundRequestReason']),
            'result_code' => (int)$result['result_code'],
            'result_info' => (string)$result['result_info'],
        ]);
        return $result;
    }

    /**
     * 解析虚拟支付推送报文
     * @param string $rawBody
     * @param string $contentType
     * @return array
     */
    private function decodeVirtualNotifyBody(string $rawBody, string $contentType): array
    {
        if ($rawBody === '') {
            return [];
        }
        if (str_contains($contentType, 'json')) {
            return (array)(helper::jsonDecode($rawBody) ?: []);
        }
        if (str_contains($contentType, 'xml') || str_starts_with(ltrim($rawBody), '<')) {
            return (array)(helper::xmlToArray($rawBody) ?: []);
        }
        $json = helper::jsonDecode($rawBody);
        if (is_array($json)) {
            return $json;
        }
        return (array)(helper::xmlToArray($rawBody) ?: []);
    }

    /**
     * 安全模式下解密微信消息推送体
     * @param array $payload
     * @return array
     */
    private function decryptVirtualNotifyPayloadIfNeeded(array $payload): array
    {
        if (empty($payload['Encrypt'])) {
            return $payload;
        }
        $config = $this->getVirtualMessagePushConfig();
        $appId = (string)(WxappSettingModel::getConfigBasic()['app_id'] ?? '');
        $token = trim((string)($config['message_push_token'] ?? ''));
        $aesKey = trim((string)($config['message_push_encoding_aes_key'] ?? ''));
        $msgSignature = (string)request()->param('msg_signature', '');
        $nonce = (string)request()->param('nonce', '');
        $timestamp = (string)request()->param('timestamp', '');
        if ($appId === '' || $token === '' || $aesKey === '' || $msgSignature === '' || $nonce === '' || $timestamp === '') {
            throwError('虚拟支付安全模式推送配置不完整');
        }
        $encryptor = new WechatEncryptor($appId, $token, $aesKey);
        $plain = $encryptor->decrypt((string)$payload['Encrypt'], $msgSignature, $nonce, $timestamp);
        $decrypted = $this->decodeVirtualNotifyBody($plain, str_starts_with(ltrim($plain), '<') ? 'xml' : 'json');
        if (empty($decrypted)) {
            throwError('虚拟支付安全模式推送解密失败');
        }
        return $decrypted;
    }

    /**
     * 验证虚拟支付消息推送来源
     * @param array $payload
     * @return void
     */
    private function verifyVirtualNotifySource(array $payload): void
    {
        $config = $this->getVirtualMessagePushConfig();
        $token = trim((string)($config['message_push_token'] ?? ''));
        $signature = (string)request()->param('signature', '');
        $timestamp = (string)request()->param('timestamp', '');
        $nonce = (string)request()->param('nonce', '');
        if ($token === '' || $signature === '' || $timestamp === '' || $nonce === '') {
            throwError('虚拟支付明文推送签名参数不完整');
        }
        $this->assertPlaintextSignature($token, $signature, $timestamp, $nonce);
    }

    /**
     * 获取虚拟支付消息推送配置
     * @return array
     */
    private function getVirtualMessagePushConfig(): array
    {
        return StoreSettingModel::getItem(SettingEnum::VIRTUAL_PAYMENT);
    }

    /**
     * 校验明文模式签名
     * @param string $token
     * @param string $signature
     * @param string $timestamp
     * @param string $nonce
     * @return void
     */
    private function assertPlaintextSignature(string $token, string $signature, string $timestamp, string $nonce): void
    {
        $parts = [$token, $timestamp, $nonce];
        sort($parts, SORT_STRING);
        if (sha1(implode($parts)) !== $signature) {
            throwError('虚拟支付消息推送签名校验失败');
        }
    }

    /**
     * 校验通知环境与本地交易环境一致
     * @param PaymentTradeModel $tradeInfo
     * @param array $notifyParams
     * @return void
     */
    private function assertVirtualNotifyEnv(PaymentTradeModel $tradeInfo, array $notifyParams): void
    {
        if (!isset($notifyParams['Env'])) {
            return;
        }
        if ((int)$notifyParams['Env'] !== (int)$tradeInfo['env']) {
            throwError('虚拟支付推送环境不匹配');
        }
    }

    /**
     * 构建 Apple iOS 退款问询专用回包。
     * @param int $resultCode 0 建议退款，1 建议不退款
     * @param string $resultInfo
     * @param string $evidence
     * @param string $format
     * @return string
     */
    private function buildVirtualIosRefundQueryResponse(int $resultCode, string $resultInfo, string $evidence, string $format): string
    {
        $payload = [
            'result_code' => $resultCode,
            'result_info' => $resultInfo,
            'evidence' => $evidence,
        ];
        if (!$this->virtualNotifyEncrypted) {
            if ($format === 'xml') {
                return response($payload, 200, [], 'xml')->getContent();
            }
            return helper::jsonEncode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        // 安全模式下，非空业务回包也必须使用同一 EncodingAESKey 加密；否则微信无法
        // 验证回包并可能将问询视为超时。Encryptor::encrypt() 返回 XML 包装，JSON
        // 推送再将同一字段集转换回 JSON。
        $config = $this->getVirtualMessagePushConfig();
        $appId = (string)(WxappSettingModel::getConfigBasic()['app_id'] ?? '');
        $token = trim((string)($config['message_push_token'] ?? ''));
        $aesKey = trim((string)($config['message_push_encoding_aes_key'] ?? ''));
        if ($appId === '' || $token === '' || $aesKey === '' || $this->virtualNotifyNonce === '') {
            throwError('虚拟支付安全模式回包配置不完整');
        }
        $plain = $format === 'xml'
            ? response($payload, 200, [], 'xml')->getContent()
            : helper::jsonEncode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $timestamp = time();
        $encryptor = new WechatEncryptor($appId, $token, $aesKey);
        $encryptedXml = $encryptor->encrypt($plain, $this->virtualNotifyNonce, $timestamp);
        if ($format === 'xml') {
            return $encryptedXml;
        }
        $encryptedPayload = (array)(helper::xmlToArray($encryptedXml) ?: []);
        if (empty($encryptedPayload['Encrypt']) || empty($encryptedPayload['MsgSignature'])) {
            throwError('虚拟支付安全模式回包构建失败');
        }
        return helper::jsonEncode($encryptedPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * 从多种可能字段命名中取第一个非空字符串。
     * @param array $payload
     * @param array<int, string> $keys
     * @return string
     */
    private function getFirstString(array $payload, array $keys): string
    {
        foreach ($keys as $key) {
            if (isset($payload[$key]) && (string)$payload[$key] !== '') {
                return (string)$payload[$key];
            }
        }
        return '';
    }

    /**
     * 记录虚拟支付退款链路观测日志，便于对齐本地申请、Apple问询、微信退款通知三段式状态。
     * @param string $stage
     * @param array $context
     * @return void
     */
    private function logVirtualRefundObservation(string $stage, array $context = []): void
    {
        Log::append('Notify-virtualPayment-refundObservation', array_merge([
            'stage' => $stage,
        ], $context));
    }

    /**
     * 构建虚拟支付推送回包
     * @param int $errCode
     * @param string $errMsg
     * @param string $format
     * @return string
     */
    private function buildVirtualNotifyResponse(int $errCode, string $errMsg, string $format): string
    {
        $payload = ['ErrCode' => $errCode, 'ErrMsg' => $errMsg];
        if ($format === 'xml') {
            return response($payload, 200, [], 'xml')->getContent();
        }
        return helper::jsonEncode($payload);
    }

    /**
     * 获取当前异步请求参数中的交易订单号
     * @param string $method 支付方式
     * @param string $wxapyVersion 微信支付版本号 v2或v3
     * @return string|null
     */
    private function getOutTradeNo(string $method, string $wxapyVersion = 'v2'): ?string
    {
        if ($method === PaymentMethodEnum::WECHAT) {
            if ($wxapyVersion === 'v2') {
                $xml = \file_get_contents('php://input');
                $data = helper::xmlToArray($xml);
                return $data['out_trade_no'];
            }
            if ($wxapyVersion === 'v3') {

            }
        }
        if ($method === PaymentMethodEnum::ALIPAY) {
            return \request()->post('out_trade_no');
        }
        return null;
    }

    /**
     * 获取第三方交易记录
     * @param string $method 支付方式
     * @param string $wxapyVersion 微信支付版本号 v2或v3
     * @return PaymentTradeModel|null
     * @throws BaseException
     */
    private function getTradeInfo(string $method, string $wxapyVersion = 'v2'): ?PaymentTradeModel
    {
        // 获取第三方交易记录
        $outTradeNo = $this->getOutTradeNo($method, $wxapyVersion);
        return PaymentTradeModel::detailByOutTradeNo($outTradeNo);
    }

    /**
     * 获取支付模块
     * @param PaymentTradeModel $tradeInfo 第三方交易记录
     * @return Driver|null
     * @throws BaseException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    private function getPayment(PaymentTradeModel $tradeInfo): ?Driver
    {
        // 获取支付方式的配置信息
        $options = $this->getPaymentConfig($tradeInfo['pay_method'], $tradeInfo['client'], $tradeInfo['store_id']);
        // 构建支付模块
        return PaymentFacade::store($tradeInfo['pay_method'])->setOptions($options, $tradeInfo['client']);
    }

    /**
     * 获取支付方式的配置信息
     * @param string $method 支付方式
     * @param string $client 下单客户端
     * @return mixed
     * @throws BaseException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    private function getPaymentConfig(string $method, string $client, int $storeId = null)
    {
        $PaymentModel = new PaymentModel;
        $templateInfo = $PaymentModel->getPaymentInfo($method, $client, $storeId);
        return $templateInfo['template']['config'][$method];
    }
}
