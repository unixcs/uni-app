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

namespace app\common\service\order;

use app\api\model\wxapp\Setting as WxappSettingModel;
use app\common\model\Payment as PaymentModel;
use app\common\model\PaymentTrade as PaymentTradeModel;
use app\common\model\User as UserModel;
use app\common\model\UserOauth as UserOauthModel;
use app\common\model\Order as OrderModel;
use app\common\model\OrderGoods as OrderGoodsModel;
use app\common\model\OrderRefund as OrderRefundModel;
use app\common\model\store\Setting as StoreSettingModel;
use app\common\model\user\BalanceLog as BalanceLogModel;
use app\common\service\BaseService;
use app\common\service\Order as OrderService;
use app\common\enum\Client as ClientEnum;
use app\common\enum\order\OrderStatus as OrderStatusEnum;
use app\common\enum\order\PayStatus as PayStatusEnum;
use app\common\enum\order\iosRefund\RiskSource as IosRiskSourceEnum;
use app\common\service\order\IosRefundRisk as IosRefundRiskService;
use app\common\enum\order\refund\AuditStatus as RefundAuditStatusEnum;
use app\common\enum\order\refund\RefundStatus as RefundStatusEnum;
use app\common\enum\order\refund\RefundType as RefundTypeEnum;
use app\common\enum\payment\trade\TradeStatus as TradeStatusEnum;
use app\common\enum\payment\trade\ChannelClass as ChannelClassEnum;
use app\common\enum\Setting as SettingEnum;
use app\common\enum\payment\Method as PaymentMethodEnum;
use app\common\enum\user\balanceLog\Scene as SceneEnum;
use app\common\model\Goods as CommonGoodsModel;
use app\common\library\wechat\VirtualPayment as WechatVirtualPayment;
use app\common\library\Log;
use app\common\library\payment\Facade as PaymentFacade;
use app\common\library\helper;
use cores\exception\BaseException;
use think\facade\Db;

/**
 * 订单退款服务类
 * Class Refund
 * @package app\common\service\order
 */
class Refund extends BaseService
{
    private const VIRTUAL_ORDER_STATUS_REFUNDED = 5;
    private const VIRTUAL_ORDER_STATUS_USER_REFUNDED = 8;
    private const VIRTUAL_IOS_APPLE_ORDER_TYPE = 7;

    /**
     * 在创建退款单事务之前收口虚拟支付渠道分类。
     * 支付成功通知不一定携带 order_type，UNKNOWN 交易必须主动查单后再决定
     * 走开发者退款还是 iOS App Store 流程。
     *
     * @param mixed $order
     * @param PaymentTradeModel $tradeInfo
     * @return PaymentTradeModel
     * @throws BaseException
     */
    public function convergeVirtualPaymentChannelForRefund($order, PaymentTradeModel $tradeInfo): PaymentTradeModel
    {
        if ((string)($tradeInfo['platform'] ?? '') !== 'wechat_virtual') {
            return $tradeInfo;
        }

        $bindingMatches = (int)($order['order_id'] ?? 0) > 0
            && (int)($order['trade_id'] ?? 0) === (int)($tradeInfo['trade_id'] ?? 0)
            && (int)($tradeInfo['order_id'] ?? 0) === (int)$order['order_id']
            && (int)($tradeInfo['store_id'] ?? 0) === (int)$order['store_id']
            && (int)($tradeInfo['user_id'] ?? 0) === (int)$order['user_id'];
        if (!$bindingMatches) {
            throwError('支付交易与订单不匹配，无法确认退款渠道');
        }

        $channelClass = (int)($tradeInfo['channel_class'] ?? ChannelClassEnum::UNKNOWN);
        if ($channelClass !== ChannelClassEnum::UNKNOWN) {
            return $tradeInfo;
        }

        $isPaidOrder = (int)($order['pay_status'] ?? 0) === PayStatusEnum::SUCCESS;
        $tradeState = (int)($tradeInfo['trade_state'] ?? TradeStatusEnum::UNPAID);
        $isPaidTrade = in_array($tradeState, [TradeStatusEnum::SUCCESS, TradeStatusEnum::REFUND], true);
        if (!$isPaidOrder || !$isPaidTrade) {
            throwError('支付结果尚未完成，无法确认退款渠道');
        }

        try {
            $queryOrder = $this->queryVirtualRefundState($order, $tradeInfo);
        } catch (\Throwable $e) {
            $this->logVirtualRefundObservation('refund_channel_preflight_query_failed', [
                'trade_id' => (int)$tradeInfo['trade_id'],
                'order_id' => (int)$tradeInfo['order_id'],
                'out_trade_no' => (string)($tradeInfo['out_trade_no'] ?? ''),
                'error' => $e->getMessage(),
            ]);
            throwError('支付渠道确认失败，请稍后重试退款申请');
        }

        $resolvedTrade = PaymentTradeModel::detail((int)$tradeInfo['trade_id']);
        if (empty($resolvedTrade)
            || (int)($resolvedTrade['trade_id'] ?? 0) !== (int)($order['trade_id'] ?? 0)
            || (int)($resolvedTrade['order_id'] ?? 0) !== (int)$order['order_id']
            || (int)($resolvedTrade['store_id'] ?? 0) !== (int)$order['store_id']
            || (int)($resolvedTrade['user_id'] ?? 0) !== (int)$order['user_id']) {
            throwError('支付交易状态已变化，无法确认退款渠道');
        }

        $resolvedClass = (int)($resolvedTrade['channel_class'] ?? ChannelClassEnum::UNKNOWN);
        $orderType = array_key_exists('order_type', $queryOrder)
            ? (int)$queryOrder['order_type']
            : null;
        if ($resolvedClass === ChannelClassEnum::UNKNOWN) {
            $this->logVirtualRefundObservation('refund_channel_preflight_unresolved', [
                'trade_id' => (int)$resolvedTrade['trade_id'],
                'order_id' => (int)$resolvedTrade['order_id'],
                'out_trade_no' => (string)($resolvedTrade['out_trade_no'] ?? ''),
                'query_status' => (int)($queryOrder['status'] ?? -1),
                'order_type' => $orderType,
            ]);
            throwError('支付渠道暂无法确认，请稍后重试退款申请');
        }

        $this->logVirtualRefundObservation('refund_channel_preflight_resolved', [
            'trade_id' => (int)$resolvedTrade['trade_id'],
            'order_id' => (int)$resolvedTrade['order_id'],
            'out_trade_no' => (string)($resolvedTrade['out_trade_no'] ?? ''),
            'channel_class' => $resolvedClass,
            'query_status' => (int)($queryOrder['status'] ?? -1),
            'order_type' => $orderType,
        ]);
        return $resolvedTrade;
    }

    /**
     * 执行订单退款
     * @param mixed $order 订单信息
     * @param string|null $money 退款金额
     * @return bool
     * @throws \cores\exception\BaseException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function handle($order, ?string $money = null, array $context = []): bool
    {
        // 退款金额，如不指定则默认为订单实付款金额
        is_null($money) && $money = (string)$order['pay_price'];
        if ($money == 0) {
            return true;
        }
        // 余额支付退款
        if ($order['pay_method'] === PaymentMethodEnum::BALANCE) {
            return $this->balance($order, $money);
        }
        // 第三方支付退款
        if (in_array($order['pay_method'], [PaymentMethodEnum::WECHAT, PaymentMethodEnum::ALIPAY])) {
            return $this->payment($order, $money, $context);
        }
        return false;
    }

    /**
     * 余额支付退款
     * @param mixed $order 订单信息
     * @param string $money 退款金额
     * @return bool
     */
    private function balance($order, string $money): bool
    {
        if ($money <= 0) {
            return false;
        }
        // 回退用户余额
        UserModel::setIncBalance((int)$order['user_id'], (float)$money);
        // 记录余额明细
        BalanceLogModel::add(SceneEnum::REFUND, [
            'user_id' => $order['user_id'],
            'money' => $money,
        ], ['order_no' => $order['order_no']], $order['store_id']);
        return true;
    }

