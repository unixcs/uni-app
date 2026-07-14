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

namespace app\api\service\cashier;

use app\api\model\Goods as ApiGoodsModel;
use app\api\model\Order as OrderModel;
use app\api\model\Payment as PaymentModel;
use app\api\model\PaymentTrade as PaymentTradeModel;
use app\api\model\wxapp\Setting as WxappSettingModel;
use app\api\service\User as UserService;
use app\api\service\Order as OrderService;
use app\api\service\order\PaySuccess as OrderPaySuccessService;
use app\api\service\order\source\Factory as OrderSourceFactory;
use app\api\service\passport\Party as PartyService;
use app\common\service\BaseService;
use app\common\enum\Client as ClientEnum;
use app\common\enum\OrderType as OrderTypeEnum;
use app\common\enum\order\PayStatus as PayStatusEnum;
use app\common\enum\payment\Method as PaymentMethodEnum;
use app\common\enum\payment\trade\ChannelClass as ChannelClassEnum;
use app\common\enum\payment\trade\TradeStatus as TradeStatusEnum;
use app\common\enum\Setting as SettingEnum;
use app\common\model\Goods as CommonGoodsModel;
use app\common\model\UserOauth as UserOauthModel;
use app\common\model\store\Setting as StoreSettingModel;
use app\common\library\helper;
use app\common\library\wechat\VirtualPayment as WechatVirtualPayment;
use app\common\library\payment\Facade as PaymentFacade;
use think\facade\Cache;
use think\facade\Db;
use cores\exception\BaseException;
use think\facade\Log;

/**
 * 订单付款服务类
 * Class Payment
 * @package app\api\controller
 */
class Payment extends BaseService
{
    private const VIRTUAL_PAYMENT_URI = 'requestVirtualPayment';
    private const VIRTUAL_ORDER_STATUS_CREATED = 0;
    private const VIRTUAL_ORDER_STATUS_PAYING = 1;
    private const VIRTUAL_ORDER_STATUS_PAID = 2;
    private const VIRTUAL_ORDER_STATUS_PAID_PENDING_DELIVERY = 3;
    private const VIRTUAL_ORDER_STATUS_CLOSED = 6;
    private const VIRTUAL_PAYMENT_STATE_CREATED = 'created';
    private const VIRTUAL_PAYMENT_STATE_CONFIRMING = 'confirming';
    private const VIRTUAL_PAYMENT_STATE_PAID = 'paid';

    // 提示信息
    private string $message = '';

    // 订单ID
    private int $orderId;

    // 订单信息
    private ?OrderModel $orderInfo;

    // 当前订单商品（补齐虚拟支付识别字段后）
    private ?array $resolvedOrderGoods = null;

    // 支付方式 (微信支付、支付宝)
    private string $method;

    // 下单的客户端
    private string $client;

    // 主动查单是否仍处于待收口状态
    private bool $pendingTradeQuery = false;

    /**
     * 设置支付的订单ID
     * @param int $orderId 订单ID
     * @return $this
     */
    public function setOrderId(int $orderId): Payment
    {
        $this->orderId = $orderId;
        return $this;
    }

    /**
     * 设置当前支付方式
     * @param string $method 支付方式
     * @return $this
     */
    public function setMethod(string $method): Payment
    {
        $this->method = $method;
        return $this;
    }

    /**
     * 设置下单的客户端
     * @param string $client 客户端
     * @return $this
     */
    public function setClient(string $client): Payment
    {
        $this->client = $client;
        return $this;
    }

    /**
     * 获取支付订单的信息
     * @return array
     * @throws BaseException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function orderInfo(): array
    {
        // 当期用户信息
        $userInfo = UserService::getCurrentLoginUser(true);
        // 根据指定客户端获取可用的支付方式
        $PaymentModel = new PaymentModel;
        $methods = $PaymentModel->getMethodsByClient($this->client);
        // 获取结算订单信息
        $OrderModel = new OrderModel;
        $orderInfo = $OrderModel->getUnpaidOrderDetail($this->orderId);
        $this->traceVirtualPaymentAttempt('order_info', [
            'user_id' => (int)($userInfo['user_id'] ?? 0),
            'pay_status' => (int)($orderInfo['pay_status'] ?? 0),
            'order_status' => (int)($orderInfo['order_status'] ?? 0),
        ], $orderInfo);
        return [
            'order' => $orderInfo,
            'personal' => $userInfo,
            'paymentMethods' => $methods
        ];
    }

    /**
     * 确认订单支付事件
     * @param array $extra 附加数据
     * @return array[]
     * @throws BaseException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function orderPay(array $extra = []): array
    {
        // 获取订单信息
        $this->orderInfo = OrderModel::getDetail($this->orderId);
        $this->traceVirtualPaymentAttempt('order_pay_entry', [
            'extra_has_login_code' => trim((string)($extra['loginCode'] ?? '')) !== '',
            'extra_keys' => array_keys($extra),
            'client_runtime' => $this->sanitizeClientRuntimeContext($extra['runtimeContext'] ?? null),
        ]);
        try {
            // 虚拟支付的“查旧尝试 -> 微信下单 -> 本地落交易”必须按订单串行化。
            // 使用 MySQL advisory lock，避免在远程微信请求期间持有数据库事务。
            if ($this->requiresVirtualPayment()) {
                return $this->orderPayWithVirtualCreateLock($extra);
            }
            return $this->executeOrderPay($extra, false);
        } catch (\Throwable $e) {
            $this->traceVirtualPaymentAttempt('order_pay_exception', [
                'exception_class' => get_class($e),
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * 执行一次支付创建/复用决策。
     * @param array $extra
     * @param bool $requiresVirtualPayment
     * @return array
     */
    private function executeOrderPay(array $extra, bool $requiresVirtualPayment): array
    {
        // 订单支付事件
        $this->orderPayEvent();
        // 虚拟支付创建新交易前，必须先确认同一订单最近一次尝试的结果
        $payment = $requiresVirtualPayment
            ? $this->resolveExistingVirtualPaymentAttempt($extra)
            : null;
        // 没有需要继续收口的旧交易时，才构建新的第三方支付请求
        $payment = $payment ?: $this->unifiedorder($extra);
        $this->traceVirtualPaymentAttempt('unifiedorder_result', [
            'provider' => (string)($payment['provider'] ?? ''),
            'platform' => (string)($payment['platform'] ?? ''),
            'out_trade_no' => (string)($payment['out_trade_no'] ?? ''),
            'env' => isset($payment['env']) ? (int)$payment['env'] : null,
            'product_id' => (string)($payment['product_id'] ?? ''),
        ]);
        // 只有新创建的支付参数才写入交易记录；paid/confirming 复用的是旧交易
        $paymentState = (string)($payment['state'] ?? self::VIRTUAL_PAYMENT_STATE_CREATED);
        if ($paymentState === self::VIRTUAL_PAYMENT_STATE_CREATED) {
            $this->recordPaymentTrade($payment);
            $this->traceVirtualPaymentAttempt('trade_recorded', [
                'provider' => (string)($payment['provider'] ?? ''),
                'platform' => (string)($payment['platform'] ?? ''),
                'out_trade_no' => (string)($payment['out_trade_no'] ?? ''),
            ]);
        } else {
            $this->traceVirtualPaymentAttempt('repeat_guard_reused_trade', [
                'state' => $paymentState,
                'out_trade_no' => (string)($payment['out_trade_no'] ?? ''),
            ]);
        }
        return compact('payment');
    }

