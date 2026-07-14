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

namespace app\timer\service;

use app\api\model\wxapp\Setting as WxappSettingModel;
use app\common\model\UserOauth as UserOauthModel;
use app\timer\model\Order as OrderModel;
use app\common\model\PaymentTrade as PaymentTradeModel;
use app\common\model\Goods as CommonGoodsModel;
use app\common\model\store\Setting as StoreSettingModel;
use app\common\service\BaseService;
use app\common\service\Order as OrderService;
use app\common\service\order\Complete as OrderCompleteService;
use app\common\service\order\Refund as RefundService;
use app\common\service\order\IosRefundRisk as IosRefundRiskService;
use app\common\service\order\PaySuccess as OrderPaySuccessService;
use app\common\enum\order\OrderStatus as OrderStatusEnum;
use app\common\enum\order\PayStatus as PayStatusEnum;
use app\common\enum\payment\trade\TradeStatus as TradeStatusEnum;
use app\common\enum\payment\trade\ChannelClass as ChannelClassEnum;
use app\common\enum\Client as ClientEnum;
use app\common\enum\Setting as SettingEnum;
use app\common\library\helper;
use app\common\library\wechat\VirtualPayment as WechatVirtualPayment;
use app\timer\library\Tools;

/**
 * 服务类：订单模块
 * Class Order
 * @package app\timer\service
 */
class Order extends BaseService
{
    private const VIRTUAL_ORDER_STATUS_CREATED = 0;
    private const VIRTUAL_ORDER_STATUS_PAYING = 1;
    private const VIRTUAL_ORDER_STATUS_PAID = 2;
    private const VIRTUAL_ORDER_STATUS_PAID_PENDING_DELIVERY = 3;