    /**
     * 第三方支付退款
     * @param mixed $order 订单信息
     * @param string $money 退款金额
     * @return bool
     * @throws BaseException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    private function payment($order, string $money, array $context = []): bool
    {
        // 获取第三方交易记录
        $tradeInfo = $this->resolveTradeInfoForRefund($order, $context);
        if ((string)($tradeInfo['platform'] ?? '') === 'wechat_virtual') {
            return $this->virtualPaymentRefund($order, $tradeInfo, $money, $context);
        }
        // 获取支付方式的配置信息
        $options = $this->getPaymentConfig($order);
        // 构建支付模块
        $Payment = PaymentFacade::store($order['pay_method'])->setOptions($options, $order['platform']);
        // 执行第三方支付下单API
        if (!$Payment->refund($tradeInfo['out_trade_no'], $money, ['totalFee' => (string)$order['pay_price']])) {
            throwError($Payment->getError() ?: '第三方支付退款API调用失败');
        }
        // 将第三方交易记录更新为已退款状态
        $this->updateTradeState((int)$tradeInfo['trade_id']);
        return true;
    }

    /**
     * 微信虚拟支付退款
     * 说明：refund_order 只表示退款任务发起成功，最终状态仍应通过 query_order 收敛。
     */
    private function virtualPaymentRefund($order, PaymentTradeModel $tradeInfo, string $money, array $context = []): bool
    {
        $snapshot = PaymentTradeModel::decodePayloadSnapshot((string)($tradeInfo['payload_snapshot'] ?? ''));
        $virtualRefund = (array)($snapshot['virtual_refund'] ?? []);
        $isDuplicatePayment = !empty($context['duplicate_payment']);
        if ($isDuplicatePayment
            && !empty($virtualRefund['duplicate_payment'])
            && \in_array((string)($virtualRefund['status'] ?? ''), ['processing', 'completed', 'waiting_ios_apple_refund'], true)) {
            return true;
        }
        if ($this->isIosAppleVirtualTrade($tradeInfo, $snapshot)) {
            PaymentTradeModel::mergePayloadSnapshot((int)$tradeInfo['trade_id'], [
                'virtual_refund' => [
                    'status' => 'waiting_ios_apple_refund',
                    'requested_at' => time(),
                    'order_refund_id' => (int)($context['order_refund_id'] ?? 0),
                    'duplicate_payment' => $isDuplicatePayment,
                    'ios_refund_required' => true,
                    'source' => (string)($context['source'] ?? 'refund_apply'),
                    'message' => 'iOS Apple虚拟支付订单不支持开发者主动退款，等待用户在App Store申请退款及Apple/微信退款通知收口',
                ],
            ]);
            $this->logVirtualRefundObservation('ios_refund_waiting_for_app_store', [
                'trade_id' => (int)$tradeInfo['trade_id'],
                'order_id' => (int)$tradeInfo['order_id'],
                'order_no' => (string)($order['order_no'] ?? ''),
                'out_trade_no' => (string)($tradeInfo['out_trade_no'] ?? ''),
                'order_refund_id' => (int)($context['order_refund_id'] ?? 0),
                'source' => (string)($context['source'] ?? 'refund_apply'),
                'duplicate_payment' => $isDuplicatePayment,
                'status' => 'waiting_ios_apple_refund',
            ]);
            return true;
        }
        // 成功虚拟支付渠道尚未确认时必须安全失败：UNKNOWN 不能被当成非 iOS 主动退款。
        // 后台定时查单会继续补偿分类，确认后用户可重试。
        $channelClass = (int)($tradeInfo['channel_class'] ?? ChannelClassEnum::UNKNOWN);
        if ($channelClass === ChannelClassEnum::UNKNOWN) {
            throwError('支付渠道尚在确认中，请稍后重试退款申请');
        }

        $config = StoreSettingModel::getItem(SettingEnum::VIRTUAL_PAYMENT, (int)$order['store_id']);
        if ((int)($config['enabled'] ?? 0) !== 1) {
            throwError('虚拟支付未开启，无法执行退款');
        }
        $wxapp = WxappSettingModel::getConfigBasic((int)$order['store_id']);
        $appId = (string)($wxapp['app_id'] ?? '');
        $appSecret = (string)($wxapp['app_secret'] ?? '');
        if ($appId === '' || $appSecret === '') {
            throwError('小程序 AppID 或 AppSecret 未配置，无法调用微信 access_token 退款接口');
        }
        $env = (int)($tradeInfo['env'] ?? $config['env'] ?? 0);
        $appKey = $env === CommonGoodsModel::VP_ENV_SANDBOX
            ? (string)($config['sandbox_app_key'] ?? '')
            : (string)($config['production_app_key'] ?? '');
        if ($appKey === '') {
            throwError('虚拟支付 AppKey 未配置');
        }
        $openid = $this->resolveVirtualTradeOpenid($order, $tradeInfo, $snapshot);
        if ($openid === '') {
            throwError('缺少用户 openid，无法执行虚拟支付退款');
        }
        $leftFee = (int)round((float)$order['pay_price'] * 100);
        $refundFee = (int)round((float)$money * 100);
        $refundOrderId = 'vrf' . date('YmdHis') . mt_rand(1000, 9999);
        $payload = [
            'openid' => $openid,
            'order_id' => (string)$tradeInfo['out_trade_no'],
            'refund_order_id' => $refundOrderId,
            'left_fee' => $leftFee,
            'refund_fee' => $refundFee,
            'biz_meta' => (string)$order['order_no'],
            'refund_reason' => '3',
            'req_from' => '3',
            'env' => $env,
        ];
        $payment = new WechatVirtualPayment($appId, $appSecret, $appKey);
        $result = $payment->refundOrder($payload);
        if ((int)($result['errcode'] ?? -1) !== 0) {
            throwError((string)($result['errmsg'] ?? '虚拟支付退款发起失败'));
        }
        PaymentTradeModel::mergePayloadSnapshot((int)$tradeInfo['trade_id'], [
            'virtual_refund' => [
                'status' => 'processing',
                'requested_at' => time(),
                'order_refund_id' => (int)($context['order_refund_id'] ?? 0),
                'duplicate_payment' => $isDuplicatePayment,
                'refund_order_id' => $refundOrderId,
                'refund_fee' => $refundFee,
                'left_fee' => $leftFee,
                'request_payload' => $payload,
                'request_result' => $result,
            ],
        ]);
        return true;
    }


    /**
     * 解析退款应绑定的第三方交易记录
     * @param mixed $order
     * @param array $context
     * @return PaymentTradeModel
     * @throws BaseException
     */
    private function resolveTradeInfoForRefund($order, array $context = []): PaymentTradeModel
    {
        $orderRefundId = (int)($context['order_refund_id'] ?? 0);
        $virtualTrade = $this->resolveVirtualTradeForOrder($order, $orderRefundId);
        if (!empty($virtualTrade)) {
            return $virtualTrade;
        }
        $tradeId = (int)($order['trade_id'] ?? 0);
        $tradeId <= 0 && throwError('未找到第三方交易记录');
        return $this->getTradeInfo($tradeId);
    }

