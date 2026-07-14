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

namespace app\store\model;

use app\api\model\wxapp\Setting as WxappSettingModel;
use app\common\model\Order as OrderModel;
use app\common\model\PaymentTrade as PaymentTradeModel;
use app\common\model\PaymentIosRefundInquiry as IosRefundInquiryModel;
use app\common\model\Goods as CommonGoodsModel;
use app\common\model\store\Setting as StoreSettingModel;
use app\common\service\Order as OrderService;
use app\common\service\order\Complete as OrderCompleteService;
use app\common\service\order\Refund as RefundService;
use app\common\service\order\IosRefundRisk as IosRefundRiskService;
use app\common\service\order\Printer as PrinterService;
use app\common\service\order\PaySuccess as OrderPaySuccessService;
use app\common\enum\order\{
    DataType as DataTypeEnum,
    PayStatus as PayStatusEnum,
    OrderStatus as OrderStatusEnum,
    DeliveryType as DeliveryTypeEnum,
    ReceiptStatus as ReceiptStatusEnum,
    DeliveryStatus as DeliveryStatusEnum
};
use app\common\enum\order\refund\AuditStatus as RefundAuditStatusEnum;
use app\common\enum\order\refund\RefundType as RefundTypeEnum;
use app\common\enum\order\refund\RefundStatus as RefundStatusEnum;
use app\common\enum\payment\Method as PaymentMethod;
use app\common\enum\payment\trade\TradeStatus as TradeStatusEnum;
use app\common\enum\payment\trade\ChannelClass as ChannelClassEnum;
use app\common\enum\Setting as SettingEnum;
use app\common\library\helper;
use app\common\library\wechat\VirtualPayment as WechatVirtualPayment;
use cores\exception\BaseException;

/**
 * 订单管理
 * Class Order
 * @package app\store\model
 */
class Order extends OrderModel
{
    /**
     * 订单详情页数据
     * @param int $orderId
     * @return Order|array|null
     */
    public function getDetail(int $orderId)
    {
        $detail = static::detail($orderId, [
            'user',
            'goods' => ['image', 'refund'],
            'trade',
        ]);
        if ($detail) {
            $latestInquiry = IosRefundInquiryModel::latestByOrderId((int)$detail['order_id']);
            $detail['ios_refund_latest_inquiry'] = $latestInquiry ? IosRefundInquiryModel::project($latestInquiry->toArray()) : null;
            $detail['ios_refund_inquiry_timeline'] = IosRefundInquiryModel::timelineByOrderId((int)$detail['order_id']);
            $virtualTrade = $this->resolveBackendVirtualTrade($detail);
            if (!empty($virtualTrade)) {
                $detail->setRelation('trade', $virtualTrade);
                $detail['virtual_payment_summary'] = $this->buildVirtualPaymentSummary($virtualTrade, $detail);
            }
        }
        return $this->appendBackendActionFlags($detail);
    }

    /**
     * 订单列表
     * @param array $param
     * @return mixed
     */
    public function getList(array $param = [])
    {
        $params = $this->normalizeQueryParams($param);
        // 检索查询条件
        $filter = $this->getQueryFilter($params);
        // 设置订单类型条件
        $dataTypeFilter = $this->getFilterDataType($params['dataType']);
        // 获取数据列表
        $query = $this->with(['goods.image', 'user.avatar', 'trade'])
            ->alias('order')
            ->field('order.*')
            ->leftJoin('user', 'user.user_id = order.user_id')
            ->leftJoin('payment_trade trade', 'trade.trade_id = order.trade_id')
            ->where($filter)
            ->where('order.is_delete', '=', 0)
            ->order(['order.create_time' => 'desc', 'order.order_id' => 'desc']);
        $this->applyKeywordFilter($query, $params);
        $this->applyPaymentChannelFilter($query, (string)$params['paymentChannel']);
        if ($this->isRefundDataType($params['dataType'])) {
            $refundOrderIds = $this->getRefundOrderIdsByDataType($params['dataType']);
            $query->where('order.order_id', 'in', empty($refundOrderIds) ? [0] : $refundOrderIds);
        } else {
            $query->where($dataTypeFilter);
            if ($this->shouldExcludeRefundingOrdersFromDataType($params['dataType'])) {
                $refundOrderIds = $this->getRefundOrderIdsByDataType(DataTypeEnum::APPLY_CANCEL);
                if (!empty($refundOrderIds)) {
                    $query->where('order.order_id', 'not in', $refundOrderIds);
                }
            }
        }
        $list = $query->paginate(10);
        $orderIds = [];
        foreach ($list as $item) {
            $orderIds[] = (int)$item['order_id'];
        }
        $inquiryMap = IosRefundInquiryModel::latestMapByOrderIds($orderIds);
        foreach ($list as $item) {
            $item['ios_refund_latest_inquiry'] = $inquiryMap[(int)$item['order_id']] ?? null;
            $item->append(['backend_action_flags']);
        }
        return $list;
    }

    /**
     * 开始服务
     * @return bool
     */
    public function startService(): bool
    {
        return $this->transaction(function () {
            // 授权必须在订单锁内重做，避免退款申请/Apple问询与开始服务同时成功。
            $order = (new static)
                ->where('order_id', '=', (int)$this['order_id'])
                ->where('store_id', '=', (int)static::$storeId)
                ->lock(true)
                ->find();
            if (empty($order)) {
                $this->error = '订单不存在';
                return false;
            }
            (new OrderRefund)
                ->where('order_id', '=', (int)$order['order_id'])
                ->where('type', '=', RefundTypeEnum::SERVICE)
                ->order(['order_refund_id' => 'asc'])
                ->lock(true)
                ->select();
            if ((int)($order['trade_id'] ?? 0) > 0) {
                (new PaymentTradeModel)
                    ->where('trade_id', '=', (int)$order['trade_id'])
                    ->lock(true)
                    ->find();
            }
            if (!$this->checkCanStartService($order)) {
                return false;
            }
            if ($order->save([
                'delivery_status' => DeliveryStatusEnum::DELIVERED,
                'delivery_time' => time(),
            ]) === false) {
                return false;
            }
            $this->data($order->getData());
            return true;
        });
    }