    /**
     * 使用订单级 MySQL advisory lock 串行化虚拟支付创建。
     * 锁获取失败时按结果未知处理，绝不继续创建第二笔交易。
     * @param array $extra
     * @return array
     */
    private function orderPayWithVirtualCreateLock(array $extra): array
    {
        $orderId = (int)($this->orderInfo['order_id'] ?? 0);
        $lockName = sprintf('vp:create:%d:%d', $this->getStoreId(), $orderId);
        $connection = Db::connect();
        $acquired = false;
        try {
            $rows = $connection->query(
                'SELECT GET_LOCK(:lock_name, :lock_timeout) AS acquired',
                ['lock_name' => $lockName, 'lock_timeout' => 5],
                true
            );
            $acquired = (int)($rows[0]['acquired'] ?? 0) === 1;
            if (!$acquired) {
                $tradeInfo = $this->getLatestVirtualTradeSnapshotByOrderId($orderId);
                $this->traceVirtualPaymentAttempt('repeat_guard_lock_unavailable', [
                    'existing_trade_id' => (int)($tradeInfo['trade_id'] ?? 0),
                    'existing_out_trade_no' => (string)($tradeInfo['out_trade_no'] ?? ''),
                ]);
                $payment = $this->buildVirtualPaymentStateResponse(
                    self::VIRTUAL_PAYMENT_STATE_CONFIRMING,
                    $tradeInfo,
                    '支付请求正在处理中，请稍后返回本页确认，切勿重复付款'
                );
                return compact('payment');
            }

            $this->traceVirtualPaymentAttempt('repeat_guard_lock_acquired');
            // 等锁期间订单/交易状态可能变化，进入临界区后必须重新读取并重新校验。
            $this->orderInfo = OrderModel::getDetail($orderId);
            $this->resolvedOrderGoods = null;
            return $this->executeOrderPay($extra, true);
        } finally {
            if ($acquired) {
                try {
                    $connection->query('SELECT RELEASE_LOCK(:lock_name) AS released', ['lock_name' => $lockName], true);
                    $this->traceVirtualPaymentAttempt('repeat_guard_lock_released');
                } catch (\Throwable $releaseError) {
                    $this->traceVirtualPaymentAttempt('repeat_guard_lock_release_error', [
                        'exception_class' => get_class($releaseError),
                        'message' => $releaseError->getMessage(),
                    ]);
                }
            }
        }
    }

    /**
     * 查询订单是否支付成功 (仅限第三方支付订单)
     * @param string $outTradeNo 商户订单号
     * @return bool
     * @throws BaseException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function tradeQuery(string $outTradeNo): bool
    {
        $this->pendingTradeQuery = false;
        if ($this->isVirtualPaymentMethod($outTradeNo)) {
            return $this->virtualTradeQuery($outTradeNo);
        }
        // 判断支付方式是否合法
        if (!in_array($this->method, [PaymentMethodEnum::WECHAT, PaymentMethodEnum::ALIPAY])) {
            return false;
        }
        // 获取支付方式的配置信息
        $options = $this->getPaymentConfig();
        // 构建支付模块
        $Payment = PaymentFacade::store($this->method)->setOptions($options, $this->client);
        // 执行第三方支付查询API
        $result = $Payment->tradeQuery($outTradeNo);
        // 订单支付成功事件
        if (!empty($result) && $result['paySuccess']) {
            // 获取第三方交易记录信息
            $tradeInfo = PaymentTradeModel::detailByOutTradeNo($outTradeNo);
            // 订单支付成功事件
            $this->orderPaySuccess($tradeInfo['order_no'], $tradeInfo['trade_id'], $result);
        }
        // 返回订单状态
        return $result ? $result['paySuccess'] : false;
    }

    /**
     * 记录第三方交易信息
     * @param array $payment 第三方支付数据
     * @throws BaseException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    private function recordPaymentTrade(array $payment): void
    {
        if (!in_array($this->method, [PaymentMethodEnum::BALANCE])) {
            PaymentTradeModel::record(
                $this->orderInfo,
                $this->method,
                $this->client,
                OrderTypeEnum::ORDER,
                $payment
            );
        }
    }

    /**
     * 返回消息提示
     * @return mixed
     */
    public function getMessage()
    {
        return $this->message;
    }

    /**
     * 当前查单是否仍在待收口
     * @return bool
     */
    public function isPendingTradeQuery(): bool
    {
        return $this->pendingTradeQuery;
    }

    /**
     * 订单支付事件
     * @return void
     * @throws BaseException
     */
    private function orderPayEvent(): void
    {
        // 验证当前订单是否允许支付
        $this->checkOrderStatusOnPay();
        // 余额支付
        if ($this->method == PaymentMethodEnum::BALANCE) {
            $this->orderPaySuccess($this->orderInfo['order_no']);
        }
    }

    /**
     * 验证当前订单是否允许支付
     * @throws BaseException
     */
    private function checkOrderStatusOnPay()
    {
        $orderSource = OrderSourceFactory::getFactory($this->orderInfo['order_source']);
        if (!$orderSource->checkOrderStatusOnPay($this->orderInfo)) {
            throwError($orderSource->getError() ?: '当前订单状态不允许支付');
        }
    }