    /**
     * 解析订单当前应绑定的虚拟支付交易
     * 优先顺序：已绑定退款单的交易 > 订单当前 trade_id > 最近成功交易 > 最近一次虚拟交易
     * @param mixed $order
     * @param int $orderRefundId
     * @return PaymentTradeModel|null
     */
    private function resolveVirtualTradeForOrder($order, int $orderRefundId = 0): ?PaymentTradeModel
    {
        $orderId = (int)($order['order_id'] ?? 0);
        if ($orderId <= 0) {
            return null;
        }
        $trades = PaymentTradeModel::getVirtualTradesByOrderId($orderId);
        if ($trades->isEmpty()) {
            return null;
        }
        if ($orderRefundId > 0) {
            foreach ($trades as $trade) {
                $snapshot = PaymentTradeModel::decodePayloadSnapshot((string)($trade['payload_snapshot'] ?? ''));
                if ((int)($snapshot['virtual_refund']['order_refund_id'] ?? 0) === $orderRefundId) {
                    return $trade;
                }
            }
        }
        $currentTradeId = (int)($order['trade_id'] ?? 0);
        if ($currentTradeId > 0) {
            foreach ($trades as $trade) {
                if ((int)$trade['trade_id'] === $currentTradeId) {
                    return $trade;
                }
            }
        }
        foreach ($trades as $trade) {
            if ($this->isPaidLikeVirtualTrade($trade)) {
                return $trade;
            }
        }
        return $trades[0] ?? null;
    }

    /**
     * 判断虚拟交易是否已进入本地应认账/应退款的有效态
     * @param PaymentTradeModel $tradeInfo
     * @return bool
     */
    private function isPaidLikeVirtualTrade(PaymentTradeModel $tradeInfo): bool
    {
        if (in_array((int)($tradeInfo['trade_state'] ?? 0), [TradeStatusEnum::SUCCESS, TradeStatusEnum::REFUND], true)) {
            return true;
        }
        $snapshot = PaymentTradeModel::decodePayloadSnapshot((string)($tradeInfo['payload_snapshot'] ?? ''));
        if (!empty($snapshot['pay_notify'])) {
            return true;
        }
        $queryStatus = isset($snapshot['query_order']['status']) ? (int)$snapshot['query_order']['status'] : null;
        $timerQueryStatus = isset($snapshot['timer_query_order']['status']) ? (int)$snapshot['timer_query_order']['status'] : null;
        return in_array($queryStatus, [2, 3], true) || in_array($timerQueryStatus, [2, 3], true);
    }

    /**
     * 定时收敛虚拟支付退款状态
     * @param int $storeId
     * @return array<int, array<string, mixed>>
     */
    public function syncVirtualRefunds(int $storeId): array
    {
        return array_merge($this->syncPendingVirtualRefunds($storeId), $this->syncTradeOnlyVirtualRefunds($storeId));
    }

    /**
     * 高频补偿：只收敛 reviewed+normal 的虚拟支付服务退款单。
     * @param int $storeId
     * @return array<int, array<string, mixed>>
     */
    public function syncPendingVirtualRefunds(int $storeId): array
    {
        $refundList = $this->getPendingVirtualRefundList($storeId);
        $result = [];
        foreach ($refundList as $refund) {
            try {
                $result[] = $this->syncVirtualRefund((int)$refund['order_refund_id']);
            } catch (\Throwable $e) {
                $result[] = [
                    'order_refund_id' => (int)$refund['order_refund_id'],
                    'status' => 'error',
                    'message' => $e->getMessage(),
                ];
            }
        }
        return $result;
    }

    /**
     * 获取待收敛的虚拟支付服务退款单列表。
     * 仅处理 wechat_virtual + 服务退款 + reviewed + normal，避免高频任务扫描无关退款单。
     * @param int $storeId
     * @return \think\Collection
     */
    private function getPendingVirtualRefundList(int $storeId)
    {
        return (new OrderRefundModel)
            ->alias('refund')
            ->field(['refund.order_refund_id'])
            ->where('refund.store_id', '=', $storeId)
            ->where('refund.type', '=', RefundTypeEnum::SERVICE)
            ->where('refund.status', '=', RefundStatusEnum::NORMAL)
            ->where('refund.audit_status', '=', RefundAuditStatusEnum::REVIEWED)
            ->whereExists(function ($query) {
                $query->name('payment_trade')
                    ->whereRaw('order_id = `refund`.order_id')
                    ->where('platform', '=', 'wechat_virtual');
            })
            ->order(['refund.order_refund_id' => 'asc'])
            ->select();
    }

    /**
     * 收敛单笔虚拟支付退款
     * @param int $orderRefundId
     * @return array<string, mixed>
     */
    public function syncVirtualRefund(int $orderRefundId): array
    {
        $refund = OrderRefundModel::detail($orderRefundId);
        if (empty($refund)) {
            return ['order_refund_id' => $orderRefundId, 'status' => 'missing_refund'];
        }
        if ((int)$refund['status'] !== RefundStatusEnum::NORMAL || (int)$refund['audit_status'] !== RefundAuditStatusEnum::REVIEWED) {
            return ['order_refund_id' => $orderRefundId, 'status' => 'skipped'];
        }
        $order = OrderModel::detail((int)$refund['order_id'], ['goods', 'trade']);
        if (empty($order)) {
            return ['order_refund_id' => $orderRefundId, 'status' => 'missing_order'];
        }
        $tradeInfo = $this->resolveVirtualTradeForOrder($order, (int)$refund['order_refund_id']);
        if (empty($tradeInfo)) {
            return ['order_refund_id' => $orderRefundId, 'status' => 'not_virtual'];
        }
        $snapshot = PaymentTradeModel::decodePayloadSnapshot((string)($tradeInfo['payload_snapshot'] ?? ''));
        if ($this->shouldWaitForIosAppleRefund($tradeInfo, $snapshot)) {
            $this->logVirtualRefundObservation('ios_refund_sync_waiting', [
                'trade_id' => (int)$tradeInfo['trade_id'],
                'order_id' => (int)$order['order_id'],
                'order_no' => (string)($order['order_no'] ?? ''),
                'out_trade_no' => (string)($tradeInfo['out_trade_no'] ?? ''),
                'order_refund_id' => $orderRefundId,
                'status' => 'waiting_ios_apple_refund',
                'action' => 'skip_query_order',
            ]);
            return [
                'order_refund_id' => $orderRefundId,
                'order_id' => (int)$order['order_id'],
                'trade_id' => (int)$tradeInfo['trade_id'],
                'status' => 'waiting_ios_apple_refund',
                'message' => 'iOS Apple退款由App Store发起并由Apple决策，等待退款问询/退款结果通知',
            ];
        }
        $queryResult = $this->queryVirtualRefundState($order, $tradeInfo, $refund);
        $status = (int)($queryResult['status'] ?? -1);
        if (!$this->isVirtualRefundCompletedStatus($status)) {
            return [
                'order_refund_id' => $orderRefundId,
                'order_id' => (int)$order['order_id'],
                'trade_id' => (int)$tradeInfo['trade_id'],
                'status' => 'processing',
                'virtual_status' => $status,
            ];
        }
        $this->finalizeVirtualRefund($order, $refund, $tradeInfo, $queryResult);
        return [
            'order_refund_id' => $orderRefundId,
            'order_id' => (int)$order['order_id'],
            'trade_id' => (int)$tradeInfo['trade_id'],
            'status' => 'completed',
            'virtual_status' => $status,
        ];
    }

    /**
     * 退款成功码必须严格为整数 0 或字符串 "0"；其他宽松可转换值全部 fail-closed。
     */
    public static function isSuccessfulVirtualRefundNotify(array $notifyParams): bool
    {
        if (!array_key_exists('RetCode', $notifyParams)) {
            return false;
        }
        $retCode = $notifyParams['RetCode'];
        return $retCode === 0 || $retCode === '0';
    }