    /**
     * 完成服务
     * @return bool
     */
    public function completeService(): bool
    {
        $shouldNotifyVirtualProvideGoods = false;
        $status = $this->transaction(function () use (&$shouldNotifyVirtualProvideGoods) {
            // 授权必须在订单锁内重做，锁顺序与退款/问询一致。
            $order = (new static)
                ->where('order_id', '=', (int)$this['order_id'])
                ->where('store_id', '=', (int)static::$storeId)
                ->lock(true)
                ->find();
            if (empty($order)) {
                $this->error = '订单不存在';
                return false;
            }
            (new OrderRefund)
                ->where('order_id', '=', (int)$order['order_id'])
                ->where('type', '=', RefundTypeEnum::SERVICE)
                ->order(['order_refund_id' => 'asc'])
                ->lock(true)
                ->select();
            $trade = null;
            if ((int)($order['trade_id'] ?? 0) > 0) {
                $trade = (new PaymentTradeModel)
                    ->where('trade_id', '=', (int)$order['trade_id'])
                    ->lock(true)
                    ->find();
            }
            if (!$this->checkCanCompleteService($order)) {
                return false;
            }
            if ($order->save([
                'receipt_status' => ReceiptStatusEnum::RECEIVED,
                'receipt_time' => time(),
                'order_status' => OrderStatusEnum::COMPLETED,
            ]) === false) {
                return false;
            }
            $shouldNotifyVirtualProvideGoods = !empty($trade)
                && (string)($trade['platform'] ?? '') === 'wechat_virtual';
            if ($shouldNotifyVirtualProvideGoods) {
                $snapshot = PaymentTradeModel::decodePayloadSnapshot((string)($trade['payload_snapshot'] ?? ''));
                $snapshot['provide_goods'] = array_merge((array)($snapshot['provide_goods'] ?? []), [
                    'status' => 'pending',
                    'queued_at' => time(),
                ]);
                if ($trade->save([
                    'payload_snapshot' => helper::jsonEncode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]) === false) {
                    throwError('记录履约通知待发送状态失败');
                }
                $order->setRelation('trade', $trade);
            }
            $goods = (new OrderGoods)
                ->where('order_id', '=', (int)$order['order_id'])
                ->select();
            $order->setRelation('goods', $goods);
            (new OrderCompleteService)->complete([$order], static::$storeId);
            $this->data($order->getData());
            if (!empty($trade)) {
                $this->setRelation('trade', $trade);
            }
            return true;
        });
        if ($status && $shouldNotifyVirtualProvideGoods) {
            $this->notifyVirtualProvideGoods();
        }
        return $status;
    }

    /**
     * 服务开始前退款
     * @return bool
     * @throws BaseException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function refundBeforeService(): bool
    {
        if (!$this->checkCanRefundBeforeService()) {
            return false;
        }
        return $this->transaction(function () {
            $firstGoods = $this['goods'][0] ?? null;
            if (empty($firstGoods) || empty($firstGoods['order_goods_id'])) {
                throwError('订单商品信息不存在');
            }

            $refund = new OrderRefund;
            if (!$refund->save([
                'order_goods_id' => (int)$firstGoods['order_goods_id'],
                'order_id' => (int)$this['order_id'],
                'user_id' => (int)$this['user_id'],
                'type' => RefundTypeEnum::SERVICE,
                'apply_desc' => '商家在服务开始前直接退款',
                'audit_status' => RefundAuditStatusEnum::WAIT,
                'status' => RefundStatusEnum::NORMAL,
                'store_id' => self::$storeId,
            ])) {
                throwError('创建退款单失败');
            }

            $refund = OrderRefund::detail((int)$refund['order_refund_id'], ['orderGoods']);
            if (empty($refund)) {
                throwError('退款单创建失败');
            }
            if (!$refund->completeAutoRefund('商家在服务开始前直接退款')) {
                throwError($refund->getError() ?: '执行订单退款失败');
            }
            return true;
        });
    }

    /**
     * 订单列表(全部)
     * @param array $param
     * @return iterable|\think\model\Collection|\think\Paginator
     */
    public function getListAll(array $param = [])
    {
        $params = $this->normalizeQueryParams($param);
        // 检索查询条件
        $queryFilter = $this->getQueryFilter($params);
        // 设置订单类型条件
        $dataTypeFilter = $this->getFilterDataType($params['dataType']);
        // 获取数据列表
        $query = $this->with(['goods.image', 'user.avatar', 'trade'])
            ->alias('order')
            ->field('order.*')
            ->leftJoin('user', 'user.user_id = order.user_id')
            ->leftJoin('payment_trade trade', 'trade.trade_id = order.trade_id')
            ->where($queryFilter)
            ->where('order.is_delete', '=', 0)
            ->order(['order.create_time' => 'desc', 'order.order_id' => 'desc']);
        $this->applyKeywordFilter($query, $params);
        $this->applyPaymentChannelFilter($query, (string)$params['paymentChannel']);
        if ($this->isRefundDataType($params['dataType'])) {
            $refundOrderIds = $this->getRefundOrderIdsByDataType($params['dataType']);
            $query->where('order.order_id', 'in', empty($refundOrderIds) ? [0] : $refundOrderIds);
        } else {
            $query->where($dataTypeFilter);
            if ($this->shouldExcludeRefundingOrdersFromDataType($params['dataType'])) {
                $refundOrderIds = $this->getRefundOrderIdsByDataType(DataTypeEnum::APPLY_CANCEL);
                if (!empty($refundOrderIds)) {
                    $query->where('order.order_id', 'not in', $refundOrderIds);
                }
            }
        }
        return $query->select();
    }