    /**
     * 未支付订单自动关闭
     * @param int $storeId
     * @param int $closeHours 自定关闭订单有效期 (小时)
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function closeEvent(int $storeId, int $closeHours)
    {
        // 截止时间
        $deadlineTime = time() - ((int)$closeHours * 60 * 60);
        // 查询截止时间未支付的订单
        $model = new OrderModel;
        $list = $model->getListByClose($storeId, $deadlineTime);
        $list = $list->filter(function ($order) {
            return !$this->shouldSkipVirtualAutoClose($order);
        });
        // 订单ID集
        $orderIds = helper::getArrayColumn($list, 'order_id');
        if (!empty($orderIds)) {
            // 取消订单事件
            foreach ($list as $order) {
                OrderService::cancelEvent($order);
            }
            // 批量更新订单状态为已取消
            $model->onBatchUpdate($orderIds, ['order_status' => OrderStatusEnum::CANCELLED]);
        }
        // 记录日志
        Tools::taskLogs('Order', 'closeEvent', [
            'storeId' => $storeId,
            'closeHours' => $closeHours,
            'deadlineTime' => $deadlineTime,
            'orderIds' => helper::jsonEncode($orderIds)
        ]);
    }

    /**
     * 已发货订单自动确认收货
     * @param int $storeId
     * @param int $receiveDays 自动收货天数
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function receiveEvent(int $storeId, int $receiveDays)
    {
        // 截止时间
        $deadlineTime = time() - ((int)$receiveDays * 86400);
        // 查询截止时间未确认收货的订单ID集
        $model = new OrderModel;
        $orderIds = $model->getOrderIdsByReceive($storeId, $deadlineTime);
        // 更新订单收货状态
        if (!empty($orderIds)) {
            // 批量更新订单状态为已收货
            $model->onUpdateReceived($orderIds);
            // 批量处理已完成的订单
            $this->onReceiveCompleted($storeId, $orderIds);
        }
        // 记录日志
        Tools::taskLogs('Order', 'receiveEvent', [
            'storeId' => $storeId,
            'receiveDays' => $receiveDays,
            'deadlineTime' => $deadlineTime,
            'orderIds' => helper::jsonEncode($orderIds)
        ]);
    }

    /**
     * 已完成订单自动结算
     * @param int $storeId
     * @param int $refundDays 售后期限天数
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function settledEvent(int $storeId, int $refundDays)
    {
        // 截止时间
        $deadlineTime = time() - ((int)$refundDays * 86400);
        // 查询截止时间确认收货的订单ID集
        $model = new OrderModel;
        $list = $model->getOrderListBySettled($storeId, $deadlineTime);
        // 订单ID集
        $orderIds = helper::getArrayColumn($list, 'order_id');
        // 订单结算服务
        if (!empty($orderIds)) {
            $OrderCompleteService = new OrderCompleteService();
            $OrderCompleteService->settled($list);
        }
        // 记录日志
        Tools::taskLogs('Order', 'settledEvent', [
            'storeId' => $storeId,
            'refundDays' => $refundDays,
            'deadlineTime' => $deadlineTime,
            'orderIds' => helper::jsonEncode($orderIds)
        ]);
    }

    /**
     * 自动关单前，先跳过仍有虚拟支付待收口迹象的订单。
     * 这样即便 notify 晚到，也不会因为先关单而失去后续补偿入口。
     * @param mixed $order
     * @return bool
     */
    private function shouldSkipVirtualAutoClose($order): bool
    {
        $trade = (new PaymentTradeModel())
            ->where('order_id', '=', (int)($order['order_id'] ?? 0))
            ->where('platform', '=', 'wechat_virtual')
            ->order('trade_id', 'desc')
            ->find();
        if (empty($trade)) {
            return false;
        }
        if (in_array((int)($trade['trade_state'] ?? 0), [TradeStatusEnum::SUCCESS, TradeStatusEnum::REFUND], true)) {
            return true;
        }
        $snapshot = PaymentTradeModel::decodePayloadSnapshot((string)($trade['payload_snapshot'] ?? ''));
        $queryStatus = isset($snapshot['query_order']['status']) ? (int)$snapshot['query_order']['status'] : null;
        $timerQueryStatus = isset($snapshot['timer_query_order']['status']) ? (int)$snapshot['timer_query_order']['status'] : null;
        $hasPayNotify = !empty($snapshot['pay_notify']);
        return $hasPayNotify
            || in_array($queryStatus, [self::VIRTUAL_ORDER_STATUS_CREATED, self::VIRTUAL_ORDER_STATUS_PAYING, self::VIRTUAL_ORDER_STATUS_PAID, self::VIRTUAL_ORDER_STATUS_PAID_PENDING_DELIVERY], true)
            || in_array($timerQueryStatus, [self::VIRTUAL_ORDER_STATUS_CREATED, self::VIRTUAL_ORDER_STATUS_PAYING, self::VIRTUAL_ORDER_STATUS_PAID, self::VIRTUAL_ORDER_STATUS_PAID_PENDING_DELIVERY], true);
    }

    /**
     * 收敛虚拟支付退款状态
     * @param int $storeId
     * @return void
     */
    public function syncVirtualRefunds(int $storeId): void
    {
        $results = (new RefundService())->syncVirtualRefunds($storeId);
        $this->logVirtualRefundResults('Order', 'syncVirtualRefunds', $storeId, $results);
    }

    /**
     * 高频补偿：收敛虚拟支付退款处理中状态
     * @param int $storeId
     * @return void
     */
    public function syncPendingVirtualRefunds(int $storeId): void
    {
        $results = (new RefundService())->syncPendingVirtualRefunds($storeId);
        $this->logVirtualRefundResults('VirtualRefundCompensation', 'syncPendingVirtualRefunds', $storeId, $results);
    }