    /**
     * 构建第三方支付请求的参数
     * @param array $extra 附加数据
     * @return array
     * @throws BaseException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    private function unifiedorder(array $extra = []): array
    {
        // 判断支付方式是否合法
        if (!in_array($this->method, [PaymentMethodEnum::WECHAT, PaymentMethodEnum::ALIPAY])) {
            return [];
        }
        if ($this->requiresVirtualPayment()) {
            $shouldUseVirtualPayment = $this->shouldUseVirtualPayment();
            $this->traceVirtualPaymentAttempt('virtual_route_check', [
                'requires_virtual' => true,
                'should_use_virtual' => $shouldUseVirtualPayment,
            ]);
            if (!$shouldUseVirtualPayment) {
                throwError('该服务商品要求使用微信虚拟支付，请先完成虚拟支付配置');
            }
            return $this->virtualUnifiedorder($extra);
        }
        $this->traceVirtualPaymentAttempt('virtual_route_check', [
            'requires_virtual' => false,
            'should_use_virtual' => false,
        ]);
        // 生成第三方交易订单号 (并非主订单号)
        $outTradeNo = OrderService::createOrderNo();
        // 获取支付方式的配置信息
        $options = $this->getPaymentConfig();
        // 整理下单接口所需的附加数据
        $extra = $this->extraAsUnify($extra);
        // 构建支付模块
        $Payment = PaymentFacade::store($this->method)->setOptions($options, $this->client);
        // 执行第三方支付下单API
        if (!$Payment->unify($outTradeNo, (string)$this->orderInfo['pay_price'], $extra)) {
            throwError('第三方支付下单API调用失败');
        }
        // 返回客户端需要的支付参数
        return $Payment->getUnifyResult();
    }

    /**
     * 生成虚拟支付参数
     * @return array
     * @throws BaseException
     */
    private function virtualUnifiedorder(array $extra = []): array
    {
        if ($this->client !== ClientEnum::MP_WEIXIN) {
            throwError('虚拟支付仅支持微信小程序端');
        }
        $resolvedGoods = $this->getResolvedOrderGoods();
        if (\count($resolvedGoods) !== 1) {
            throwError('虚拟支付商品仅支持单商品立即购买');
        }
        $goods = $this->getVirtualPaymentGoods();
        if (!$goods) {
            throwError('该服务商品虚拟支付配置不完整，请检查商品绑定的 productId、平台价格快照和服务商品设置');
        }
        $buyQuantity = (int)($goods['total_num'] ?? 0);
        if ($buyQuantity < 1) {
            throwError('虚拟支付订单商品数量异常，无法发起支付');
        }
        $goodsPriceFen = (int)($goods['vp_price_snapshot'] ?? 0);
        $orderPayPriceFen = (int)round((float)($this->orderInfo['pay_price'] ?? 0) * 100);
        if ($goodsPriceFen <= 0) {
            throwError('虚拟支付商品价格快照异常，无法发起支付');
        }
        if ($goodsPriceFen * $buyQuantity !== $orderPayPriceFen) {
            throwError('虚拟支付下单金额与订单金额不一致，请刷新后重试');
        }
        $config = $this->getVirtualPaymentConfig();
        $outTradeNo = OrderService::createOrderNo();
        $env = (int)$config['env'];
        $payerOpenid = (string)($extra['openid'] ?? $this->getWechatOpenid());
        $signDataPayload = [
            'offerId' => (string)$config['offer_id'],
            'buyQuantity' => $buyQuantity,
            'env' => $env,
            'currencyType' => 'CNY',
            'productId' => (string)$goods['vp_product_id'],
            'goodsPrice' => $goodsPriceFen,
            'outTradeNo' => $outTradeNo,
            'attach' => (string)$this->orderInfo['order_no'],
        ];
        $snapshotPayload = $signDataPayload + [
            'payer_openid' => $payerOpenid,
        ];
        $signData = helper::jsonEncode($signDataPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $sessionKey = $this->resolveWechatSessionKey($extra);
        if ($sessionKey === '') {
            throwError('当前登录态缺少 session_key，请重新登录微信小程序后再支付');
        }
        $appKey = $env === CommonGoodsModel::VP_ENV_SANDBOX
            ? (string)$config['sandbox_app_key']
            : (string)$config['production_app_key'];
        if ($appKey === '') {
            throwError('虚拟支付 AppKey 未配置');
        }
        $paySig = hash_hmac('sha256', self::VIRTUAL_PAYMENT_URI . '&' . $signData, $appKey);
        $signature = hash_hmac('sha256', $signData, $sessionKey);
        $this->traceVirtualPaymentAttempt('virtual_payload_built', [
            'offer_id' => (string)$config['offer_id'],
            'out_trade_no' => $outTradeNo,
            'env' => $env,
            'app_key_scope' => $env === CommonGoodsModel::VP_ENV_SANDBOX ? 'sandbox' : 'production',
            'product_id' => (string)$goods['vp_product_id'],
            'goods_price' => $goodsPriceFen,
            'buy_quantity' => $buyQuantity,
            'pay_price_fen' => $orderPayPriceFen,
            'sign_data' => $signData,
            'session_key_present' => $sessionKey !== '',
            'client_runtime' => $this->sanitizeClientRuntimeContext($extra['runtimeContext'] ?? null),
        ]);
        return [
            'provider' => 'virtual',
            'platform' => 'wechat_virtual',
            'state' => self::VIRTUAL_PAYMENT_STATE_CREATED,
            'method' => $this->method,
            'mode' => 'short_series_goods',
            'out_trade_no' => $outTradeNo,
            'outTradeNo' => $outTradeNo,
            'env' => $env,
            'product_id' => (string)$goods['vp_product_id'],
            'goods_price' => $goodsPriceFen,
            'buy_quantity' => $buyQuantity,
            'attach' => (string)$this->orderInfo['order_no'],
            'signData' => $signData,
            'paySig' => $paySig,
            'signature' => $signature,
            'payload_snapshot' => helper::jsonEncode($snapshotPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }

    /**
     * 获取支付方式的配置信息
     * @return mixed
     * @throws BaseException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    private function getPaymentConfig()
    {
        $PaymentModel = new PaymentModel;
        $templateInfo = $PaymentModel->getPaymentInfo($this->method, $this->client, $this->getStoreId());
        return $templateInfo['template']['config'][$this->method];
    }

    /**
     * 整理下单接口所需的附加数据
     * @param array $extra
     * @return array
     * @throws BaseException
     */
    private function extraAsUnify(array $extra = []): array
    {
        // 微信支付时需要的附加数据
        if ($this->method === PaymentMethodEnum::WECHAT) {
            // 微信小程序端需要openid
            if (in_array($this->client, [ClientEnum::MP_WEIXIN])) {
                $extra['openid'] = $this->getWechatOpenid();
            }
        }
        // 支付宝支付时需要的附加数据
        if ($this->method === PaymentMethodEnum::ALIPAY) {
        }
        return $extra;
    }

    /**
     * 获取微信端的用户openid(仅微信小程序)
     * @return null
     * @throws BaseException
     */
    private function getWechatOpenid()
    {
        if (in_array($this->client, [ClientEnum::MP_WEIXIN])) {
            // 当前登录用户信息
            $useInfo = UserService::getCurrentLoginUser(true);
            if (!$useInfo['currentOauth'] || empty($useInfo['currentOauth']['oauth_id'])) {
                throwError('很抱歉，您当前不存在openid 无法发起微信支付');
            }
            // 当前第三方用户标识
            return $useInfo['currentOauth']['oauth_id'];
        }
        return null;
    }


    /**
     * 优先复用付款时留下的 openid 证据，避免用户重新绑定后查单失败。
     * @param mixed $tradeInfo
     * @return string
     */
    private function resolveVirtualTradeOpenid($tradeInfo): string
    {
        if ($tradeInfo instanceof PaymentTradeModel) {
            $tradeInfo = $tradeInfo->toArray();
        }
        if (!\is_array($tradeInfo)) {
            $tradeInfo = [];
        }
        $snapshot = PaymentTradeModel::decodePayloadSnapshot((string)($tradeInfo['payload_snapshot'] ?? ''));
        $candidates = [
            (string)($snapshot['payer_openid'] ?? ''),
            (string)($snapshot['query_order']['payload']['openid'] ?? ''),
            (string)($snapshot['timer_query_order']['payload']['openid'] ?? ''),
            (string)($snapshot['refund_notify']['payload']['OpenId'] ?? ''),
            (string)($snapshot['pay_notify']['payload']['OpenId'] ?? ''),
        ];
        foreach ($candidates as $candidate) {
            if ($candidate !== '') {
                return $candidate;
            }
        }
        return (string)($this->getWechatOpenid() ?? '');
    }

    /**
     * 获取微信小程序 session_key
     * @return string
     * @throws BaseException
     */
    private function getWechatSessionKey(): string
    {
        $token = $this->request->header('Access-Token');
        $cache = $token ? (array)Cache::get($token) : [];
        $sessionKey = (string)($cache['mp_weixin_session_key'] ?? '');
        $userId = (int)UserService::getCurrentLoginUserId(true);
        $oauth = UserOauthModel::getOauth($userId, ClientEnum::MP_WEIXIN);
        $oauthId = (string)($oauth['oauth_id'] ?? '');
        if ($oauthId !== '') {
            $this->assertWechatOauthIdentityUnique($oauthId, $userId);
        }
        if ($sessionKey !== '') {
            return $sessionKey;
        }
        return UserOauthModel::getSessionKeyByUserId($userId, ClientEnum::MP_WEIXIN);
    }

    /**
     * 解析当前支付使用的 session_key
     * 优先使用本次 wx.login 换取的新 session_key，避免旧登录态导致虚拟支付 signature 失效
     * @param array $extra
     * @return string
     * @throws BaseException
     */
    private function resolveWechatSessionKey(array $extra = []): string
    {
        $loginCode = trim((string)($extra['loginCode'] ?? ''));
        if ($loginCode !== '') {
            $refreshed = $this->refreshWechatSessionKeyByCode($loginCode);
            if ($refreshed !== '') {
                return $refreshed;
            }
        }
        return $this->getWechatSessionKey();
    }

    /**
     * 使用当前 wx.login code 刷新 session_key，并回写到 oauth / token 缓存
     * @param string $loginCode
     * @return string
     * @throws BaseException
     */
    private function refreshWechatSessionKeyByCode(string $loginCode): string
    {
        if ($this->client !== ClientEnum::MP_WEIXIN) {
            return '';
        }
        $wxSession = PartyService::getMpWxSession($loginCode);
        $sessionKey = (string)($wxSession['session_key'] ?? '');
        $openid = (string)($wxSession['openid'] ?? '');
        if ($sessionKey === '' || $openid === '') {
            return '';
        }
        $userId = (int)UserService::getCurrentLoginUserId(true);
        $this->assertWechatOauthIdentityUnique($openid, $userId);
        $currentOpenid = (string)UserOauthModel::getOauthIdByUserId($userId, ClientEnum::MP_WEIXIN);
        if ($currentOpenid !== '' && $currentOpenid !== $openid) {
            throwError('当前微信登录态已变更，请重新进入小程序后再支付');
        }
        UserOauthModel::updateBase([
            'oauth_id' => $openid,
            'session_key' => $sessionKey,
            'update_time' => time(),
        ], [
            'user_id' => $userId,
            'oauth_type' => ClientEnum::MP_WEIXIN,
            'is_delete' => 0,
        ]);
        $token = (string)$this->request->header('Access-Token');
        if ($token !== '') {
            $cache = (array)Cache::get($token);
            if (!empty($cache)) {
                $cache['mp_weixin_session_key'] = $sessionKey;
                Cache::set($token, $cache, 86400 * 30);
            }
        }
        return $sessionKey;
    }

    /**
     * 同一个小程序 openid 只能映射到一个商城用户，否则支付身份会歧义
     * @param string $openid
     * @param int $userId
     */
    private function assertWechatOauthIdentityUnique(string $openid, int $userId): void
    {
        if ($openid === '' || $userId <= 0) {
            return;
        }
        $bindings = Db::name('user_oauth')
            ->where('oauth_type', '=', ClientEnum::MP_WEIXIN)
            ->where('oauth_id', '=', $openid)
            ->where('is_delete', '=', 0)
            ->distinct(true)
            ->column('user_id');
        $bindings = array_values(array_unique(array_map('intval', (array)$bindings)));
        if (count($bindings) <= 1) {
            return;
        }
        sort($bindings);
        $message = sprintf(
            '当前微信身份在商城内绑定了多个用户(%s)，请先清理重复绑定后再支付',
            implode(',', $bindings)
        );
        throwError($message);
    }

    /**
     * 是否应走虚拟支付
     * @return bool
     */
    private function shouldUseVirtualPayment(): bool
    {
        if (!$this->requiresVirtualPayment()) {
            return false;
        }
        $config = $this->getVirtualPaymentConfig();
        return (int)($config['enabled'] ?? 0) === 1;
    }

    /**
     * 当前订单是否要求走虚拟支付
     * @return bool
     */
    private function requiresVirtualPayment(): bool
    {
        if ($this->method !== PaymentMethodEnum::WECHAT) {
            return false;
        }
        if ($this->client !== ClientEnum::MP_WEIXIN) {
            return false;
        }
        $resolvedGoods = $this->getResolvedOrderGoods();
        $existingVirtualTrade = $this->getLatestVirtualTradeSnapshotByOrderId((int)($this->orderInfo['order_id'] ?? 0));
        $hasValidVirtualGoods = false;
        $hasInvalidVirtualFlag = false;
        foreach ($resolvedGoods as $goods) {
            if (CommonGoodsModel::isVirtualPaymentServiceGoods($goods)) {
                $hasValidVirtualGoods = true;
                continue;
            }
            if (CommonGoodsModel::isVirtualPaymentEnabled($goods)) {
                $hasInvalidVirtualFlag = true;
            }
        }
        if (($hasValidVirtualGoods || $existingVirtualTrade) && \count($resolvedGoods) !== 1) {
            throwError('虚拟支付商品仅支持单商品立即购买');
        }
        if (!$hasValidVirtualGoods && $hasInvalidVirtualFlag) {
            throwError('该服务商品虚拟支付配置不完整，请检查商品绑定的 productId、平台价格快照和服务商品设置');
        }
        return $hasValidVirtualGoods;
    }

    /**
     * 获取命中的虚拟支付商品
     * @return mixed|null
     */
    private function getVirtualPaymentGoods()
    {
        foreach ($this->getResolvedOrderGoods() as $goods) {
            if (CommonGoodsModel::isVirtualPaymentServiceGoods($goods)) {
                return $goods;
            }
        }
        return null;
    }

    /**
     * 补齐订单商品中的虚拟支付识别字段。
     * 订单商品快照本身不保留 vp_* 配置，因此支付阶段需要优先复用既有虚拟支付尝试，
     * 其次再回补当前商品配置，避免待支付订单因商品配置后续变更而切换支付通道。
     * @return array
     */
    private function getResolvedOrderGoods(): array
    {
        if ($this->resolvedOrderGoods !== null) {
            return $this->resolvedOrderGoods;
        }
        $orderGoodsList = (array)($this->orderInfo['goods'] ?? []);
        $existingVirtualTrade = \count($orderGoodsList) === 1
            ? $this->getLatestVirtualTradeSnapshotByOrderId((int)($this->orderInfo['order_id'] ?? 0))
            : [];
        $resolved = [];
        $GoodsModel = new ApiGoodsModel;
        foreach ($orderGoodsList as $goods) {
            $item = \is_object($goods) && method_exists($goods, 'toArray')
                ? $goods->toArray()
                : (array)$goods;
            if (isset($item[0]) && \count($item) === 1) {
                $item = \is_object($item[0]) && method_exists($item[0], 'toArray')
                    ? $item[0]->toArray()
                    : (array)$item[0];
            }
            $linkedGoods = [];
            if (isset($item['goods'])) {
                $linkedGoods = \is_object($item['goods']) && method_exists($item['goods'], 'toArray')
                    ? $item['goods']->toArray()
                    : (array)$item['goods'];
            }
            if ($linkedGoods) {
                $item['goods_type'] = $linkedGoods['goods_type'] ?? ($item['goods_type'] ?? 0);
                $item['delivery_type'] = $linkedGoods['delivery_type'] ?? ($item['delivery_type'] ?? []);
                $item['vp_enabled'] = (int)($linkedGoods['vp_enabled'] ?? ($item['vp_enabled'] ?? 0));
                $item['vp_product_id'] = (string)($linkedGoods['vp_product_id'] ?? ($item['vp_product_id'] ?? ''));
                $item['vp_price_snapshot'] = (int)($linkedGoods['vp_price_snapshot'] ?? ($item['vp_price_snapshot'] ?? 0));
                if (isset($linkedGoods['is_service_package'])) {
                    $item['is_service_package'] = (bool)$linkedGoods['is_service_package'];
                }
            }
            if ($existingVirtualTrade && $this->shouldReuseVirtualTradeSnapshot($item, $existingVirtualTrade)) {
                $item['vp_enabled'] = 1;
                $item['vp_product_id'] = (string)$existingVirtualTrade['product_id'];
                $item['vp_price_snapshot'] = (int)$existingVirtualTrade['goods_price'];
            }
            $needsHydrate = !isset($item['vp_enabled'], $item['vp_product_id'], $item['vp_price_snapshot'], $item['is_service_package']);
            if ($needsHydrate && (int)($item['goods_id'] ?? 0) > 0) {
                try {
                    $currentGoods = $GoodsModel->getBasic((int)$item['goods_id'], false);
                    $currentGoods = \is_object($currentGoods) && method_exists($currentGoods, 'toArray')
                        ? $currentGoods->toArray()
                        : (array)$currentGoods;
                    $item['vp_enabled'] = (int)($currentGoods['vp_enabled'] ?? $item['vp_enabled'] ?? 0);
                    $item['vp_product_id'] = (string)($currentGoods['vp_product_id'] ?? $item['vp_product_id'] ?? '');
                    $item['vp_price_snapshot'] = (int)($currentGoods['vp_price_snapshot'] ?? $item['vp_price_snapshot'] ?? 0);
                    $item['is_service_package'] = (bool)($currentGoods['is_service_package'] ?? $item['is_service_package'] ?? false);
                } catch (\Throwable $e) {
                    // 保持原始快照，后续按普通支付分支继续处理
                }
            }
            $resolved[] = $item;
        }
        return $this->resolvedOrderGoods = $resolved;
    }

    /**
     * 获取当前订单最近一次虚拟支付尝试的快照。
     * @param int $orderId
     * @return array
     */
    private function getLatestVirtualTradeSnapshotByOrderId(int $orderId): array
    {
        if ($orderId <= 0) {
            return [];
        }
        $trade = Db::name('payment_trade')
            ->where('order_id', '=', $orderId)
            ->where('store_id', '=', $this->getStoreId())
            ->where('platform', '=', 'wechat_virtual')
            ->where('product_id', '<>', '')
            ->where('goods_price', '>', 0)
            ->order('trade_id', 'desc')
            ->find();
        return \is_array($trade) ? $trade : [];
    }

    /**
     * 待支付订单一旦已有虚拟支付交易尝试，就优先复用那次支付快照，避免后续商品配置漂移。
     * @param array $goods
     * @param array $trade
     * @return bool
     */
    private function shouldReuseVirtualTradeSnapshot(array $goods, array $trade): bool
    {
        if ((int)($goods['goods_id'] ?? 0) <= 0) {
            return false;
        }
        if ((int)($trade['order_id'] ?? 0) !== (int)($this->orderInfo['order_id'] ?? 0)) {
            return false;
        }
        if ((int)($goods['vp_enabled'] ?? 0) === 1
            && trim((string)($goods['vp_product_id'] ?? '')) !== ''
            && (int)($goods['vp_price_snapshot'] ?? 0) > 0) {
            return false;
        }
        if (trim((string)($trade['product_id'] ?? '')) === '' || (int)($trade['goods_price'] ?? 0) <= 0) {
            return false;
        }
        $snapshot = PaymentTradeModel::decodePayloadSnapshot((string)($trade['payload_snapshot'] ?? ''));
        $snapshotQuantity = (int)($snapshot['buyQuantity'] ?? 0);
        if ($snapshotQuantity > 0 && (int)($goods['total_num'] ?? 0) > 0 && $snapshotQuantity !== (int)$goods['total_num']) {
            return false;
        }
        return true;
    }

    /**
     * 创建新虚拟支付尝试前，先收口同一订单最近一次交易。
     * 返回 null 才表示旧交易已明确终态未支付，可以创建新 out_trade_no。
     * @return array|null
     */
    private function resolveExistingVirtualPaymentAttempt(array $extra = []): ?array
    {
        $orderId = (int)($this->orderInfo['order_id'] ?? 0);
        $tradeInfo = $this->getLatestVirtualTradeSnapshotByOrderId($orderId);
        $this->traceVirtualPaymentAttempt('repeat_guard_entry', [
            'existing_trade_id' => (int)($tradeInfo['trade_id'] ?? 0),
            'existing_out_trade_no' => (string)($tradeInfo['out_trade_no'] ?? ''),
            'existing_trade_state' => isset($tradeInfo['trade_state']) ? (int)$tradeInfo['trade_state'] : null,
        ]);
        if (empty($tradeInfo)) {
            $this->traceVirtualPaymentAttempt('repeat_guard_no_existing_trade');
            return null;
        }
        if ((int)($tradeInfo['user_id'] ?? 0) !== (int)($this->orderInfo['user_id'] ?? 0)) {
            throwError('支付交易用户与当前订单不一致');
        }
        $outTradeNo = (string)($tradeInfo['out_trade_no'] ?? '');
        if ($outTradeNo === '') {
            $this->traceVirtualPaymentAttempt('repeat_guard_query_error', [
                'reason' => 'missing_out_trade_no',
                'existing_trade_id' => (int)($tradeInfo['trade_id'] ?? 0),
            ]);
            return $this->buildVirtualPaymentStateResponse(
                self::VIRTUAL_PAYMENT_STATE_CONFIRMING,
                $tradeInfo,
                '上一笔支付结果暂时无法确认，请稍后重试'
            );
        }

        $previousCancelledOutTradeNo = trim((string)($extra['previousCancelledOutTradeNo'] ?? ''));
        $isExplicitlyCancelled = $previousCancelledOutTradeNo !== '' && hash_equals($outTradeNo, $previousCancelledOutTradeNo);
        $tradeState = (int)($tradeInfo['trade_state'] ?? TradeStatusEnum::UNPAID);
        if ($tradeState === TradeStatusEnum::SUCCESS) {
            $this->convergeExistingSuccessfulTrade($tradeInfo);
            $this->traceVirtualPaymentAttempt('repeat_guard_existing_paid', [
                'existing_trade_id' => (int)$tradeInfo['trade_id'],
                'existing_out_trade_no' => $outTradeNo,
                'source' => 'local_trade_state',
            ]);
            return $this->buildVirtualPaymentStateResponse(
                self::VIRTUAL_PAYMENT_STATE_PAID,
                $tradeInfo,
                '恭喜您，订单已付款成功'
            );
        }
        if ($tradeState === TradeStatusEnum::REFUND) {
            $this->traceVirtualPaymentAttempt('repeat_guard_existing_confirming', [
                'existing_trade_id' => (int)$tradeInfo['trade_id'],
                'existing_out_trade_no' => $outTradeNo,
                'reason' => 'refunded_trade',
            ]);
            return $this->buildVirtualPaymentStateResponse(
                self::VIRTUAL_PAYMENT_STATE_CONFIRMING,
                $tradeInfo,
                '该订单已有完成后退款的支付记录，请勿重复支付'
            );
        }
        if ($tradeState === TradeStatusEnum::CLOSED) {
            $this->traceVirtualPaymentAttempt('repeat_guard_existing_closed', [
                'existing_trade_id' => (int)$tradeInfo['trade_id'],
                'existing_out_trade_no' => $outTradeNo,
                'source' => 'local_trade_state',
            ]);
            return null;
        }

        try {
            if ($this->virtualTradeQuery($outTradeNo)) {
                $latestTrade = PaymentTradeModel::detailByOutTradeNo($outTradeNo);
                $latestTrade = \is_object($latestTrade) && method_exists($latestTrade, 'toArray')
                    ? $latestTrade->toArray()
                    : (array)$latestTrade;
                $this->traceVirtualPaymentAttempt('repeat_guard_existing_paid', [
                    'existing_trade_id' => (int)($latestTrade['trade_id'] ?? 0),
                    'existing_out_trade_no' => $outTradeNo,
                    'source' => 'remote_query',
                ]);
                return $this->buildVirtualPaymentStateResponse(
                    self::VIRTUAL_PAYMENT_STATE_PAID,
                    $latestTrade,
                    '恭喜您，订单已付款成功'
                );
            }
            $latestTrade = PaymentTradeModel::detailByOutTradeNo($outTradeNo);
            $latestTrade = \is_object($latestTrade) && method_exists($latestTrade, 'toArray')
                ? $latestTrade->toArray()
                : (array)$latestTrade;
            $remoteStatus = $this->getLatestVirtualQueryStatus($latestTrade);
            if ($isExplicitlyCancelled && \in_array($remoteStatus, [
                self::VIRTUAL_ORDER_STATUS_CREATED,
                self::VIRTUAL_ORDER_STATUS_PAYING,
            ], true)) {
                PaymentTradeModel::updateBase([
                    'trade_state' => TradeStatusEnum::CLOSED,
                ], (int)$latestTrade['trade_id']);
                $this->traceVirtualPaymentAttempt('repeat_guard_existing_closed', [
                    'existing_trade_id' => (int)$latestTrade['trade_id'],
                    'existing_out_trade_no' => $outTradeNo,
                    'source' => 'explicit_client_cancel',
                    'remote_status' => $remoteStatus,
                ]);
                return null;
            }
            if ($remoteStatus === self::VIRTUAL_ORDER_STATUS_CLOSED) {
                PaymentTradeModel::updateBase([
                    'trade_state' => TradeStatusEnum::CLOSED,
                ], (int)$latestTrade['trade_id']);
                $this->traceVirtualPaymentAttempt('repeat_guard_existing_closed', [
                    'existing_trade_id' => (int)$latestTrade['trade_id'],
                    'existing_out_trade_no' => $outTradeNo,
                    'source' => 'remote_query',
                    'remote_status' => $remoteStatus,
                ]);
                return null;
            }
            $this->traceVirtualPaymentAttempt('repeat_guard_existing_confirming', [
                'existing_trade_id' => (int)($latestTrade['trade_id'] ?? 0),
                'existing_out_trade_no' => $outTradeNo,
                'remote_status' => $remoteStatus,
                'pending' => $this->pendingTradeQuery,
            ]);
            return $this->buildVirtualPaymentStateResponse(
                self::VIRTUAL_PAYMENT_STATE_CONFIRMING,
                $latestTrade,
                $this->getError() ?: '上一笔支付结果仍在确认中，请勿重复支付'
            );
        } catch (\Throwable $e) {
            if ($isExplicitlyCancelled && str_contains($e->getMessage(), '尚未创建这笔虚拟支付订单')) {
                PaymentTradeModel::updateBase([
                    'trade_state' => TradeStatusEnum::CLOSED,
                ], (int)$tradeInfo['trade_id']);
                $this->traceVirtualPaymentAttempt('repeat_guard_existing_closed', [
                    'existing_trade_id' => (int)$tradeInfo['trade_id'],
                    'existing_out_trade_no' => $outTradeNo,
                    'source' => 'explicit_client_cancel_not_created',
                ]);
                return null;
            }
            $this->traceVirtualPaymentAttempt('repeat_guard_query_error', [
                'existing_trade_id' => (int)($tradeInfo['trade_id'] ?? 0),
                'existing_out_trade_no' => $outTradeNo,
                'exception_class' => get_class($e),
                'message' => $e->getMessage(),
            ]);
            return $this->buildVirtualPaymentStateResponse(
                self::VIRTUAL_PAYMENT_STATE_CONFIRMING,
                $tradeInfo,
                '上一笔支付结果暂时无法确认，请稍后重试，切勿重复付款'
            );
        }
    }

    /**
     * 将已有成功交易幂等收口到订单主状态。
     * @param array $tradeInfo
     */
    private function convergeExistingSuccessfulTrade(array $tradeInfo): void
    {
        $latestOrder = OrderModel::getDetail((int)$tradeInfo['order_id']);
        if (!empty($latestOrder) && (int)($latestOrder['pay_status'] ?? 0) === PayStatusEnum::SUCCESS) {
            $this->message = '恭喜您，订单已付款成功';
            return;
        }
        $this->orderPaySuccess(
            (string)$tradeInfo['order_no'],
            (int)$tradeInfo['trade_id'],
            [
                'tradeNo' => (string)($tradeInfo['trade_no'] ?? $tradeInfo['out_trade_no'] ?? ''),
                'outTradeNo' => (string)($tradeInfo['out_trade_no'] ?? ''),
            ]
        );
    }

    /**
     * 从最近一次主动/定时查单快照读取微信订单状态。
     * @param array $tradeInfo
     * @return int|null
     */
    private function getLatestVirtualQueryStatus(array $tradeInfo): ?int
    {
        $snapshot = PaymentTradeModel::decodePayloadSnapshot((string)($tradeInfo['payload_snapshot'] ?? ''));
        $queryOrder = (array)($snapshot['query_order'] ?? []);
        $timerQueryOrder = (array)($snapshot['timer_query_order'] ?? []);
        $queryAt = (int)($queryOrder['queried_at'] ?? 0);
        $timerQueryAt = (int)($timerQueryOrder['queried_at'] ?? 0);
        $source = $timerQueryAt > $queryAt ? $timerQueryOrder : $queryOrder;
        return isset($source['status']) ? (int)$source['status'] : null;
    }

    /**
     * 构建不拉起新收银台的兼容响应。
     * @param string $state
     * @param array $tradeInfo
     * @param string $message
     * @return array
     */
    private function buildVirtualPaymentStateResponse(string $state, array $tradeInfo, string $message): array
    {
        $outTradeNo = (string)($tradeInfo['out_trade_no'] ?? '');
        $this->message = $message;
        return [
            'provider' => 'virtual',
            'platform' => 'wechat_virtual',
            'method' => $this->method,
            'state' => $state,
            'out_trade_no' => $outTradeNo,
            'outTradeNo' => $outTradeNo,
            'message' => $message,
        ];
    }

    /**
     * 获取虚拟支付配置
     * @return array
     */
    private function getVirtualPaymentConfig(): array
    {
        return StoreSettingModel::getItem(SettingEnum::VIRTUAL_PAYMENT, $this->getStoreId());
    }

    /**
     * 是否为虚拟支付交易号
     * @param string $outTradeNo
     * @return bool
     */
    private function isVirtualPaymentMethod(string $outTradeNo): bool
    {
        $tradeInfo = PaymentTradeModel::detailByOutTradeNo($outTradeNo);
        return (string)($tradeInfo['platform'] ?? '') === 'wechat_virtual';
    }

    /**
     * 虚拟支付订单主动查单
     * @param string $outTradeNo
     * @return bool
     * @throws BaseException
     */
    private function virtualTradeQuery(string $outTradeNo): bool
    {
        $tradeInfo = PaymentTradeModel::detailByOutTradeNo($outTradeNo);
        $currentUserId = (int)UserService::getCurrentLoginUserId(true);
        if ((int)($tradeInfo['user_id'] ?? 0) !== $currentUserId) {
            throwError('无权查询该支付订单');
        }
        $orderInfo = OrderModel::getDetail((int)$tradeInfo['order_id']);
        $isOrderPaid = !empty($orderInfo)
            && (int)($orderInfo['pay_status'] ?? 0) === PayStatusEnum::SUCCESS;
        $channelClass = (int)($tradeInfo['channel_class'] ?? ChannelClassEnum::UNKNOWN);
        // 支付成功事实与渠道分类事实必须分别收口。支付通知可能先把订单置为已支付，
        // 但通知报文不含 order_type；此时仍需 query_order 补齐 Android / Apple 分类。
        if ($isOrderPaid && $channelClass !== ChannelClassEnum::UNKNOWN) {
            return true;
        }
        $config = $this->getVirtualPaymentConfig();
        if ((int)($config['enabled'] ?? 0) !== 1) {
            throwError('虚拟支付未开启，无法查询订单状态');
        }
        $wxapp = WxappSettingModel::getConfigBasic($this->getStoreId());
        $appId = (string)($wxapp['app_id'] ?? '');
        $appSecret = (string)($wxapp['app_secret'] ?? '');
        if ($appId === '' || $appSecret === '') {
            throwError('小程序 AppID 或 AppSecret 未配置，无法调用微信 access_token 查单接口');
        }
        $env = (int)($tradeInfo['env'] ?? $config['env'] ?? 0);
        $appKey = $env === CommonGoodsModel::VP_ENV_SANDBOX
            ? (string)($config['sandbox_app_key'] ?? '')
            : (string)($config['production_app_key'] ?? '');
        if ($appKey === '') {
            throwError('虚拟支付 AppKey 未配置');
        }
        $openid = $this->resolveVirtualTradeOpenid($tradeInfo);
        if ($openid === '') {
            throwError('缺少用户 openid，无法查询虚拟支付订单状态');
        }
        $payload = [
            'openid' => $openid,
            'env' => $env,
            'order_id' => $outTradeNo,
        ];
        $payment = new WechatVirtualPayment($appId, $appSecret, $appKey);
        $result = $payment->queryOrder($payload);
        $errCode = (int)($result['errcode'] ?? -1);
        if ($errCode !== 0) {
            throwError($this->buildVirtualTradeQueryRemoteErrorMessage($result));
        }
        if (!isset($result['order']) || !\is_array($result['order'])) {
            throwError('虚拟支付查单返回结构异常');
        }
        $order = (array)($result['order'] ?? []);
        $status = (int)($order['status'] ?? -1);
        PaymentTradeModel::mergePayloadSnapshot((int)$tradeInfo['trade_id'], [
            'query_order' => [
                'queried_at' => time(),
                'payload' => $payload,
                'result' => $result,
                'status' => $status,
            ],
        ]);
        if ($status === self::VIRTUAL_ORDER_STATUS_PAID || $status === self::VIRTUAL_ORDER_STATUS_PAID_PENDING_DELIVERY) {
            $paymentData = [
                'tradeNo' => (string)($order['wxpay_order_id'] ?? $order['channel_order_id'] ?? $outTradeNo),
                'outTradeNo' => $outTradeNo,
                'orderStatus' => $status,
                'raw' => $result,
            ];
            $this->orderPaySuccess((string)$tradeInfo['order_no'], (int)$tradeInfo['trade_id'], $paymentData);
            return true;
        }
        $latestOrderInfo = OrderModel::getDetail((int)$tradeInfo['order_id']);
        if (!empty($latestOrderInfo) && (int)($latestOrderInfo['pay_status'] ?? 0) === PayStatusEnum::SUCCESS) {
            return true;
        }
        // 只有上游明确 CLOSED 才能判定终态未支付；未知状态必须 fail closed。
        $this->pendingTradeQuery = $status !== self::VIRTUAL_ORDER_STATUS_CLOSED;
        $this->setError($this->buildVirtualTradeQueryPendingMessage(
            \is_array($tradeInfo) ? $tradeInfo : $tradeInfo->toArray(),
            $status
        ));
        return false;
    }

    /**
     * 生成虚拟支付主动查单的用户提示
     * @param array $tradeInfo
     * @param int $status
     * @return string
     */
    private function buildVirtualTradeQueryPendingMessage(array $tradeInfo, int $status): string
    {
        $outTradeNo = (string)($tradeInfo['out_trade_no'] ?? '');
        $productId = (string)($tradeInfo['product_id'] ?? '');
        $env = (int)($tradeInfo['env'] ?? 0);
        $tradeHint = $outTradeNo !== '' ? "（交易号:{$outTradeNo}）" : '';
        if ($status === self::VIRTUAL_ORDER_STATUS_CLOSED) {
            if ($env === CommonGoodsModel::VP_ENV_SANDBOX) {
                $goodsHint = $productId !== '' ? "、道具 {$productId} 发布状态" : '';
                return '微信虚拟支付订单已被上游关闭' . $tradeHint
                    . '，当前更像沙箱侧拦截或关单。请优先核验 Android 开发版/体验版、测试账号资格'
                    . $goodsHint
                    . '、OfferID/商户主体一致性与商户限制后重试';
            }
            return '微信虚拟支付订单已被上游关闭' . $tradeHint . '，请核验道具发布状态、商户主体配置和商户收款限制后重试';
        }
        if (\in_array($status, [self::VIRTUAL_ORDER_STATUS_CREATED, self::VIRTUAL_ORDER_STATUS_PAYING], true)) {
            return '微信虚拟支付仍在处理中' . $tradeHint . '，请稍后点击“确认支付”重新查单';
        }
        return sprintf('微信虚拟支付未完成支付%s，当前微信状态=%d', $tradeHint, $status);
    }

    /**
     * 生成虚拟支付查单异常的用户提示
     * @param array $result
     * @return string
     */
    private function buildVirtualTradeQueryRemoteErrorMessage(array $result): string
    {
        $errCode = (int)($result['errcode'] ?? -1);
        $errMsg = (string)($result['errmsg'] ?? '');
        if ($errCode === 268490002 && str_contains($errMsg, '数据不存在')) {
            return '微信侧尚未创建这笔虚拟支付订单，当前更像开发者工具 automator/预览态拦截，或支付拉起未真正到达微信支付层，请退出自动化会话后重试';
        }
        if ($errMsg !== '') {
            return $errMsg;
        }
        return sprintf('虚拟支付查单失败（errcode=%d）', $errCode);
    }

    /**
     * 订单支付成功事件
     * @param string $orderNo 当前订单号
     * @param int|null $tradeId 第三方交易记录ID
     * @param array $paymentData 第三方支付成功返回的数据
     * @return void
     * @throws BaseException
     */
    private function orderPaySuccess(string $orderNo, ?int $tradeId = null, array $paymentData = []): void
    {
        // 订单支付成功业务处理
        $service = new OrderPaySuccessService;
        $service->setOrderNo($orderNo)->setMethod($this->method)->setTradeId($tradeId)->setPaymentData($paymentData);
        if (!$service->handle()) {
            throwError($service->getError() ?: '订单支付失败');
        }
        $this->message = '恭喜您，订单已付款成功';
    }

    /**
     * 为微信小程序微信支付链路记录轻量结构化痕迹，帮助区分是否真正走到 orderPay / 虚拟支付分支
     * @param string $stage
     * @param array $payload
     * @param array|null $orderInfo
     */
    private function traceVirtualPaymentAttempt(string $stage, array $payload = [], ?array $orderInfo = null): void
    {
        $method = isset($this->method) ? $this->method : '';
        if ($this->client !== ClientEnum::MP_WEIXIN) {
            return;
        }
        if ($method !== '' && $method !== PaymentMethodEnum::WECHAT) {
            return;
        }
        try {
            $runtimeRoot = rtrim(app()->getRuntimePath(), DIRECTORY_SEPARATOR);
            $dir = $runtimeRoot . DIRECTORY_SEPARATOR . 'codex-evidence' . DIRECTORY_SEPARATOR . 'virtual-payment-attempt-trace';
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            $currentOrder = $orderInfo ?? ($this->orderInfo ? $this->orderInfo->toArray() : null);
            $entry = [
                'at' => date('c'),
                'stage' => $stage,
                'order_id' => (int)($this->orderId ?? 0),
                'order_no' => (string)($currentOrder['order_no'] ?? ''),
                'client' => $this->client,
                'method' => $method,
                'user_id' => (int)UserService::getCurrentLoginUserId(true),
                'headers' => [
                    'referer' => (string)$this->request->header('Referer'),
                    'referer_kind' => $this->classifyWechatRefererKind((string)$this->request->header('Referer')),
                    'user_agent' => (string)$this->request->header('User-Agent'),
                    'x_forwarded_for' => (string)$this->request->header('X-Forwarded-For'),
                    'host' => (string)$this->request->header('Host'),
                    'access_token_prefix' => substr((string)$this->request->header('Access-Token'), 0, 8),
                ],
                'payload' => $payload,
            ];
            $file = $dir . DIRECTORY_SEPARATOR . date('Ymd') . '.jsonl';
            file_put_contents($file, helper::jsonEncode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND);
        } catch (\Throwable $e) {
            // best effort only; never block payment path on diagnostics
        }
    }

    private function sanitizeClientRuntimeContext($runtimeContext): ?array
    {
        if (!\is_array($runtimeContext)) {
            return null;
        }
        $sanitized = [
            'envVersion' => trim((string)($runtimeContext['envVersion'] ?? '')),
            'appId' => trim((string)($runtimeContext['appId'] ?? '')),
            'platform' => trim((string)($runtimeContext['platform'] ?? '')),
            'scene' => isset($runtimeContext['scene']) && $runtimeContext['scene'] !== ''
                ? (int)$runtimeContext['scene']
                : null,
            'sdkVersion' => trim((string)($runtimeContext['sdkVersion'] ?? '')),
            'wechatVersion' => trim((string)($runtimeContext['wechatVersion'] ?? '')),
            'system' => trim((string)($runtimeContext['system'] ?? '')),
        ];
        return array_filter($sanitized, static function ($value) {
            return $value !== null && $value !== '';
        }) ?: null;
    }

    private function classifyWechatRefererKind(string $referer): string
    {
        $normalized = strtolower($referer);
        if ($normalized === '') {
            return 'missing';
        }
        if (strpos($normalized, '/devtools/page-frame.html') !== false) {
            return 'devtools_preview';
        }
        if (preg_match('#/\\d+/page-frame\\.html#', $normalized)) {
            return 'mini_program_runtime';
        }
        return 'other';
    }
}