    /**
     * 设置检索查询条件
     * @param array $param
     * @return array
     */
    private function getQueryFilter(array $param): array
    {
        $params = $this->normalizeQueryParams($param);
        // 检索查询条件
        $filter = [];
        // 起止时间
        if (!empty($params['betweenTime'])) {
            $times = between_time($params['betweenTime']);
            $filter[] = ['order.create_time', '>=', $times['start_time']];
            $filter[] = ['order.create_time', '<', $times['end_time'] + 86400];
        }
        // 订单来源
        $params['orderSource'] > -1 && $filter[] = ['order.order_source', '=', (int)$params['orderSource']];
        // 支付方式
        !empty($params['payMethod']) && $filter[] = ['order.pay_method', '=', $params['payMethod']];
        // 会员ID
        $params['userId'] > 0 && $filter[] = ['order.user_id', '=', (int)$params['userId']];
        return $filter;
    }

    /**
     * 规范化列表查询参数
     * @param array $param
     * @return array
     */
    private function normalizeQueryParams(array $param): array
    {
        $params = $this->setQueryDefaultValue($param, [
            'searchType' => 10,     // 关键词类型 (10订单号 20会员昵称 30会员ID 60第三方支付订单号)
            'searchValue' => '',    // 关键词内容
            'serviceSearchFields' => [], // 服务单字段复选框
            'gamePlatform' => '',   // 服务单游戏平台筛选
            'orderSource' => -1,    // 订单来源
            'payMethod' => '',      // 支付方式
            'paymentChannel' => '', // 支付渠道 (ios_apple/non_ios)
            'deliveryType' => -1,   // 兼容旧参数，服务订单场景忽略
            'betweenTime' => [],    // 起止时间
            'userId' => 0,          // 会员ID
        ]);
        $params['serviceSearchFields'] = $this->normalizeServiceSearchFields($params['serviceSearchFields'] ?? []);
        $params['gamePlatform'] = $this->normalizeServiceGamePlatform((string)($params['gamePlatform'] ?? ''));
        $paymentChannel = (string)($params['paymentChannel'] ?? '');
        $params['paymentChannel'] = $params['dataType'] === 'all' && in_array($paymentChannel, ['ios_apple', 'non_ios'], true)
            ? $paymentChannel
            : '';
        return $params;
    }

    /**
     * 在分页前应用支付渠道筛选。未知、未支付、失败或交易绑定异常均不命中任一渠道。
     * @param mixed $query
     * @param string $paymentChannel
     * @return void
     */
    private function applyPaymentChannelFilter($query, string $paymentChannel): void
    {
        if ($paymentChannel === '') {
            return;
        }
        $tradeConsistency = '`trade`.`trade_id` = `order`.`trade_id`'
            . ' AND `trade`.`order_id` = `order`.`order_id`'
            . ' AND `trade`.`store_id` = `order`.`store_id`'
            . ' AND `trade`.`trade_state` IN (:tradeSuccess, :tradeRefund)';
        $bindings = [
            'paySuccess' => PayStatusEnum::SUCCESS,
            'tradeSuccess' => TradeStatusEnum::SUCCESS,
            'tradeRefund' => TradeStatusEnum::REFUND,
        ];
        if ($paymentChannel === 'ios_apple') {
            $bindings['channelClass'] = ChannelClassEnum::IOS_APPLE;
            $query->whereRaw(
                '`order`.`pay_status` = :paySuccess AND ' . $tradeConsistency . ' AND `trade`.`channel_class` = :channelClass',
                $bindings
            );
            return;
        }
        $bindings['balanceMethod'] = PaymentMethod::BALANCE;
        $bindings['channelClass'] = ChannelClassEnum::NON_IOS;
        $query->whereRaw(
            '`order`.`pay_status` = :paySuccess AND ('
            . '(`order`.`pay_method` = :balanceMethod AND `order`.`trade_id` = 0)'
            . ' OR (' . $tradeConsistency . ' AND `trade`.`channel_class` = :channelClass)'
            . ')',
            $bindings
        );
    }