    /**
     * 记录虚拟退款补偿结果，便于按 order_refund_id 追踪命中情况。
     * @param string $taskKey
     * @param string $method
     * @param int $storeId
     * @param array<int, array<string, mixed>> $results
     * @return void
     */
    private function logVirtualRefundResults(string $taskKey, string $method, int $storeId, array $results): void
    {
        $orderRefundIds = [];
        foreach ($results as $item) {
            $orderRefundId = (int)($item['order_refund_id'] ?? 0);
            if ($orderRefundId > 0) {
                $orderRefundIds[] = $orderRefundId;
            }
        }
        Tools::taskLogs($taskKey, $method, [
            'storeId' => $storeId,
            'orderRefundIds' => helper::jsonEncode(array_values(array_unique($orderRefundIds))),
            'results' => helper::jsonEncode($results, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    /**
     * 收敛虚拟支付支付状态
     * @param int $storeId
     * @return void
     */
    public function syncVirtualTradeStates(int $storeId): void
    {
        $config = StoreSettingModel::getItem(SettingEnum::VIRTUAL_PAYMENT, $storeId);
        $wxapp = WxappSettingModel::getConfigBasic($storeId);
        $appId = (string)($wxapp['app_id'] ?? '');
        $appSecret = (string)($wxapp['app_secret'] ?? '');
        $results = [];
        $trades = (new PaymentTradeModel())
            ->alias('trade')
            ->join('order o', 'o.order_id = trade.order_id')
            ->field('trade.*')
            ->where('trade.store_id', '=', $storeId)
            ->where('trade.platform', '=', 'wechat_virtual')
            ->where('o.pay_status', '=', PayStatusEnum::PENDING)
            ->whereIn('o.order_status', [OrderStatusEnum::NORMAL, OrderStatusEnum::CANCELLED, OrderStatusEnum::APPLY_CANCEL])
            ->where(function ($query) {
                $query->where('trade.trade_state', '<>', 30)
                    ->whereOr(function ($successQuery) {
                        $successQuery->where('trade.trade_state', '=', 20)
                            ->whereRaw('(`o`.`trade_id` = 0 OR `o`.`trade_id` <> `trade`.`trade_id`)');
                    });
            })
            ->order(['trade.order_id' => 'asc', 'trade.trade_id' => 'desc'])
            ->select();
        foreach ($trades as $trade) {
            try {
                $order = OrderModel::detail((int)$trade['order_id']);
                if (empty($order) || (int)($order['pay_status'] ?? 0) === PayStatusEnum::SUCCESS) {
                    continue;
                }
                $env = (int)($trade['env'] ?? $config['env'] ?? 0);
                $appKey = $env === CommonGoodsModel::VP_ENV_SANDBOX
                    ? (string)($config['sandbox_app_key'] ?? '')
                    : (string)($config['production_app_key'] ?? '');
                if ($appId === '' || $appSecret === '' || $appKey === '') {
                    $results[] = [
                        'trade_id' => (int)$trade['trade_id'],
                        'order_id' => (int)$trade['order_id'],
                        'status' => 'config_missing',
                    ];
                    continue;
                }
                $openid = $this->resolveVirtualTradeOpenid($trade);
                if ($openid === '') {
                    $results[] = [
                        'trade_id' => (int)$trade['trade_id'],
                        'order_id' => (int)$trade['order_id'],
                        'status' => 'missing_openid',
                    ];
                    continue;
                }
                $payload = [
                    'openid' => $openid,
                    'env' => $env,
                    'order_id' => (string)$trade['out_trade_no'],
                ];
                $payment = new WechatVirtualPayment($appId, $appSecret, $appKey);
                $result = $payment->queryOrder($payload);
                $status = (int)($result['order']['status'] ?? -1);
                PaymentTradeModel::mergePayloadSnapshot((int)$trade['trade_id'], [
                    'timer_query_order' => [
                        'queried_at' => time(),
                        'payload' => $payload,
                        'result' => $result,
                        'status' => $status,
                    ],
                ]);
                if ((int)($result['errcode'] ?? -1) !== 0) {
                    $results[] = [
                        'trade_id' => (int)$trade['trade_id'],
                        'order_id' => (int)$trade['order_id'],
                        'status' => 'query_error',
                        'errcode' => (int)($result['errcode'] ?? -1),
                    ];
                    continue;
                }
                if (in_array($status, [self::VIRTUAL_ORDER_STATUS_PAID, self::VIRTUAL_ORDER_STATUS_PAID_PENDING_DELIVERY], true)) {
                    $paymentData = [
                        'tradeNo' => (string)(($result['order']['wxpay_order_id'] ?? '') ?: ($result['order']['channel_order_id'] ?? '') ?: (string)$trade['out_trade_no']),
                        'outTradeNo' => (string)$trade['out_trade_no'],
                        'orderStatus' => $status,
                        'raw' => $result,
                    ];
                    $service = new OrderPaySuccessService();
                    $service->setOrderNo((string)$trade['order_no'])
                        ->setMethod((string)$trade['pay_method'])
                        ->setTradeId((int)$trade['trade_id'])
                        ->setPaymentData($paymentData);
                    $handled = $service->handle();
                    $results[] = [
                        'trade_id' => (int)$trade['trade_id'],
                        'order_id' => (int)$trade['order_id'],
                        'status' => $handled ? 'paid' : 'pay_success_failed',
                        'virtual_status' => $status,
                        'message' => $handled ? '' : (string)$service->getError(),
                    ];
                    continue;
                }
                $results[] = [
                    'trade_id' => (int)$trade['trade_id'],
                    'order_id' => (int)$trade['order_id'],
                    'status' => in_array($status, [self::VIRTUAL_ORDER_STATUS_CREATED, self::VIRTUAL_ORDER_STATUS_PAYING], true) ? 'pending' : 'not_paid',
                    'virtual_status' => $status,
                ];
            } catch (\Throwable $e) {
                $results[] = [
                    'trade_id' => (int)($trade['trade_id'] ?? 0),
                    'order_id' => (int)($trade['order_id'] ?? 0),
                    'status' => 'error',
                    'message' => $e->getMessage(),
                ];
            }
        }
        $results = array_merge($results, $this->syncPaidUnknownVirtualTradeChannels(
            $storeId,
            $config,
            $appId,
            $appSecret
        ));
        Tools::taskLogs('Order', 'syncVirtualTradeStates', [
            'storeId' => $storeId,
            'results' => helper::jsonEncode($results, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    /**
     * 有界补偿：订单已支付但渠道分类仍未知时继续查单，避免长期无法筛选。
     *
     * @param int $storeId
     * @param array $config
     * @param string $appId
     * @param string $appSecret
     * @return array<int, array<string, mixed>>
     */
    private function syncPaidUnknownVirtualTradeChannels(int $storeId, array $config, string $appId, string $appSecret): array
    {
        $results = [];
        $processed = 0;
        $trades = (new PaymentTradeModel())
            ->alias('trade')
            ->join('order o', 'o.order_id = trade.order_id')
            ->field('trade.*')
            ->where('trade.store_id', '=', $storeId)
            ->where('trade.platform', '=', 'wechat_virtual')
            ->where('trade.channel_class', '=', ChannelClassEnum::UNKNOWN)
            ->whereIn('trade.trade_state', [TradeStatusEnum::SUCCESS, TradeStatusEnum::REFUND])
            ->where('o.pay_status', '=', PayStatusEnum::SUCCESS)
            ->whereRaw('`o`.`trade_id` = `trade`.`trade_id`')
            ->order(['trade.update_time' => 'asc', 'trade.trade_id' => 'asc'])
            ->limit(100)
            ->select();
        foreach ($trades as $trade) {
            if ($processed >= 20) {
                break;
            }
            $snapshot = PaymentTradeModel::decodePayloadSnapshot((string)($trade['payload_snapshot'] ?? ''));
            $reconcile = (array)($snapshot['channel_reconcile'] ?? []);
            if ((int)($reconcile['next_retry_at'] ?? 0) > time()) {
                continue;
            }
            $processed++;
            $attempt = (int)($reconcile['attempt_count'] ?? 0) + 1;
            try {
                $env = (int)($trade['env'] ?? $config['env'] ?? 0);
                $appKey = $env === CommonGoodsModel::VP_ENV_SANDBOX
                    ? (string)($config['sandbox_app_key'] ?? '')
                    : (string)($config['production_app_key'] ?? '');
                $openid = $this->resolveVirtualTradeOpenid($trade);
                if ($appId === '' || $appSecret === '' || $appKey === '' || $openid === '') {
                    throw new \RuntimeException($openid === '' ? 'missing_openid' : 'config_missing');
                }
                $payload = [
                    'openid' => $openid,
                    'env' => $env,
                    'order_id' => (string)$trade['out_trade_no'],
                ];
                $payment = new WechatVirtualPayment($appId, $appSecret, $appKey);
                $result = $payment->queryOrder($payload);
                $errCode = (int)($result['errcode'] ?? -1);
                $status = (int)($result['order']['status'] ?? -1);
                PaymentTradeModel::mergePayloadSnapshot((int)$trade['trade_id'], [
                    'timer_query_order' => [
                        'queried_at' => time(),
                        'payload' => $payload,
                        'result' => $result,
                        'status' => $status,
                    ],
                ]);
                $resolved = PaymentTradeModel::detail((int)$trade['trade_id']);
                $resolvedClass = (int)($resolved['channel_class'] ?? ChannelClassEnum::UNKNOWN);
                $isResolved = $errCode === 0 && $resolvedClass !== ChannelClassEnum::UNKNOWN;
                $retryAt = $isResolved ? 0 : time() + min(3600, 60 * (2 ** min($attempt - 1, 6)));
                PaymentTradeModel::mergePayloadSnapshot((int)$trade['trade_id'], [
                    'channel_reconcile' => [
                        'attempt_count' => $attempt,
                        'last_attempt_at' => time(),
                        'next_retry_at' => $retryAt,
                        'status' => $isResolved ? 'resolved' : ($errCode === 0 ? 'evidence_pending' : 'query_error'),
                        'errcode' => $errCode,
                    ],
                ]);
                $results[] = [
                    'trade_id' => (int)$trade['trade_id'],
                    'order_id' => (int)$trade['order_id'],
                    'status' => $isResolved ? 'channel_reconciled' : ($errCode === 0 ? 'channel_evidence_pending' : 'channel_query_error'),
                    'channel_class' => $resolvedClass,
                    'errcode' => $errCode,
                ];
            } catch (\Throwable $e) {
                $retryAt = time() + min(3600, 60 * (2 ** min($attempt - 1, 6)));
                PaymentTradeModel::mergePayloadSnapshot((int)$trade['trade_id'], [
                    'channel_reconcile' => [
                        'attempt_count' => $attempt,
                        'last_attempt_at' => time(),
                        'next_retry_at' => $retryAt,
                        'status' => 'error',
                        'error' => $e->getMessage(),
                    ],
                ]);
                $results[] = [
                    'trade_id' => (int)$trade['trade_id'],
                    'order_id' => (int)$trade['order_id'],
                    'status' => 'channel_reconcile_error',
                    'message' => $e->getMessage(),
                ];
            }
        }
        return $results;
    }

    /**
     * 优先复用付款时留下的 openid 证据，避免用户绑定漂移后定时补偿查不到单。
     * @param mixed $trade
     * @return string
     */
    private function resolveVirtualTradeOpenid($trade): string
    {
        $snapshot = PaymentTradeModel::decodePayloadSnapshot((string)($trade['payload_snapshot'] ?? ''));
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
        $oauth = UserOauthModel::getOauth((int)($trade['user_id'] ?? 0), ClientEnum::MP_WEIXIN);
        return (string)($oauth['oauth_id'] ?? '');
    }

    /**
     * 补偿虚拟支付履约通知
     * @param int $storeId
     * @return void
     */
    public function syncVirtualProvideGoods(int $storeId): void
    {
        $config = StoreSettingModel::getItem(SettingEnum::VIRTUAL_PAYMENT, $storeId);
        $wxapp = WxappSettingModel::getConfigBasic($storeId);
        $appId = (string)($wxapp['app_id'] ?? '');
        $appSecret = (string)($wxapp['app_secret'] ?? '');
        $results = [];
        $orders = (new OrderModel)->with(['trade'])
            ->where('store_id', '=', $storeId)
            ->where('pay_status', '=', 20)
            ->where('order_status', '=', OrderStatusEnum::COMPLETED)
            ->select();
        foreach ($orders as $order) {
            try {
                $trade = $order['trade'] ?? null;
                if (empty($trade) || (string)($trade['platform'] ?? '') !== 'wechat_virtual') {
                    continue;
                }
                $snapshot = PaymentTradeModel::decodePayloadSnapshot((string)$trade['payload_snapshot']);
                if ((string)($snapshot['provide_goods']['status'] ?? '') === 'success') {
                    continue;
                }
                // order -> trade 原子建立发送权；风险冻结先提交时不再产生新 dispatch。
                $claim = IosRefundRiskService::claimProvideGoodsDispatchIfAllowed(
                    (int)$order['order_id'],
                    (int)$trade['trade_id'],
                    'timer_compensation'
                );
                if (empty($claim['claimed'])) {
                    if ((string)($claim['reason'] ?? '') === 'ios_refund_risk_locked') {
                        $currentStatus = (string)($snapshot['provide_goods']['status'] ?? '');
                        if (!in_array($currentStatus, ['success', 'sending'], true)) {
                            PaymentTradeModel::finishProvideGoodsDispatch((int)$trade['trade_id'], 'skipped', [
                                'reason' => 'ios_refund_risk_locked',
                            ]);
                        }
                        $results[] = ['order_id' => (int)$order['order_id'], 'status' => 'risk_locked'];
                    }
                    continue;
                }
                $env = (int)($trade['env'] ?? $config['env'] ?? 0);
                $appKey = $env === CommonGoodsModel::VP_ENV_SANDBOX
                    ? (string)($config['sandbox_app_key'] ?? '')
                    : (string)($config['production_app_key'] ?? '');
                if ($appId === '' || $appSecret === '' || $appKey === '') {
                    PaymentTradeModel::finishProvideGoodsDispatch((int)$trade['trade_id'], 'failed', [
                        'reason' => 'virtual_payment_config_missing',
                    ]);
                    $results[] = ['order_id' => (int)$order['order_id'], 'status' => 'config_missing'];
                    continue;
                }
                $payload = [
                    'order_id' => (string)$trade['out_trade_no'],
                    'env' => $env,
                ];
                $riskOrder = (new OrderModel)->where('order_id', '=', (int)$order['order_id'])->find();
                if (!empty($riskOrder) && IosRefundRiskService::isLocked($riskOrder)) {
                    PaymentTradeModel::finishProvideGoodsDispatch((int)$trade['trade_id'], 'skipped', [
                        'reason' => 'ios_refund_risk_locked_before_send',
                    ]);
                    $results[] = ['order_id' => (int)$order['order_id'], 'status' => 'risk_locked_before_send'];
                    continue;
                }
                $payment = new WechatVirtualPayment($appId, $appSecret, $appKey);
                $result = $payment->notifyProvideGoods($payload);
                PaymentTradeModel::finishProvideGoodsDispatch(
                    (int)$trade['trade_id'],
                    (int)($result['errcode'] ?? -1) === 0 ? 'success' : 'failed',
                    [
                        'request_payload' => $payload,
                        'result' => $result,
                    ]
                );
                $results[] = [
                    'order_id' => (int)$order['order_id'],
                    'trade_id' => (int)$trade['trade_id'],
                    'status' => (int)($result['errcode'] ?? -1) === 0 ? 'success' : 'failed',
                ];
            } catch (\Throwable $e) {
                if (!empty($trade['trade_id'])) {
                    PaymentTradeModel::finishProvideGoodsDispatch((int)$trade['trade_id'], 'failed', [
                        'error' => $e->getMessage(),
                    ]);
                }
                $results[] = [
                    'order_id' => (int)$order['order_id'],
                    'status' => 'error',
                    'message' => $e->getMessage(),
                ];
            }
        }
        Tools::taskLogs('Order', 'syncVirtualProvideGoods', [
            'storeId' => $storeId,
            'results' => helper::jsonEncode($results, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    /**
     * 批量处理已完成的订单
     * @param int $storeId 商城ID
     * @param array $orderIds 订单ID集
     * @return void
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    private function onReceiveCompleted(int $storeId, array $orderIds): void
    {
        // 获取已完成的订单列表
        $model = new OrderModel;
        $list = $model->getListByOrderIds($storeId, $orderIds);
        // 执行订单完成后的操作
        if (!$list->isEmpty()) {
            $OrderCompleteService = new OrderCompleteService();
            $OrderCompleteService->complete($list, $storeId);
        }
    }
}
