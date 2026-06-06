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

use app\common\model\Order as OrderModel;
use app\common\service\Order as OrderService;
use app\common\service\order\Complete as OrderCompleteService;
use app\common\service\order\Refund as RefundService;
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
use app\common\library\helper;
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
            ->order(['order.create_time' => 'desc']);
        $this->applyKeywordFilter($query, $params);
        if ($this->isRefundDataType($params['dataType'])) {
            $refundOrderIds = $this->getRefundOrderIdsByDataType($params['dataType']);
            $query->where('order.order_id', 'in', empty($refundOrderIds) ? [0] : $refundOrderIds);
        } else {
            $query->where($dataTypeFilter);
        }
        $list = $query->paginate(10);
        foreach ($list as $item) {
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
        if (!$this->checkCanStartService()) {
            return false;
        }
        return $this->transaction(function () {
            return $this->save([
                'delivery_status' => DeliveryStatusEnum::DELIVERED,
                'delivery_time' => time(),
            ]) !== false;
        });
    }

    /**
     * 完成服务
     * @return bool
     */
    public function completeService(): bool
    {
        if (!$this->checkCanCompleteService()) {
            return false;
        }
        return $this->transaction(function () {
            $status = $this->save([
                'receipt_status' => ReceiptStatusEnum::RECEIVED,
                'receipt_time' => time(),
                'order_status' => OrderStatusEnum::COMPLETED,
            ]);
            if ($status === false) {
                return false;
            }
            (new OrderCompleteService)->complete([$this], static::$storeId);
            return true;
        });
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
            ->order(['order.create_time' => 'desc']);
        $this->applyKeywordFilter($query, $params);
        if ($this->isRefundDataType($params['dataType'])) {
            $refundOrderIds = $this->getRefundOrderIdsByDataType($params['dataType']);
            $query->where('order.order_id', 'in', empty($refundOrderIds) ? [0] : $refundOrderIds);
        } else {
            $query->where($dataTypeFilter);
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
        return $this->setQueryDefaultValue($param, [
            'searchType' => '',     // 关键词类型 (10订单号 20会员昵称 30会员ID 40联系人姓名 50联系人电话 60第三方支付订单号)
            'searchValue' => '',    // 关键词内容
            'orderSource' => -1,    // 订单来源
            'payMethod' => '',      // 支付方式
            'deliveryType' => -1,   // 兼容旧参数，服务订单场景忽略
            'betweenTime' => [],    // 起止时间
            'userId' => 0,          // 会员ID
        ]);
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
        if ($searchValue === '') {
            return;
        }
        $searchLikeValue = $this->escapeLikeValue($searchValue);
        switch ((int)$params['searchType']) {
            case 10:
                $query->where('order.order_no', 'like', "%{$searchLikeValue}%");
                break;
            case 20:
                $query->where('user.nick_name', 'like', "%{$searchLikeValue}%");
                break;
            case 30:
                if (preg_match('/^\d+$/', $searchValue)) {
                    $query->where('order.user_id', '=', (int)$searchValue);
                } else {
                    $query->where('order.user_id', '=', -1);
                }
                break;
            case 40:
                $query->whereRaw(
                    "CASE WHEN JSON_VALID(order.order_source_data) THEN JSON_UNQUOTE(JSON_EXTRACT(order.order_source_data, '$.service_contact.contact_name')) ELSE '' END LIKE :searchValue",
                    ['searchValue' => "%{$searchLikeValue}%"]
                );
                break;
            case 50:
                $query->whereRaw(
                    "CASE WHEN JSON_VALID(order.order_source_data) THEN JSON_UNQUOTE(JSON_EXTRACT(order.order_source_data, '$.service_contact.contact_mobile')) ELSE '' END LIKE :searchValue",
                    ['searchValue' => "%{$searchLikeValue}%"]
                );
                break;
            case 60:
                $query->where('trade.out_trade_no', 'like', "%{$searchLikeValue}%");
                break;
        }
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
        return [
            'can_start_service' => $this->checkCanStartService($data),
            'can_complete_service' => $this->checkCanCompleteService($data),
            'can_refund_before_service' => $this->checkCanRefundBeforeService($data),
            'has_active_refund' => !empty($activeRefund),
            'active_refund_id' => (int)($activeRefund['order_refund_id'] ?? 0),
            'can_audit_refund' => !empty($activeRefund) && (int)($activeRefund['audit_status'] ?? 0) === RefundAuditStatusEnum::WAIT,
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
     * 是否为服务订单
     * @param array|self $order
     * @return bool
     */
    private function isServiceOrder($order): bool
    {
        return static::isServiceOrderData($order);
    }
}