    /**
     * 收到退款成功通知后，按交易记录直接收口本地退款状态。
     * 主链路优先信任已验签且成功码严格有效的退款事件，避免再次查单导致卡在处理中。
     * @param int $tradeId
     * @param array $notifyParams
     * @return array<string, mixed>
     */
    public function finalizeVirtualRefundByTrade(int $tradeId, array $notifyParams = []): array
    {
        if (!self::isSuccessfulVirtualRefundNotify($notifyParams)) {
            return ['trade_id' => $tradeId, 'status' => 'untrusted_refund_notify'];
        }
        $tradeInfo = PaymentTradeModel::detail($tradeId);
        if (empty($tradeInfo)) {
            return ['trade_id' => $tradeId, 'status' => 'missing_trade'];
        }
        if ((string)($tradeInfo['platform'] ?? '') !== 'wechat_virtual') {
            return ['trade_id' => $tradeId, 'status' => 'not_virtual'];
        }

        $snapshot = PaymentTradeModel::decodePayloadSnapshot((string)($tradeInfo['payload_snapshot'] ?? ''));
        $virtualRefund = (array)($snapshot['virtual_refund'] ?? []);
        $isDuplicatePayment = !empty($virtualRefund['duplicate_payment']);
        PaymentTradeModel::mergePayloadSnapshot($tradeId, [
            'virtual_refund' => [
                'notify_received_at' => time(),
                'notify_payload' => $notifyParams,
            ],
        ]);

        if ($isDuplicatePayment) {
            $result = $this->finalizeTradeOnlyVirtualRefundFromNotify($tradeInfo, $notifyParams);
            $this->logVirtualRefundObservation('refund_notify_trade_only_completed', [
                'trade_id' => (int)$tradeInfo['trade_id'],
                'order_id' => (int)$tradeInfo['order_id'],
                'out_trade_no' => (string)($tradeInfo['out_trade_no'] ?? ''),
                'status' => (string)($result['status'] ?? ''),
                'mode' => (string)($result['mode'] ?? ''),
                'ios_apple_trade' => $this->isIosAppleVirtualTrade($tradeInfo, $snapshot),
            ]);
            return $result;
        }

        // iOS Apple 最终退款由可信成功通知直接收口；商家曾建议拒绝也不能阻止资金事实落地。
        if ($this->isIosAppleVirtualTrade($tradeInfo, $snapshot)) {
            return $this->finalizeIosAppleRefundSuccess($tradeInfo, $snapshot, $notifyParams);
        }

        $order = OrderModel::detail((int)$tradeInfo['order_id'], ['goods', 'trade']);
        if (empty($order)) {
            return [
                'trade_id' => $tradeId,
                'order_id' => (int)$tradeInfo['order_id'],
                'status' => 'missing_order',
            ];
        }

        $refundResolution = $this->resolveRefundForVirtualTradeNotify($tradeInfo, $snapshot);
        $refund = $refundResolution['refund'] ?? null;
        if (empty($refund) && $this->isIosAppleVirtualTrade($tradeInfo, $snapshot)) {
            $refundResolution = $this->ensureIosAppleRefundForSuccessfulNotify(
                $tradeInfo,
                $order,
                $snapshot,
                $notifyParams
            );
            $refund = $refundResolution['refund'] ?? null;
        }
        if (empty($refund)) {
            $result = [
                'trade_id' => $tradeId,
                'order_id' => (int)$order['order_id'],
                'status' => (string)($refundResolution['status'] ?? 'pending_refund_binding'),
                'message' => (string)($refundResolution['message'] ?? ''),
            ];
            $this->logVirtualRefundObservation('refund_notify_pending_binding', [
                'trade_id' => $tradeId,
                'order_id' => (int)$order['order_id'],
                'out_trade_no' => (string)($tradeInfo['out_trade_no'] ?? ''),
                'status' => (string)($result['status'] ?? ''),
                'message' => (string)($result['message'] ?? ''),
                'ios_apple_trade' => $this->isIosAppleVirtualTrade($tradeInfo, $snapshot),
            ]);
            return $result;
        }

        if (!empty($refundResolution['bound_order_refund_id'])) {
            PaymentTradeModel::mergePayloadSnapshot($tradeId, [
                'virtual_refund' => [
                    'order_refund_id' => (int)$refundResolution['bound_order_refund_id'],
                    'bound_from_notify_at' => time(),
                ],
            ]);
        }

        if ((int)$refund['status'] === RefundStatusEnum::COMPLETED) {
            // 即使退款单已完成，也重新执行幂等收敛，修复历史/乱序数据中订单或交易未同步的情况。
            $this->finalizeVirtualRefund($order, $refund, $tradeInfo, [
                'status' => self::VIRTUAL_ORDER_STATUS_REFUNDED,
                'source' => 'refund_notify',
                'notify_payload' => $notifyParams,
            ]);
            $result = [
                'trade_id' => (int)$tradeInfo['trade_id'],
                'order_id' => (int)$order['order_id'],
                'order_refund_id' => (int)$refund['order_refund_id'],
                'status' => 'completed',
                'mode' => 'already_completed',
            ];
            $this->logVirtualRefundObservation('refund_notify_completed', [
                'trade_id' => (int)$tradeInfo['trade_id'],
                'order_id' => (int)$order['order_id'],
                'order_refund_id' => (int)$refund['order_refund_id'],
                'order_no' => (string)($order['order_no'] ?? ''),
                'out_trade_no' => (string)($tradeInfo['out_trade_no'] ?? ''),
                'status' => (string)($result['status'] ?? ''),
                'mode' => (string)($result['mode'] ?? ''),
                'ios_apple_trade' => $this->isIosAppleVirtualTrade($tradeInfo, $snapshot),
            ]);
            return $result;
        }

        if ((int)$refund['status'] !== RefundStatusEnum::NORMAL
            || (int)$refund['audit_status'] !== RefundAuditStatusEnum::REVIEWED) {
            $result = [
                'trade_id' => (int)$tradeInfo['trade_id'],
                'order_id' => (int)$order['order_id'],
                'order_refund_id' => (int)$refund['order_refund_id'],
                'status' => 'pending_refund_ready',
                'refund_status' => (int)$refund['status'],
                'audit_status' => (int)$refund['audit_status'],
            ];
            $this->logVirtualRefundObservation('refund_notify_pending_refund_ready', [
                'trade_id' => (int)$tradeInfo['trade_id'],
                'order_id' => (int)$order['order_id'],
                'order_refund_id' => (int)$refund['order_refund_id'],
                'out_trade_no' => (string)($tradeInfo['out_trade_no'] ?? ''),
                'status' => (string)($result['status'] ?? ''),
                'refund_status' => (int)($result['refund_status'] ?? -1),
                'audit_status' => (int)($result['audit_status'] ?? -1),
                'ios_apple_trade' => $this->isIosAppleVirtualTrade($tradeInfo, $snapshot),
            ]);
            return $result;
        }

        $this->finalizeVirtualRefund($order, $refund, $tradeInfo, [
            'status' => self::VIRTUAL_ORDER_STATUS_REFUNDED,
            'source' => 'refund_notify',
            'notify_payload' => $notifyParams,
        ]);
        $result = [
            'trade_id' => (int)$tradeInfo['trade_id'],
            'order_id' => (int)$order['order_id'],
            'order_refund_id' => (int)$refund['order_refund_id'],
            'status' => 'completed',
            'mode' => 'refund_notify',
        ];
        $this->logVirtualRefundObservation('refund_notify_completed', [
            'trade_id' => (int)$tradeInfo['trade_id'],
            'order_id' => (int)$order['order_id'],
            'order_refund_id' => (int)$refund['order_refund_id'],
            'order_no' => (string)($order['order_no'] ?? ''),
            'out_trade_no' => (string)($tradeInfo['out_trade_no'] ?? ''),
            'status' => (string)($result['status'] ?? ''),
            'mode' => (string)($result['mode'] ?? ''),
            'ios_apple_trade' => $this->isIosAppleVirtualTrade($tradeInfo, $snapshot),
        ]);
        return $result;
    }

