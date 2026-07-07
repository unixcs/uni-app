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

namespace app\common\library\wechat;

use app\common\library\helper;

/**
 * 微信虚拟支付服务端API
 */
class VirtualPayment extends WxBase
{
    private const URI_QUERY_ORDER = '/xpay/query_order';
    private const URI_REFUND_ORDER = '/xpay/refund_order';
    private const URI_NOTIFY_PROVIDE_GOODS = '/xpay/notify_provide_goods';

    private string $appKey;

    public function __construct(string $appId, string $appSecret, string $appKey)
    {
        parent::__construct($appId, $appSecret);
        $this->appKey = $appKey;
    }

    /**
     * 查询虚拟支付订单
     * 官方文档：
     * POST /xpay/query_order?access_token=ACCESS_TOKEN&pay_sig=PAY_SIG
     */
    public function queryOrder(array $payload): array
    {
        return $this->signedPost(self::URI_QUERY_ORDER, $payload);
    }

    /**
     * 发起退款任务
     */
    public function refundOrder(array $payload): array
    {
        return $this->signedPost(self::URI_REFUND_ORDER, $payload);
    }

    /**
     * 通知已发货完成
     * 该接口不使用 pay_sig，仅使用 access_token。
     */
    public function notifyProvideGoods(array $payload): array
    {
        $body = helper::jsonEncode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $result = $this->postWithAccessTokenRetry(self::URI_NOTIFY_PROVIDE_GOODS, $body, false);
        return (array)$this->jsonDecode($result);
    }

    private function signedPost(string $uri, array $payload): array
    {
        $body = helper::jsonEncode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $paySig = hash_hmac('sha256', $uri . '&' . $body, $this->appKey);
        $result = $this->postWithAccessTokenRetry($uri, $body, true, $paySig);
        return (array)$this->jsonDecode($result);
    }

    private function postWithAccessTokenRetry(string $uri, string $body, bool $withPaySig, string $paySig = ''): string
    {
        $result = $this->postWithAccessToken($uri, $body, $this->getAccessToken(), $withPaySig, $paySig);
        $decoded = (array)$this->jsonDecode($result);
        if (!$this->isInvalidAccessTokenResponse($decoded)) {
            return $result;
        }
        $result = $this->postWithAccessToken($uri, $body, $this->refreshAccessToken(), $withPaySig, $paySig);
        return $result;
    }

    private function postWithAccessToken(string $uri, string $body, string $accessToken, bool $withPaySig, string $paySig = ''): string
    {
        $query = "?access_token={$accessToken}";
        if ($withPaySig) {
            $query .= "&pay_sig={$paySig}";
        }
        $url = "https://api.weixin.qq.com{$uri}{$query}";
        return $this->post($url, $body);
    }
}