    /**
     * 应用关键词搜索条件
     * @param $query
     * @param array $params
     * @return void
     */
    private function applyKeywordFilter($query, array $params): void
    {
        $searchValue = trim((string)$params['searchValue']);
        $serviceSearchFields = $params['serviceSearchFields'] ?? [];
        if ($searchValue !== '') {
            $searchLikeValue = '%' . $this->escapeLikeValue($searchValue) . '%';
            $conditions = [];
            $bindings = [];
            $this->appendBaseSearchConditions($conditions, $bindings, (int)$params['searchType'], $searchValue, $searchLikeValue);
            $this->appendServiceSearchConditions($conditions, $bindings, $serviceSearchFields, $searchLikeValue);
            if (empty($conditions)) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereRaw('(' . implode(' OR ', $conditions) . ')', $bindings);
            }
        }
        if (!empty($params['gamePlatform'])) {
            $query->whereRaw(
                "CASE WHEN JSON_VALID(order.order_source_data) THEN JSON_UNQUOTE(JSON_EXTRACT(order.order_source_data, '$.service_contact.game_platform')) ELSE '' END = :gamePlatform",
                ['gamePlatform' => $params['gamePlatform']]
            );
        }
    }

    /**
     * 追加基础搜索条件
     * @param array $conditions
     * @param array $bindings
     * @param int $searchType
     * @param string $searchValue
     * @param string $searchLikeValue
     * @return void
     */
    private function appendBaseSearchConditions(array &$conditions, array &$bindings, int $searchType, string $searchValue, string $searchLikeValue): void
    {
        switch ($searchType) {
            case 10:
                $conditions[] = 'order.order_no LIKE :searchOrderNo';
                $bindings['searchOrderNo'] = $searchLikeValue;
                break;
            case 20:
                $conditions[] = 'user.nick_name LIKE :searchNickName';
                $bindings['searchNickName'] = $searchLikeValue;
                break;
            case 30:
                if (preg_match('/^\d+$/', $searchValue)) {
                    $conditions[] = 'order.user_id = :searchUserId';
                    $bindings['searchUserId'] = (int)$searchValue;
                }
                break;
            case 40:
                $conditions[] = "CASE WHEN JSON_VALID(order.order_source_data) THEN JSON_UNQUOTE(JSON_EXTRACT(order.order_source_data, '$.service_contact.game_account_id')) ELSE '' END LIKE :searchGameAccountId";
                $bindings['searchGameAccountId'] = $searchLikeValue;
                break;
            case 50:
                $conditions[] = "CASE WHEN JSON_VALID(order.order_source_data) THEN JSON_UNQUOTE(JSON_EXTRACT(order.order_source_data, '$.service_contact.contact_mobile')) ELSE '' END LIKE :searchContactMobile";
                $bindings['searchContactMobile'] = $searchLikeValue;
                break;
            case 60:
                $conditions[] = '(trade.out_trade_no LIKE :searchOutTradeNo OR EXISTS (SELECT 1 FROM payment_trade WHERE payment_trade.order_id = `order`.order_id AND payment_trade.out_trade_no LIKE :searchOutTradeNoExists))';
                $bindings['searchOutTradeNo'] = $searchLikeValue;
                $bindings['searchOutTradeNoExists'] = $searchLikeValue;
                break;
        }
    }

    /**
     * 追加服务订单字段搜索条件
     * @param array $conditions
     * @param array $bindings
     * @param array $serviceSearchFields
     * @param string $searchLikeValue
     * @return void
     */
    private function appendServiceSearchConditions(array &$conditions, array &$bindings, array $serviceSearchFields, string $searchLikeValue): void
    {
        foreach ($serviceSearchFields as $field) {
            switch ($field) {
                case 'game_account_id':
                    $conditions[] = "CASE WHEN JSON_VALID(order.order_source_data) THEN JSON_UNQUOTE(JSON_EXTRACT(order.order_source_data, '$.service_contact.game_account_id')) ELSE '' END LIKE :serviceSearchGameAccountId";
                    $bindings['serviceSearchGameAccountId'] = $searchLikeValue;
                    break;
                case 'contact_mobile':
                    $conditions[] = "CASE WHEN JSON_VALID(order.order_source_data) THEN JSON_UNQUOTE(JSON_EXTRACT(order.order_source_data, '$.service_contact.contact_mobile')) ELSE '' END LIKE :serviceSearchContactMobile";
                    $bindings['serviceSearchContactMobile'] = $searchLikeValue;
                    break;
                case 'buyer_remark':
                    $conditions[] = 'order.buyer_remark LIKE :serviceSearchBuyerRemark';
                    $bindings['serviceSearchBuyerRemark'] = $searchLikeValue;
                    break;
            }
        }
    }

    /**
     * 规范化服务订单搜索字段
     * @param mixed $fields
     * @return array
     */
    private function normalizeServiceSearchFields($fields): array
    {
        if (is_string($fields)) {
            $fields = explode(',', $fields);
        }
        if (!is_array($fields)) {
            return [];
        }
        $allowed = ['game_account_id', 'contact_mobile', 'buyer_remark'];
        $normalized = [];
        foreach ($fields as $field) {
            $field = trim((string)$field);
            if ($field !== '' && in_array($field, $allowed, true)) {
                $normalized[] = $field;
            }
        }
        return array_values(array_unique($normalized));
    }

    /**
     * 规范化服务订单游戏平台
     * @param string $value
     * @return string
     */
    private function normalizeServiceGamePlatform(string $value): string
    {
        $value = strtolower(trim($value));
        return in_array($value, ['pc', 'mobile'], true) ? $value : '';
    }

    /**
     * 转义 LIKE 查询中的通配符
     * @param string $value
     * @return string
     */
    private function escapeLikeValue(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /**
     * 设置订单类型条件
     * @param string $dataType
     * @return array
     */
    private function getFilterDataType(string $dataType): array
    {
        // 数据类型
        $filter = [];
        switch ($dataType) {
            case DataTypeEnum::ALL:
                break;
            case DataTypeEnum::CONTACT:
                $filter = [
                    ['pay_status', '=', PayStatusEnum::SUCCESS],
                    ['delivery_status', '=', DeliveryStatusEnum::NOT_DELIVERED],
                    ['order_status', '=', OrderStatusEnum::NORMAL]
                ];
                break;
            case DataTypeEnum::PAY:
                $filter[] = ['pay_status', '=', PayStatusEnum::PENDING];
                $filter[] = ['order_status', '=', OrderStatusEnum::NORMAL];
                break;
            case DataTypeEnum::DELIVERY:
            case DataTypeEnum::IN_SERVICE:
                $filter = [
                    ['pay_status', '=', PayStatusEnum::SUCCESS],
                    ['delivery_status', '=', DeliveryStatusEnum::DELIVERED],
                    ['receipt_status', '=', ReceiptStatusEnum::NOT_RECEIVED],
                    ['order_status', '=', OrderStatusEnum::NORMAL]
                ];
                break;
            case DataTypeEnum::RECEIPT:
                $filter = [
                    ['pay_status', '=', PayStatusEnum::SUCCESS],
                    ['delivery_status', '=', DeliveryStatusEnum::DELIVERED],
                    ['receipt_status', '=', ReceiptStatusEnum::NOT_RECEIVED],
                    ['order_status', '=', OrderStatusEnum::NORMAL]
                ];
                break;
            case DataTypeEnum::COMPLETE:
                $filter[] = ['order_status', '=', OrderStatusEnum::COMPLETED];
                break;
            case DataTypeEnum::APPLY_CANCEL:
            case DataTypeEnum::CANCEL:
                $filter[] = ['pay_status', '=', PayStatusEnum::PENDING];
                $filter[] = ['order_status', '=', OrderStatusEnum::CANCELLED];
                break;
            case DataTypeEnum::REFUND:
            case 'refund':
                break;
        }
        return $filter;
    }

    /**
     * 是否为退款相关订单筛选
     * @param string $dataType
     * @return bool
     */
    private function isRefundDataType(string $dataType): bool
    {
        return in_array($dataType, [DataTypeEnum::APPLY_CANCEL, DataTypeEnum::REFUND, 'refund'], true);
    }

    /**
     * 退款中的服务单不应继续混入普通服务流程分组。
     */
    private function shouldExcludeRefundingOrdersFromDataType(string $dataType): bool
    {
        return in_array($dataType, [
            DataTypeEnum::CONTACT,
            DataTypeEnum::DELIVERY,
            DataTypeEnum::IN_SERVICE,
            DataTypeEnum::RECEIPT,
        ], true);
    }

    /**
     * 获取退款相关订单ID集合
     * @param string $dataType
     * @return array
     */
    private function getRefundOrderIdsByDataType(string $dataType): array
    {
        $query = (new OrderRefund)
            ->where('store_id', '=', (int)static::$storeId)
            ->where('type', '=', RefundTypeEnum::SERVICE);
        if ($dataType === DataTypeEnum::APPLY_CANCEL) {
            $query->where('status', '=', RefundStatusEnum::NORMAL);
        } else {
            $query->where('status', '=', RefundStatusEnum::COMPLETED);
        }
        return array_values(array_unique($query->column('order_id')));
    }

    /**
     * 修改订单价格
     * @param array $data
     * @return bool
     */
    public function updatePrice(array $data): bool
    {
        if ($this['pay_status'] != PayStatusEnum::PENDING) {
            $this->error = '该订单不合法';
            return false;
        }
        // 实际付款金额
        $payPrice = $data['order_price'];
        if ($payPrice <= 0) {
            $this->error = '订单实付款价格不能为0.00元';
            return false;
        }
        // 改价的金额差价
        $updatePrice = helper::bcsub($data['order_price'], $this['order_price']);
        // 更新订单记录
        return $this->save([
                'order_price' => $data['order_price'],
                'pay_price' => $payPrice,
                'update_price' => $updatePrice,
                'express_price' => 0
            ]) !== false;
    }

    /**
     * 修改商家备注
     * @param array $data
     * @return bool
     */
    public function updateRemark(array $data): bool
    {
        return $this->save(['merchant_remark' => $data['content'] ?? '']);
    }

    /**
     * 修改收货地址
     * @param array $data
     * @return bool
     */
    public function updateAddress(array $data): bool
    {
        $this->error = '服务订单不支持修改收货地址';
        return false;
    }

    /**
     * 小票打印
     * @param array $data
     * @return bool
     * @throws BaseException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function printer(array $data): bool
    {
        // 实例化打印机驱动
        $Printer = new PrinterService;
        // 手动打印小票
        $status = $Printer->printEvent($this, $data['printerId']);
        if ($status === false) {
            $this->error = $Printer->getError();
        }
        return $status;
    }

    /**
     * 审核：用户取消订单
     * @param array $data
     * @return bool|mixed
     */
    public function confirmCancel(array $data)
    {
        $this->error = '服务订单已改为通过售后单审核退款，不再支持原取消审核流程';
        return false;
    }

    /**
     * 获取后台动作标识
     * @param $value
     * @param $data
     * @return array
     */
    public function getBackendActionFlagsAttr($value, $data): array
    {
        $activeRefund = $this->getActiveServiceRefundSummary($data);
        $refundProjection = $this->getBackendVirtualRefundProjection($data);
        return [
            'can_start_service' => $this->checkCanStartService($data),
            'can_complete_service' => $this->checkCanCompleteService($data),
            'can_refund_before_service' => $this->checkCanRefundBeforeService($data),
            'has_active_refund' => !empty($activeRefund),
            'active_refund_id' => (int)($activeRefund['order_refund_id'] ?? 0),
            'can_audit_refund' => !empty($activeRefund) && (int)($activeRefund['audit_status'] ?? 0) === RefundAuditStatusEnum::WAIT,
            'ios_apple_refund_required' => (bool)($refundProjection['ios_apple_refund_required'] ?? false),
            'ios_refund_risk_status' => (int)($refundProjection['ios_refund_risk_status'] ?? 0),
            'ios_refund_risk_text' => (string)($refundProjection['ios_refund_risk_text'] ?? ''),
            'ios_refund_inquiry_received' => (bool)($refundProjection['ios_refund_inquiry_received'] ?? false),
            'latest_ios_refund_inquiry' => $refundProjection['latest_ios_refund_inquiry'] ?? null,
            'merchant_refund_review_status' => $refundProjection['merchant_refund_review_status'] ?? null,
            'can_cancel_refund' => (bool)($refundProjection['can_cancel'] ?? false),
            'refund_entry_mode' => (string)($refundProjection['refund_entry_mode'] ?? 'developer_refund'),
            'refund_guidance' => (string)($refundProjection['refund_guidance'] ?? ''),
            'refund_display_state' => (string)($refundProjection['refund_display_state'] ?? ''),
            'refund_display_state_text' => (string)($refundProjection['refund_display_state_text'] ?? ''),
        ];
    }

    /**
     * 将订单记录设置为已删除
     * @return bool
     */
    public function setDelete(): bool
    {
        return $this->save(['is_delete' => 1]);
    }

    /**
     * 获取已付款订单总数 (可指定某天)
     * @param null $startDate
     * @param null $endDate
     * @return int
     */
    public function getPayOrderTotal($startDate = null, $endDate = null): int
    {
        $filter = [
            ['pay_status', '=', PayStatusEnum::SUCCESS],
            ['order_status', '<>', OrderStatusEnum::CANCELLED]
        ];
        if (!is_null($startDate) && !is_null($endDate)) {
            $filter[] = ['pay_time', '>=', strtotime($startDate)];
            $filter[] = ['pay_time', '<', strtotime($endDate) + 86400];
        }
        return $this->getOrderTotal($filter);
    }

    /**
     * 获取未发货订单数量
     * @return int
     */
    public function getNotDeliveredOrderTotal(): int
    {
        $filter = [
            ['pay_status', '=', PayStatusEnum::SUCCESS],
            ['delivery_status', '<>', DeliveryStatusEnum::DELIVERED],
            ['order_status', 'in', [OrderStatusEnum::NORMAL, OrderStatusEnum::APPLY_CANCEL]]
        ];
        return $this->getOrderTotal($filter);
    }

    /**
     * 获取未付款订单数量
     * @return int
     */
    public function getNotPayOrderTotal(): int
    {
        $filter = [
            ['pay_status', '=', PayStatusEnum::PENDING],
            ['order_status', '=', OrderStatusEnum::NORMAL]
        ];
        return $this->getOrderTotal($filter);
    }

    /**
     * 获取订单总数
     * @param array $filter
     * @return int
     */
    private function getOrderTotal(array $filter = []): int
    {
        // 获取订单总数量
        return $this->where($filter)
            ->where('is_delete', '=', 0)
            ->count();
    }

    /**
     * 获取某天的总销售额
     * @param null $startDate
     * @param null $endDate
     * @return float
     */
    public function getOrderTotalPrice($startDate = null, $endDate = null): float
    {
        // 查询对象
        $query = $this->getNewQuery();
        // 设置查询条件
        if (!is_null($startDate) && !is_null($endDate)) {
            $query->where('pay_time', '>=', strtotime($startDate))
                ->where('pay_time', '<', strtotime($endDate) + 86400);
        }
        // 总销售额
        return $query->where('pay_status', '=', PayStatusEnum::SUCCESS)
            ->where('order_status', '<>', OrderStatusEnum::CANCELLED)
            ->where('is_delete', '=', 0)
            ->sum('pay_price');
    }

    /**
     * 获取某天的下单用户数
     * @param string $day
     * @return float|int
     */
    public function getPayOrderUserTotal(string $day)
    {
        $startTime = strtotime($day);
        return $this->field('user_id')
            ->where('pay_time', '>=', $startTime)
            ->where('pay_time', '<', $startTime + 86400)
            ->where('pay_status', '=', PayStatusEnum::SUCCESS)
            ->where('is_delete', '=', '0')
            ->group('user_id')
            ->count();
    }

    /**
     * 根据订单号获取ID集
     * @param array $orderNoArr
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public static function getOrderIds(array $orderNoArr): array
    {
        $list = (new static)->where('order_no', 'in', $orderNoArr)->select();
        $data = [];
        foreach ($list as $item) {
            $data[$item['order_no']] = $item['order_id'];
        }
        return $data;
    }

    /**
     * 判断是否可开始服务
     * @param array|self|null $order
     * @return bool
     */
    private function checkCanStartService($order = null): bool
    {
        $order = $order ?: $this;
        if (!$this->isServiceOrder($order)) {
            $this->error = '当前订单仅支持服务单操作';
            return false;
        }
        if ($order['pay_status'] != PayStatusEnum::SUCCESS) {
            $this->error = '未支付订单不可开始服务';
            return false;
        }
        if ($order['order_status'] != OrderStatusEnum::NORMAL || $order['delivery_status'] != DeliveryStatusEnum::NOT_DELIVERED) {
            $this->error = '当前订单状态不允许开始服务';
            return false;
        }
        if (IosRefundRiskService::isLocked($order)) {
            $this->error = '该订单已进入App Store退款流程，服务已永久冻结';
            return false;
        }
        if ($this->hasActiveRefund((int)$order['order_id'])) {
            $this->error = '当前订单存在进行中的退款申请，暂不可开始服务';
            return false;
        }
        return true;
    }

    /**
     * 判断是否可完成服务
     * @param array|self|null $order
     * @return bool
     */
    private function checkCanCompleteService($order = null): bool
    {
        $order = $order ?: $this;
        if (!$this->isServiceOrder($order)) {
            $this->error = '当前订单仅支持服务单操作';
            return false;
        }
        if (
            $order['pay_status'] != PayStatusEnum::SUCCESS
            || $order['order_status'] != OrderStatusEnum::NORMAL
            || $order['delivery_status'] != DeliveryStatusEnum::DELIVERED
            || $order['receipt_status'] != ReceiptStatusEnum::NOT_RECEIVED
        ) {
            $this->error = '当前订单状态不允许完成服务';
            return false;
        }
        if (IosRefundRiskService::isLocked($order)) {
            $this->error = '该订单已进入App Store退款流程，服务已永久冻结';
            return false;
        }
        if ($this->hasActiveRefund((int)$order['order_id'])) {
            $this->error = '当前订单存在进行中的退款申请，暂不可完成服务';
            return false;
        }
        return true;
    }

    /**
     * 判断是否可在服务开始前退款
     * @param array|self|null $order
     * @return bool
     */
    private function checkCanRefundBeforeService($order = null): bool
    {
        $order = $order ?: $this;
        if (!$this->isServiceOrder($order)) {
            $this->error = '当前订单仅支持服务单操作';
            return false;
        }
        if ($order['pay_status'] != PayStatusEnum::SUCCESS) {
            $this->error = '未支付订单无需退款';
            return false;
        }
        $refundProjection = $this->getBackendVirtualRefundProjection($order);
        if (!empty($refundProjection['ios_apple_refund_required'])) {
            $this->error = 'iOS App Store 虚拟支付订单需由用户在 App Store 申请退款，商家不可直接发起服务前退款';
            return false;
        }
        if (static::getServiceRefundMode($order) !== static::SERVICE_REFUND_MODE_AUTO) {
            $this->error = '当前订单状态不允许退款';
            return false;
        }
        if ($this->hasActiveRefund((int)$order['order_id'])) {
            $this->error = '当前订单已存在进行中的退款申请';
            return false;
        }
        return true;
    }

    /**
     * 追加后台动作标识
     * @param mixed $order
     * @return mixed
     */
    private function appendBackendActionFlags($order)
    {
        if (!empty($order)) {
            $order->append(['backend_action_flags']);
        }
        return $order;
    }


    /**
     * 后台订单详情优先解析与当前订单最相关的虚拟支付交易
     * 优先顺序：当前进行中退款绑定的交易 > 订单当前 trade_id > 最近成功/已退款交易 > 最近一笔虚拟交易
     * @param array|self $order
     * @return PaymentTradeModel|array|null
     */
    private function resolveBackendVirtualTrade($order)
    {
        $orderId = (int)($order['order_id'] ?? 0);
        if ($orderId <= 0) {
            return $order['trade'] ?? null;
        }
        $activeRefund = $this->getActiveServiceRefundSummary($order);
        $trade = PaymentTradeModel::resolveVirtualTradeForRefundContext(
            $orderId,
            (int)($order['trade_id'] ?? 0),
            (int)($activeRefund['order_refund_id'] ?? 0)
        );
        return $trade ?: ($order['trade'] ?? null);
    }

    /**
     * 获取后台订单维度的虚拟支付退款投影。
     * @param array|self|null $order
     * @param PaymentTradeModel|array|null $trade
     * @return array
     */
    private function getBackendVirtualRefundProjection($order = null, $trade = null): array
    {
        $order = $order ?: $this;
        $orderData = $order instanceof self ? $order->getData() : (array)$order;
        $refund = $this->getActiveServiceRefundSummary($orderData) ?: $this->getLatestServiceRefundSummary($orderData);
        $trade = $trade ?: $this->resolveBackendVirtualTrade($orderData);
        return IosRefundRiskService::buildProjection($orderData, $trade, (array)$refund,
            !empty($orderData['ios_refund_latest_inquiry']) ? (array)$orderData['ios_refund_latest_inquiry'] : null
        );
    }

    /**
     * 当前订单是否存在进行中的退款申请
     * @param int $orderId
     * @return bool
     */
    private function hasActiveRefund(int $orderId): bool
    {
        return (new OrderRefund)
            ->where('type', '=', RefundTypeEnum::SERVICE)
            ->where('order_id', '=', $orderId)
            ->where('status', '=', RefundStatusEnum::NORMAL)
            ->count() > 0;
    }

    /**
     * 获取当前进行中的服务退款单摘要
     * @param array|self|null $order
     * @return array|null
     */
    private function getActiveServiceRefundSummary($order = null): ?array
    {
        $order = $order ?: $this;
        foreach (($order['goods'] ?? []) as $goods) {
            if (!empty($goods['refund']) && (int)$goods['refund']['status'] === RefundStatusEnum::NORMAL) {
                return [
                    'order_refund_id' => (int)($goods['refund']['order_refund_id'] ?? 0),
                    'audit_status' => (int)($goods['refund']['audit_status'] ?? 0),
                ];
            }
        }
        if (empty($order['order_id'])) {
            return null;
        }
        $refund = (new OrderRefund)
            ->field(['order_refund_id', 'audit_status'])
            ->where('type', '=', RefundTypeEnum::SERVICE)
            ->where('order_id', '=', (int)$order['order_id'])
            ->where('status', '=', RefundStatusEnum::NORMAL)
            ->order(['create_time' => 'desc', 'order_refund_id' => 'desc'])
            ->find();
        return $refund ? $refund->toArray() : null;
    }

    /**
     * 获取最近一笔服务退款摘要（包含已驳回/已完成），供风险状态投影使用。
     */
    private function getLatestServiceRefundSummary($order = null): ?array
    {
        $order = $order ?: $this;
        if (empty($order['order_id'])) {
            return null;
        }
        $refund = (new OrderRefund)
            ->field(['order_refund_id', 'audit_status', 'status', 'apply_desc', 'refuse_desc', 'refund_money'])
            ->where('type', '=', RefundTypeEnum::SERVICE)
            ->where('order_id', '=', (int)$order['order_id'])
            ->order(['create_time' => 'desc', 'order_refund_id' => 'desc'])
            ->find();
        return $refund ? $refund->toArray() : null;
    }

    /**
     * 是否为服务订单
     * @param array|self $order
     * @return bool
     */
    private function isServiceOrder($order): bool
    {
        return static::isServiceOrderData($order);
    }

    /**
     * 通知微信虚拟支付已完成履约
     * 失败仅记录到交易快照，交由补偿任务继续收敛。
     * @return void
     */
    private function notifyVirtualProvideGoods(): void
    {
        if ((string)($this['trade']['platform'] ?? '') !== 'wechat_virtual') {
            return;
        }
        // order -> trade 原子建立发送权，消除风险写入与 dispatch claim 之间的 TOCTOU。
        $claim = IosRefundRiskService::claimProvideGoodsDispatchIfAllowed(
            (int)$this['order_id'],
            (int)$this['trade_id'],
            'store_complete_service'
        );
        if (empty($claim['claimed'])) {
            if ((string)($claim['reason'] ?? '') === 'ios_refund_risk_locked') {
                $snapshot = PaymentTradeModel::decodePayloadSnapshot((string)($this['trade']['payload_snapshot'] ?? ''));
                if (!in_array((string)($snapshot['provide_goods']['status'] ?? ''), ['success', 'sending'], true)) {
                    PaymentTradeModel::finishProvideGoodsDispatch((int)$this['trade_id'], 'skipped', [
                        'reason' => 'ios_refund_risk_locked',
                    ]);
                }
            }
            return;
        }
        $config = StoreSettingModel::getItem(SettingEnum::VIRTUAL_PAYMENT, (int)$this['store_id']);
        if ((int)($config['enabled'] ?? 0) !== 1) {
            PaymentTradeModel::finishProvideGoodsDispatch((int)$this['trade_id'], 'skipped', [
                'reason' => 'virtual_payment_disabled',
            ]);
            return;
        }
        $wxapp = WxappSettingModel::getConfigBasic((int)$this['store_id']);
        $appId = (string)($wxapp['app_id'] ?? '');
        $appSecret = (string)($wxapp['app_secret'] ?? '');
        $env = (int)($this['trade']['env'] ?? $config['env'] ?? 0);
        $appKey = $env === CommonGoodsModel::VP_ENV_SANDBOX
            ? (string)($config['sandbox_app_key'] ?? '')
            : (string)($config['production_app_key'] ?? '');
        if ($appId === '' || $appSecret === '' || $appKey === '') {
            PaymentTradeModel::finishProvideGoodsDispatch((int)$this['trade_id'], 'failed', [
                'reason' => 'virtual_payment_config_missing',
            ]);
            return;
        }
        $payload = [
            'order_id' => (string)$this['trade']['out_trade_no'],
            'env' => $env,
        ];
        try {
            // 真正发起上游请求前再次读取风险；前端按钮和任务扫描条件都不是安全边界。
            $riskOrder = (new static)->where('order_id', '=', (int)$this['order_id'])->find();
            if (!empty($riskOrder) && IosRefundRiskService::isLocked($riskOrder)) {
                PaymentTradeModel::finishProvideGoodsDispatch((int)$this['trade_id'], 'skipped', [
                    'reason' => 'ios_refund_risk_locked_before_send',
                ]);
                return;
            }
            $payment = new WechatVirtualPayment($appId, $appSecret, $appKey);
            $result = $payment->notifyProvideGoods($payload);
            PaymentTradeModel::finishProvideGoodsDispatch(
                (int)$this['trade_id'],
                (int)($result['errcode'] ?? -1) === 0 ? 'success' : 'failed',
                [
                    'request_payload' => $payload,
                    'result' => $result,
                ]
            );
        } catch (\Throwable $e) {
            PaymentTradeModel::finishProvideGoodsDispatch((int)$this['trade_id'], 'failed', [
                'request_payload' => $payload,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 构建后台订单详情中的虚拟支付摘要
     * @param PaymentTradeModel|array $trade
     * @return array
     */
    private function buildVirtualPaymentSummary($trade, $order = null): array
    {
        $tradeData = \is_array($trade) ? $trade : $trade->toArray();
        if ((string)($tradeData['platform'] ?? '') !== 'wechat_virtual') {
            return [];
        }
        $order = $order ?: $this;
        $snapshot = PaymentTradeModel::decodePayloadSnapshot((string)($tradeData['payload_snapshot'] ?? ''));
        $provideGoods = (array)($snapshot['provide_goods'] ?? []);
        $refundNotify = (array)($snapshot['refund_notify'] ?? []);
        $refundProjection = $this->getBackendVirtualRefundProjection($order, $trade);
        return [
            'enabled' => true,
            'platform' => (string)($tradeData['platform'] ?? ''),
            'env' => (int)($tradeData['env'] ?? 0),
            'product_id' => (string)($tradeData['product_id'] ?? ''),
            'goods_price' => (int)($tradeData['goods_price'] ?? 0),
            'notify_times' => (int)($tradeData['notify_times'] ?? 0),
            'last_notify_time' => (int)($tradeData['last_notify_time'] ?? 0),
            'trade_state' => (int)($tradeData['trade_state'] ?? 0),
            'refund_status' => (string)($refundNotify['payload']['RetMsg'] ?? ''),
            'provide_goods_status' => (string)($provideGoods['status'] ?? ''),
            'ios_apple_refund_required' => (bool)($refundProjection['ios_apple_refund_required'] ?? false),
            'ios_refund_risk_status' => (int)($refundProjection['ios_refund_risk_status'] ?? 0),
            'ios_refund_risk_text' => (string)($refundProjection['ios_refund_risk_text'] ?? ''),
            'ios_refund_inquiry_received' => (bool)($refundProjection['ios_refund_inquiry_received'] ?? false),
            'latest_ios_refund_inquiry' => $refundProjection['latest_ios_refund_inquiry'] ?? null,
            'merchant_refund_review_status' => $refundProjection['merchant_refund_review_status'] ?? null,
            'refund_entry_mode' => (string)($refundProjection['refund_entry_mode'] ?? 'developer_refund'),
            'refund_guidance' => (string)($refundProjection['refund_guidance'] ?? ''),
            'refund_display_state' => (string)($refundProjection['refund_display_state'] ?? ''),
            'refund_display_state_text' => (string)($refundProjection['refund_display_state_text'] ?? ''),
        ];
    }
}