    /**
     * 查询虚拟支付退款状态
     * @param mixed $order
     * @param PaymentTradeModel $tradeInfo
     * @return array
     */
    private function queryVirtualRefundState($order, PaymentTradeModel $tradeInfo, $refund = null): array
    {
        $config = StoreSettingModel::getItem(SettingEnum::VIRTUAL_PAYMENT, (int)$order['store_id']);
        if ((int)($config['enabled'] ?? 0) !== 1) {
            throwError('虚拟支付未开启，无法查询退款状态');
        }
        $wxapp = WxappSettingModel::getConfigBasic((int)$order['store_id']);
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
        $snapshot = PaymentTradeModel::decodePayloadSnapshot((string)($tradeInfo['payload_snapshot'] ?? ''));
        $openid = $this->resolveVirtualTradeOpenid($order, $tradeInfo, $snapshot);
        if ($openid === '') {
            throwError('缺少用户 openid，无法查询虚拟支付退款状态');
        }
        $payload = [
            'openid' => $openid,
            'env' => $env,
            'order_id' => (string)$tradeInfo['out_trade_no'],
        ];
        $payment = new WechatVirtualPayment($appId, $appSecret, $appKey);
        $result = $payment->queryOrder($payload);
        if ((int)($result['errcode'] ?? -1) !== 0) {
            throwError((string)($result['errmsg'] ?? '虚拟支付退款状态查询失败'));
        }
        $orderInfo = (array)($result['order'] ?? []);
        if (empty($orderInfo)) {
            throwError('虚拟支付退款状态返回结构异常');
        }
        $querySnapshot = [
            'last_query_at' => time(),
            'query_payload' => $payload,
            // 分类器读取 virtual_refund.query_result.order_type；完整响应另行保留供审计。
            'query_result' => $orderInfo,
            'query_response' => $result,
            'query_status' => (int)($orderInfo['status'] ?? -1),
        ];
        if (!empty($refund['order_refund_id'])) {
            $querySnapshot['order_refund_id'] = (int)$refund['order_refund_id'];
        }
        PaymentTradeModel::mergePayloadSnapshot((int)$tradeInfo['trade_id'], [
            'virtual_refund' => $querySnapshot,
        ]);
        return $orderInfo;
    }

    /**
     * 完成虚拟支付退款本地收敛
     * @param mixed $order
     * @param mixed $refund
     * @param array $queryResult
     * @return void
     */
    private function finalizeVirtualRefund($order, $refund, PaymentTradeModel $tradeInfo, array $queryResult): void
    {
        Db::transaction(function () use ($order, $refund, $tradeInfo, $queryResult) {
            // 所有退款收敛路径统一按“订单 → 退款单 → 交易”加锁，避免并发通知与自动建档互相反向等待。
            $lockedOrder = (new OrderModel)
                ->where('order_id', '=', (int)$order['order_id'])
                ->lock(true)
                ->find();
            $lockedRefund = (new OrderRefundModel)
                ->where('order_refund_id', '=', (int)$refund['order_refund_id'])
                ->lock(true)
                ->find();
            $lockedTrade = (new PaymentTradeModel)
                ->where('trade_id', '=', (int)$tradeInfo['trade_id'])
                ->lock(true)
                ->find();
            if (empty($lockedOrder) || empty($lockedRefund)) {
                throwError('退款收敛失败，订单或退款单不存在');
            }
            if (empty($lockedTrade) || (string)($lockedTrade['platform'] ?? '') !== 'wechat_virtual') {
                throwError('退款收敛失败，交易记录不存在或不是虚拟支付');
            }
            if ((int)$lockedRefund['order_id'] !== (int)$lockedOrder['order_id']) {
                throwError('退款收敛失败，退款单与订单不匹配');
            }
            if ((int)$lockedRefund['type'] !== RefundTypeEnum::SERVICE) {
                throwError('退款收敛失败，当前退款单不是服务退款单');
            }
            if ((int)$lockedTrade['order_id'] !== (int)$lockedOrder['order_id']) {
                throwError('退款收敛失败，交易记录与订单不匹配');
            }
            $refundAlreadyCompleted = (int)$lockedRefund['status'] === RefundStatusEnum::COMPLETED;
            if (!$refundAlreadyCompleted
                && ((int)$lockedRefund['status'] !== RefundStatusEnum::NORMAL
                    || (int)$lockedRefund['audit_status'] !== RefundAuditStatusEnum::REVIEWED)) {
                return;
            }
            if ((int)$lockedOrder['order_status'] !== OrderStatusEnum::CANCELLED) {
                $goodsList = (new OrderGoodsModel)
                    ->where('order_id', '=', (int)$lockedOrder['order_id'])
                    ->select();
                $lockedOrder->setRelation('goods', $goodsList);
                OrderService::cancelEvent($lockedOrder);
                if ($lockedOrder->save(['order_status' => OrderStatusEnum::CANCELLED]) === false) {
                    throwError('更新订单状态失败');
                }
            }
            if (!$refundAlreadyCompleted) {
                $refundMoney = (string)$lockedRefund['refund_money'];
                if ((float)$refundMoney <= 0) {
                    $refundMoney = OrderModel::getRefundableAmount($lockedOrder);
                }
                if ($lockedRefund->save([
                    'status' => RefundStatusEnum::COMPLETED,
                    'refund_money' => $refundMoney,
                ]) === false) {
                    throwError('更新退款单状态失败');
                }
            }
            if (!PaymentTradeModel::mergePayloadSnapshot((int)$lockedTrade['trade_id'], [
                'virtual_refund' => [
                    'status' => 'completed',
                    'completed_at' => time(),
                    'final_query' => $queryResult,
                ],
            ])) {
                throwError('更新退款交易快照失败');
            }
            if (!$this->updateTradeState((int)$lockedTrade['trade_id'])) {
                throwError('更新退款交易状态失败');
            }
        });
    }


