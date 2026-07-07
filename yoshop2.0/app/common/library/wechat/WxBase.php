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

use think\facade\Cache;
use cores\traits\ErrorTrait;
use app\common\library\helper;
use cores\exception\BaseException;

/**
 * 微信api基类
 * Class wechat
 * @package app\library
 */
class WxBase
{
    use ErrorTrait;

    private const STABLE_ACCESS_TOKEN_URI = 'https://api.weixin.qq.com/cgi-bin/stable_token';

    protected string $appId;
    protected string $appSecret;

    /**
     * 构造函数
     * WxBase constructor.
     * @param $appId
     * @param $appSecret
     */
    public function __construct($appId = null, $appSecret = null)
    {
        $this->setConfig($appId, $appSecret);
    }

    protected function setConfig($appId = null, $appSecret = null)
    {
        !empty($appId) && $this->appId = $appId;
        !empty($appSecret) && $this->appSecret = $appSecret;
    }

    /**
     * 获取access_token
     * 优先使用 stable_token，避免多个进程/服务刷新普通 access_token 时产生
     * "access_token is invalid or not latest" 竞态。
     *
     * @return mixed
     * @throws \cores\exception\BaseException
     */
    protected function getAccessToken(bool $forceRefresh = false)
    {
        $cacheKey = $this->appId . '@stable_access_token';
        if ($forceRefresh) {
            Cache::delete($cacheKey);
        }
        if ($cached = Cache::get($cacheKey)) {
            return $cached;
        }

        $response = $this->requestStableAccessToken($forceRefresh);
        if (!isset($response['access_token']) || trim((string)$response['access_token']) === '') {
            throwError('stable_access_token获取失败，微信返回中缺少 access_token');
        }

        $ttl = max(60, (int)($response['expires_in'] ?? 7200) - 200);
        log_record([
            'name' => '获取stable_access_token',
            'url' => self::STABLE_ACCESS_TOKEN_URI,
            'appId' => $this->appId,
            'force_refresh' => $forceRefresh ? 1 : 0,
            'result' => helper::jsonEncode([
                'expires_in' => (int)($response['expires_in'] ?? 0),
                'hint' => 'token body omitted from log',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        Cache::set($cacheKey, (string)$response['access_token'], $ttl);
        return (string)$response['access_token'];
    }

    /**
     * 强制刷新 access_token
     * @return string
     * @throws \cores\exception\BaseException
     */
    protected function refreshAccessToken(): string
    {
        return (string)$this->getAccessToken(true);
    }

    /**
     * 判断微信返回是否属于 access_token 失效/非最新态
     * @param array $response
     * @return bool
     */
    protected function isInvalidAccessTokenResponse(array $response): bool
    {
        $errCode = (int)($response['errcode'] ?? 0);
        $errMsg = strtolower((string)($response['errmsg'] ?? ''));
        if ($errCode === 40001) {
            return true;
        }
        if ($errMsg === '') {
            return false;
        }
        return str_contains($errMsg, 'access_token is invalid or not latest')
            || str_contains($errMsg, 'invalid credential')
            || str_contains($errMsg, 'could get access_token by getstableaccesstoken');
    }

    /**
     * @param bool $forceRefresh
     * @return array
     * @throws \cores\exception\BaseException
     */
    private function requestStableAccessToken(bool $forceRefresh = false): array
    {
        $payload = helper::jsonEncode([
            'grant_type' => 'client_credential',
            'appid' => $this->appId,
            'secret' => $this->appSecret,
            'force_refresh' => $forceRefresh,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $result = $this->post(self::STABLE_ACCESS_TOKEN_URI, $payload);
        $response = (array)$this->jsonDecode($result);
        if (array_key_exists('errcode', $response) && (int)$response['errcode'] !== 0) {
            throwError("stable_access_token获取失败，错误信息：{$result}");
        }
        return $response;
    }

    /**
     * 模拟GET请求 HTTPS的页面
     * @param string $url 请求地址
     * @param array $data
     * @return string $result
     * @throws \cores\exception\BaseException
     */
    protected function get(string $url, array $data = [])
    {
        // 处理query参数
        if (!empty($data)) {
            $url = $url . '?' . http_build_query($data);
        }
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        $result = curl_exec($ch);
        if ($result === false) {
            throwError(curl_error($ch));
        }
        curl_close($ch);
        return $result;
    }

    /**
     * 模拟POST请求
     * @param string $url 请求地址
     * @param mixed $data 请求数据
     * @param false $useCert 是否引入微信支付证书
     * @param array $sslCert 证书路径
     * @return mixed|bool|string
     * @throws \cores\exception\BaseException
     */
    protected function post(string $url, $data = [], bool $useCert = false, array $sslCert = [])
    {
        $header = ['Content-type: application/json;'];
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        if ($useCert) {
            // 设置证书：cert 与 key 分别属于两个.pem文件
            curl_setopt($ch, CURLOPT_SSLCERTTYPE, 'PEM');
            curl_setopt($ch, CURLOPT_SSLCERT, $sslCert['certPem']);
            curl_setopt($ch, CURLOPT_SSLKEYTYPE, 'PEM');
            curl_setopt($ch, CURLOPT_SSLKEY, $sslCert['keyPem']);
        }
        $result = curl_exec($ch);
        if ($result === false) {
            throwError(curl_error($ch));
        }
        curl_close($ch);
        return $result;
    }

    /**
     * 模拟POST请求 [第二种方式, 用于兼容微信api]
     * @param $url
     * @param array $data
     * @return mixed
     * @throws \cores\exception\BaseException
     */
    protected function post2($url, array $data = [])
    {
        $header = ['Content-Type: application/x-www-form-urlencoded'];
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        $result = curl_exec($ch);
        if ($result === false) {
            throwError(curl_error($ch));
        }
        curl_close($ch);
        return $result;
    }

    /**
     * 数组转json
     * @param $data
     * @return string
     */
    protected function jsonEncode($data): string
    {
        return helper::jsonEncode($data, JSON_UNESCAPED_UNICODE);
    }

    /**
     * json转数组
     * @param $json
     * @return mixed
     */
    protected function jsonDecode($json)
    {
        return helper::jsonDecode($json);
    }
}