    /**
     * iOS Apple 退款成功的最小安全收口。
     * 不调用通用 cancelEvent，避免本任务引入积分、优惠券或库存冲正。
     * @return array<string, mixed>
     */
    private function finalizeIosAppleRefundSuccess(
        PaymentTradeModel $tradeInfo,
        array $snapshot,
        array $notifyParams
    ): array {
        return Db::transaction(function () use ($tradeInfo, $snapshot, $notifyParams) {
            $order = (new OrderModel)
                ->where('order_id', '=', (int)$tradeInfo['order_id'])
                ->lock(true)
                ->find();
            if (empty($order)) {
                return [
                    'trade_id' => (int)$tradeInfo['trade_id'],
                    'order_id' => (int)$tradeInfo['order_id'],
                    'status' => 'missing_order',
                ];
            }
            $refunds = (new OrderRefundModel)
                ->where('order_id', '=', (int)$order['order_id'])
                ->where('type', '=', RefundTypeEnum::SERVICE)
                ->order(['order_refund_id' => 'asc'])
                ->lock(true)
                ->select();
            $finalTrade = (new PaymentTradeModel)
                ->where('trade_id', '=', (int)$order['trade_id'])
                ->lock(true)
                ->find();
            if (empty($finalTrade)
                || (int)$finalTrade['trade_id'] !== (int)$tradeInfo['trade_id']
                || (int)$finalTrade['order_id'] !== (int)$order['order_id']
                || (int)$finalTrade['store_id'] !== (int)$order['store_id']
                || (int)$finalTrade['user_id'] !== (int)$order['user_id']
                || (string)($finalTrade['platform'] ?? '') !== 'wechat_virtual') {
                return [
                    'trade_id' => (int)$tradeInfo['trade_id'],
                    'order_id' => (int)$order['order_id'],
                    'status' => 'final_trade_binding_conflict',
                    'message' => '退款成功交易不是订单最终iOS交易，未修改订单',
                ];
            }

            $refund = null;
            $boundRefundId = (int)($snapshot['virtual_refund']['order_refund_id'] ?? 0);
            if ($boundRefundId > 0) {
                foreach ($refunds as $candidate) {
                    if ((int)$candidate['order_refund_id'] === $boundRefundId) {
                        $refund = $candidate;
                        break;
                    }
                }
            } elseif ($refunds->count() === 1) {
                $refund = $refunds[0];
            } elseif ($refunds->isEmpty()) {
                $orderGoodsId = (int)(new OrderGoodsModel)
                    ->where('order_id', '=', (int)$order['order_id'])
                    ->order(['order_goods_id' => 'asc'])
                    ->value('order_goods_id');
                if ($orderGoodsId <= 0) {
                    throwError('Apple退款成功通知对应的订单商品不存在');
                }
                $reason = trim((string)($snapshot['ios_refund_query_notify']['payload']['refund_request_reason'] ?? ''));
                $refund = new OrderRefundModel;
                if ($refund->save([
                    'order_goods_id' => $orderGoodsId,
                    'order_id' => (int)$order['order_id'],
                    'user_id' => (int)$order['user_id'],
                    'type' => RefundTypeEnum::SERVICE,
                    'apply_desc' => $reason !== '' ? $reason : 'Apple / App Store 退款成功通知自动建档',
                    'audit_status' => RefundAuditStatusEnum::REVIEWED,
                    'status' => RefundStatusEnum::NORMAL,
                    'refund_money' => OrderModel::getRefundableAmount($order),
                    'store_id' => (int)$order['store_id'],
                ]) === false) {
                    throwError('创建Apple退款跟踪记录失败');
                }
            }

            // 保留 delivery/receipt 历史，只关闭订单并依靠风险状态永久禁止履约。
            if ((int)$order['order_status'] !== OrderStatusEnum::CANCELLED
                && $order->save(['order_status' => OrderStatusEnum::CANCELLED]) === false) {
                throwError('关闭Apple退款订单失败');
            }
            if (!IosRefundRiskService::markRefunded($order, IosRiskSourceEnum::REFUND_NOTIFY_RECOVERY)) {
                throwError('更新Apple退款风险终态失败');
            }
            if (!empty($refund) && (int)$refund['status'] !== RefundStatusEnum::COMPLETED) {
                if ($refund->save([
                    'status' => RefundStatusEnum::COMPLETED,
                    'refund_money' => (float)$refund['refund_money'] > 0
                        ? (string)$refund['refund_money']
                        : OrderModel::getRefundableAmount($order),
                ]) === false) {
                    throwError('完成Apple退款跟踪记录失败');
                }
            }

            $currentSnapshot = PaymentTradeModel::decodePayloadSnapshot((string)($finalTrade['payload_snapshot'] ?? ''));
            $currentSnapshot['virtual_refund'] = array_merge((array)($currentSnapshot['virtual_refund'] ?? []), [
                'status' => 'completed',
                'completed_at' => time(),
                'source' => 'refund_notify',
                'notify_payload' => $notifyParams,
                'order_refund_id' => (int)($refund['order_refund_id'] ?? $boundRefundId),
            ]);
            if ($finalTrade->save([
                'trade_state' => TradeStatusEnum::REFUND,
                'payload_snapshot' => helper::jsonEncode($currentSnapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]) === false) {
                throwError('完成Apple退款交易状态失败');
            }

            $mode = empty($refund) ? 'refund_binding_ambiguous' : 'ios_apple_final';
            if (empty($refund)) {
                $this->logVirtualRefundObservation('refund_notify_refund_binding_ambiguous', [
                    'trade_id' => (int)$finalTrade['trade_id'],
                    'order_id' => (int)$order['order_id'],
                    'refund_count' => $refunds->count(),
                ]);
            }
            return [
                'trade_id' => (int)$finalTrade['trade_id'],
                'order_id' => (int)$order['order_id'],
                'order_refund_id' => (int)($refund['order_refund_id'] ?? 0),
                'status' => 'completed',
                'mode' => $mode,
            ];
        });
    }

    /**
     * 尝试为退款成功通知解析唯一的服务退款单。
     * 规则：优先吃快照里的 order_refund_id；拿不到时，只在当前订单恰好只有一笔 reviewed+normal 服务退款时兜底绑定。
     * @param PaymentTradeModel $tradeInfo
     * @param array $snapshot
     * @return array{status:string,message?:string,refund?:mixed,bound_order_refund_id?:int}
     */
    private function resolveRefundForVirtualTradeNotify(PaymentTradeModel $tradeInfo, array $snapshot): array
    {
        $boundRefundId = (int)(($snapshot['virtual_refund']['order_refund_id'] ?? 0));
        if ($boundRefundId > 0) {
            $refund = OrderRefundModel::detail($boundRefundId);
            if (!empty($refund)) {
                if ((int)$refund['order_id'] !== (int)$tradeInfo['order_id']) {
                    return [
                        'status' => 'invalid_bound_refund',
                        'message' => '快照中的退款单不属于当前订单',
                    ];
                }
                if ((int)$refund['type'] !== RefundTypeEnum::SERVICE) {
                    return [
                        'status' => 'invalid_bound_refund',
                        'message' => '快照中的退款单不是服务退款单',
                    ];
                }
                return [
                    'status' => 'resolved',
                    'refund' => $refund,
                ];
            }
            return [
                'status' => 'missing_bound_refund',
                'message' => '快照中的退款单不存在',
            ];
        }

        $candidates = (new OrderRefundModel)
            ->where('order_id', '=', (int)$tradeInfo['order_id'])
            ->where('type', '=', RefundTypeEnum::SERVICE)
            ->where('audit_status', '=', RefundAuditStatusEnum::REVIEWED)
            ->where('status', '=', RefundStatusEnum::NORMAL)
            ->order(['order_refund_id' => 'asc'])
            ->select();
        $count = $candidates->count();
        if ($count === 1) {
            $refund = $candidates[0];
            return [
                'status' => 'resolved',
                'refund' => $refund,
                'bound_order_refund_id' => (int)$refund['order_refund_id'],
            ];
        }
        if ($count < 1) {
            return [
                'status' => 'pending_refund_binding',
                'message' => '当前订单尚未出现可收口的服务退款单',
            ];
        }
        return [
            'status' => 'ambiguous_refund_binding',
            'message' => '当前订单存在多笔可候选的服务退款单，拒绝猜测绑定',
        ];
    }

    /**
     * Apple 退款成功通知可能先于小程序内的本地退款跟踪记录到达。
     * 上游成功通知是资金事实；此时应在订单锁内复用唯一的进行中记录，或自动建立一条已审核记录，
     * 避免因为本地记录缺失而永远无法把订单、退款单和交易状态收敛为已退款。
     *
     * @param PaymentTradeModel $tradeInfo
     * @param mixed $order
     * @param array $snapshot
     * @param array $notifyParams
     * @return array{status:string,message?:string,refund?:mixed,bound_order_refund_id?:int}
     */
    private function ensureIosAppleRefundForSuccessfulNotify(
        PaymentTradeModel $tradeInfo,
        $order,
        array $snapshot,
        array $notifyParams
    ): array {
        return Db::transaction(function () use ($tradeInfo, $order, $snapshot, $notifyParams) {
            $lockedOrder = (new OrderModel)
                ->where('order_id', '=', (int)$order['order_id'])
                ->lock(true)
                ->find();
            if (empty($lockedOrder)) {
                return [
                    'status' => 'missing_order',
                    'message' => 'Apple退款通知对应的本地订单不存在',
                ];
            }

            $activeRefunds = (new OrderRefundModel)
                ->where('order_id', '=', (int)$lockedOrder['order_id'])
                ->where('type', '=', RefundTypeEnum::SERVICE)
                ->where('status', '=', RefundStatusEnum::NORMAL)
                ->order(['order_refund_id' => 'asc'])
                ->lock(true)
                ->select();
            if ($activeRefunds->count() > 1) {
                return [
                    'status' => 'ambiguous_refund_binding',
                    'message' => '当前订单存在多笔进行中的服务退款单，拒绝猜测绑定',
                ];
            }

            if ($activeRefunds->count() === 1) {
                $refund = $activeRefunds[0];
                if ((int)$refund['audit_status'] !== RefundAuditStatusEnum::REVIEWED) {
                    if ($refund->save([
                        'audit_status' => RefundAuditStatusEnum::REVIEWED,
                        'refuse_desc' => '',
                    ]) === false) {
                        throwError('更新 Apple 退款跟踪记录失败');
                    }
                }
                return [
                    'status' => 'resolved',
                    'refund' => $refund,
                    'bound_order_refund_id' => (int)$refund['order_refund_id'],
                ];
            }

            // 快照可能丢失绑定，但本地退款单已经完成；重复通知必须复用既有事实，不能再建一条。
            $completedRefunds = (new OrderRefundModel)
                ->where('order_id', '=', (int)$lockedOrder['order_id'])
                ->where('type', '=', RefundTypeEnum::SERVICE)
                ->where('status', '=', RefundStatusEnum::COMPLETED)
                ->order(['order_refund_id' => 'asc'])
                ->lock(true)
                ->select();
            if ($completedRefunds->count() > 1) {
                return [
                    'status' => 'ambiguous_completed_refund_binding',
                    'message' => '当前订单存在多笔已完成服务退款单，拒绝猜测绑定',
                ];
            }
            if ($completedRefunds->count() === 1) {
                $refund = $completedRefunds[0];
                return [
                    'status' => 'resolved',
                    'refund' => $refund,
                    'bound_order_refund_id' => (int)$refund['order_refund_id'],
                ];
            }

            $orderGoodsId = (int)(new OrderGoodsModel)
                ->where('order_id', '=', (int)$lockedOrder['order_id'])
                ->order(['order_goods_id' => 'asc'])
                ->value('order_goods_id');
            if ($orderGoodsId <= 0) {
                return [
                    'status' => 'missing_order_goods',
                    'message' => 'Apple退款通知对应的本地订单商品不存在',
                ];
            }

            $queryPayload = (array)($snapshot['ios_refund_query_notify']['payload'] ?? []);
            $reason = trim((string)($queryPayload['refund_request_reason'] ?? ''));
            $refund = new OrderRefundModel();
            if ($refund->save([
                'order_goods_id' => $orderGoodsId,
                'order_id' => (int)$lockedOrder['order_id'],
                'user_id' => (int)$lockedOrder['user_id'],
                'type' => RefundTypeEnum::SERVICE,
                'apply_desc' => $reason !== '' ? $reason : 'Apple / App Store 退款成功通知自动建档',
                'audit_status' => RefundAuditStatusEnum::REVIEWED,
                'status' => RefundStatusEnum::NORMAL,
                'refund_money' => OrderModel::getRefundableAmount($lockedOrder),
                'store_id' => (int)$lockedOrder['store_id'],
            ]) === false) {
                throwError('创建 Apple 退款跟踪记录失败');
            }

            $this->logVirtualRefundObservation('refund_notify_auto_created_refund', [
                'trade_id' => (int)$tradeInfo['trade_id'],
                'order_id' => (int)$lockedOrder['order_id'],
                'order_refund_id' => (int)$refund['order_refund_id'],
                'out_trade_no' => (string)($tradeInfo['out_trade_no'] ?? ''),
                'ret_code' => (int)($notifyParams['RetCode'] ?? -1),
            ]);
            return [
                'status' => 'resolved',
                'refund' => $refund,
                'bound_order_refund_id' => (int)$refund['order_refund_id'],
            ];
        });
    }

    /**
     * 直接根据退款成功通知完成重复支付输家交易的退款收口。
     * @param PaymentTradeModel $tradeInfo
     * @param array $notifyParams
     * @return array<string, mixed>
     */
    private function finalizeTradeOnlyVirtualRefundFromNotify(PaymentTradeModel $tradeInfo, array $notifyParams = []): array
    {
        PaymentTradeModel::mergePayloadSnapshot((int)$tradeInfo['trade_id'], [
            'virtual_refund' => [
                'status' => 'completed',
                'completed_at' => time(),
                'source' => 'refund_notify',
                'notify_payload' => $notifyParams,
            ],
        ]);
        $this->updateTradeState((int)$tradeInfo['trade_id']);
        return [
            'trade_id' => (int)$tradeInfo['trade_id'],
            'order_id' => (int)$tradeInfo['order_id'],
            'status' => 'completed',
            'mode' => 'duplicate_payment',
        ];
    }

    /**
     * 收敛无本地退款单的重复支付退款。
     * 这类退款只需要把输家交易收敛到已退款，不应污染订单退款业务状态。
     * @param int $storeId
     * @return array<int, array<string, mixed>>
     */
    private function syncTradeOnlyVirtualRefunds(int $storeId): array
    {
        $trades = (new PaymentTradeModel)
            ->where('store_id', '=', $storeId)
            ->where('platform', '=', 'wechat_virtual')
            ->where('trade_state', '<>', TradeStatusEnum::REFUND)
            ->order(['trade_id' => 'asc'])
            ->select();
        $result = [];
        foreach ($trades as $trade) {
            $snapshot = PaymentTradeModel::decodePayloadSnapshot((string)($trade['payload_snapshot'] ?? ''));
            $virtualRefund = (array)($snapshot['virtual_refund'] ?? []);
            if (empty($virtualRefund['duplicate_payment']) || (string)($virtualRefund['status'] ?? '') !== 'processing') {
                continue;
            }
            try {
                $result[] = $this->syncTradeOnlyVirtualRefund($trade);
            } catch (\Throwable $e) {
                $result[] = [
                    'trade_id' => (int)$trade['trade_id'],
                    'order_id' => (int)$trade['order_id'],
                    'status' => 'error',
                    'message' => $e->getMessage(),
                ];
            }
        }
        return $result;
    }

    /**
     * 收敛无本地退款单的重复支付退款。
     * @param PaymentTradeModel $tradeInfo
     * @return array<string, mixed>
     */
    private function syncTradeOnlyVirtualRefund(PaymentTradeModel $tradeInfo): array
    {
        $order = OrderModel::detail((int)$tradeInfo['order_id']);
        if (empty($order)) {
            return [
                'trade_id' => (int)$tradeInfo['trade_id'],
                'order_id' => (int)$tradeInfo['order_id'],
                'status' => 'missing_order',
            ];
        }
        $snapshot = PaymentTradeModel::decodePayloadSnapshot((string)($tradeInfo['payload_snapshot'] ?? ''));
        if ($this->shouldWaitForIosAppleRefund($tradeInfo, $snapshot)) {
            $this->logVirtualRefundObservation('ios_refund_trade_only_sync_waiting', [
                'trade_id' => (int)$tradeInfo['trade_id'],
                'order_id' => (int)$tradeInfo['order_id'],
                'order_no' => (string)($order['order_no'] ?? ''),
                'out_trade_no' => (string)($tradeInfo['out_trade_no'] ?? ''),
                'status' => 'waiting_ios_apple_refund',
                'action' => 'skip_query_order',
                'mode' => 'duplicate_payment',
            ]);
            return [
                'trade_id' => (int)$tradeInfo['trade_id'],
                'order_id' => (int)$tradeInfo['order_id'],
                'status' => 'waiting_ios_apple_refund',
                'message' => 'iOS Apple退款等待App Store与Apple结果通知，跳过主动查单',
            ];
        }
        $queryResult = $this->queryVirtualRefundState($order, $tradeInfo);
        $status = (int)($queryResult['status'] ?? -1);
        if (!$this->isVirtualRefundCompletedStatus($status)) {
            return [
                'trade_id' => (int)$tradeInfo['trade_id'],
                'order_id' => (int)$tradeInfo['order_id'],
                'status' => 'processing',
                'virtual_status' => $status,
            ];
        }
        PaymentTradeModel::mergePayloadSnapshot((int)$tradeInfo['trade_id'], [
            'virtual_refund' => [
                'status' => 'completed',
                'completed_at' => time(),
                'final_query' => $queryResult,
            ],
        ]);
        $this->updateTradeState((int)$tradeInfo['trade_id']);
        return [
            'trade_id' => (int)$tradeInfo['trade_id'],
            'order_id' => (int)$tradeInfo['order_id'],
            'status' => 'completed',
            'virtual_status' => $status,
        ];
    }

    /**
     * 是否已达到虚拟退款完成态
     * @param int $status
     * @return bool
     */
    private function isVirtualRefundCompletedStatus(int $status): bool
    {
        return in_array($status, [
            self::VIRTUAL_ORDER_STATUS_REFUNDED,
            self::VIRTUAL_ORDER_STATUS_USER_REFUNDED,
        ], true);
    }

    /**
     * 当前退款是否应等待 iOS Apple / App Store 异步结果，而不是主动查单收敛。
     * 只要本地已进入 waiting_ios_apple_refund，或者已经识别为 Apple 订单，就不再走 query_order 轮询。
     * @param PaymentTradeModel $tradeInfo
     * @param array $snapshot
     * @return bool
     */
    private function shouldWaitForIosAppleRefund(PaymentTradeModel $tradeInfo, array $snapshot = []): bool
    {
        $virtualRefund = (array)($snapshot['virtual_refund'] ?? []);
        if ((string)($virtualRefund['status'] ?? '') === 'waiting_ios_apple_refund') {
            return true;
        }
        return $this->isIosAppleVirtualTrade($tradeInfo, $snapshot)
            && !empty($virtualRefund['ios_refund_required']);
    }

    /**
     * 是否为 iOS Apple 虚拟支付订单。
     * 微信 query_order 中 order_type=7 表示 Apple 支付订单；若已收到 Apple 退款问询，也视为 Apple 订单。
     * @param PaymentTradeModel $tradeInfo
     * @param array $snapshot
     * @return bool
     */
    private function isIosAppleVirtualTrade(PaymentTradeModel $tradeInfo, array $snapshot = []): bool
    {
        return PaymentTradeModel::isIosAppleVirtualTrade($tradeInfo, $snapshot);
    }

    /**
     * 提取虚拟支付查单/通知快照中的 order_type 候选值。
     * @param array $snapshot
     * @return array<int, int>
     */
    private function getVirtualOrderTypeCandidates(array $snapshot): array
    {
        $candidates = [];
        $paths = [
            ['query_order', 'result', 'order', 'order_type'],
            ['query_order', 'order', 'order_type'],
            ['timer_query_order', 'result', 'order', 'order_type'],
            ['timer_query_order', 'order', 'order_type'],
            ['virtual_refund', 'query_result', 'order_type'],
            ['virtual_refund', 'final_query', 'order_type'],
            ['pay_notify', 'payload', 'order_type'],
            ['pay_notify', 'payload', 'OrderType'],
        ];
        foreach ($paths as $path) {
            $value = $this->getNestedArrayValue($snapshot, $path);
            if ($value !== null && $value !== '') {
                $candidates[] = (int)$value;
            }
        }
        return $candidates;
    }

    /**
     * 从数组中按路径取值。
     * @param array $payload
     * @param array<int, string> $path
     * @return mixed|null
     */
    private function getNestedArrayValue(array $payload, array $path)
    {
        $cursor = $payload;
        foreach ($path as $key) {
            if (!is_array($cursor) || !array_key_exists($key, $cursor)) {
                return null;
            }
            $cursor = $cursor[$key];
        }
        return $cursor;
    }

    /**
     * 记录虚拟支付退款链路观测日志。
     * 目标是把“本地申请退款 -> Apple问询 -> 微信退款通知 -> 本地收口/等待”串成可检索证据。
     * @param string $stage
     * @param array $context
     * @return void
     */
    private function logVirtualRefundObservation(string $stage, array $context = []): void
    {
        Log::append('OrderRefund-virtualPaymentObservation', array_merge([
            'stage' => $stage,
        ], $context));
    }

    /**
     * 获取第三方交易记录
     * @param int $tradeId 交易记录ID
     * @return PaymentTradeModel|null
     * @throws BaseException
     */
    private function getTradeInfo(int $tradeId): ?PaymentTradeModel
    {
        $tradeInfo = PaymentTradeModel::detail($tradeId);
        empty($tradeInfo) && throwError('未找到第三方交易记录');
        return $tradeInfo;
    }

    /**
     * 将第三方交易记录更新为已退款状态
     * @param int $tradeId 交易记录ID
     */
    private function updateTradeState(int $tradeId): bool
    {
        return PaymentTradeModel::updateToRefund($tradeId);
    }

    /**
     * 优先复用付款当时留下的 openid 证据，避免用户绑定漂移后无法补偿。
     */
    private function resolveVirtualTradeOpenid($order, PaymentTradeModel $tradeInfo, array $snapshot = []): string
    {
        $candidates = [
            (string)($snapshot['payer_openid'] ?? ''),
            (string)($snapshot['query_order']['payload']['openid'] ?? ''),
            (string)($snapshot['timer_query_order']['payload']['openid'] ?? ''),
            (string)($snapshot['virtual_refund']['request_payload']['openid'] ?? ''),
            (string)($snapshot['refund_notify']['payload']['OpenId'] ?? ''),
            (string)($snapshot['pay_notify']['payload']['OpenId'] ?? ''),
        ];
        foreach ($candidates as $candidate) {
            if ($candidate !== '') {
                return $candidate;
            }
        }
        $oauth = UserOauthModel::getOauth((int)$order['user_id'], ClientEnum::MP_WEIXIN);
        return (string)($oauth['oauth_id'] ?? '');
    }

    /**
     * 获取支付方式的配置信息
     * @param mixed $order 订单信息
     * @return mixed
     * @throws BaseException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    private function getPaymentConfig($order)
    {
        $PaymentModel = new PaymentModel;
        $templateInfo = $PaymentModel->getPaymentInfo($order['pay_method'], $order['platform'], $order['store_id']);
        return $templateInfo['template']['config'][$order['pay_method']];
    }
}
