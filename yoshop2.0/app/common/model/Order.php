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

namespace app\common\model;

use cores\BaseModel;
use app\common\service\Order as OrderService;
use app\common\enum\order\PayStatus as PayStatusEnum;
use app\common\enum\payment\Method as PaymentMethodEnum;
use app\common\enum\order\OrderStatus as OrderStatusEnum;
use app\common\enum\order\DeliveryType as DeliveryTypeEnum;
use app\common\enum\order\ReceiptStatus as ReceiptStatusEnum;
use app\common\enum\order\DeliveryStatus as DeliveryStatusEnum;
use app\common\enum\order\refund\RefundStatus as RefundStatusEnum;
use app\common\enum\order\refund\RefundType as RefundTypeEnum;
use app\common\enum\payment\trade\TradeStatus as TradeStatusEnum;
use app\common\library\helper;
use think\db\exception\DataNotFoundException;
use think\db\exception\DbException;
use think\db\exception\ModelNotFoundException;
use think\model\relation\HasOne;
use think\model\relation\HasMany;
use think\model\relation\BelongsTo;

/**
 * 订单模型
 * Class Order
 * @package app\common\model
 */
class Order extends BaseModel
{
    public const SERVICE_REFUND_MODE_NONE = 'none';
    public const SERVICE_REFUND_MODE_AUTO = 'auto';
    public const SERVICE_REFUND_MODE_AUDIT = 'audit';

    // 定义表名
    protected $name = 'order';

    // 定义表名(外部引用)
    public static string $tableName = 'order';

    // 定义主键
    protected $pk = 'order_id';

    // 定义别名
    protected string $alias = 'order';

    /**
     * 追加字段
     * @var array
     */
    protected $append = [
        'state_text',   // 售后单状态文字描述
        'game_platform',
        'game_account_id',
        'contact_mobile',
        'adult_confirm',
    ];

    /**
     * 订单商品列表
     * @return HasMany
     */
    public function goods(): HasMany
    {
        $module = self::getCalledModule();
        return $this->hasMany("app\\{$module}\\model\\OrderGoods")->withoutField('content');
    }

    /**
     * 关联订单发货单
     * @return hasMany
     */
    public function delivery(): HasMany
    {
        $module = self::getCalledModule();
        return $this->hasMany("app\\{$module}\\model\\order\\Delivery");
    }

    /**
     * 关联订单收货地址表
     * @return HasOne
     */
    public function address(): HasOne
    {
        $module = self::getCalledModule();
        return $this->hasOne("app\\{$module}\\model\\OrderAddress");
    }

    /**
     * 关联用户表
     * @return BelongsTo
     */
    public function user(): BelongsTo
    {
        $module = self::getCalledModule();
        return $this->belongsTo("app\\{$module}\\model\\User");
    }

    /**
     * 关联物流公司表 (仅用于兼容旧物流数据)
     * @return BelongsTo
     */
    public function express(): BelongsTo
    {
        $module = self::getCalledModule();
        return $this->belongsTo("app\\{$module}\\model\\Express");
    }

    /**
     * 关联模型：第三方交易记录
     * @return BelongsTo
     */
    public function trade(): BelongsTo
    {
        $module = self::getCalledModule();
        return $this->belongsTo("app\\{$module}\\model\\PaymentTrade", 'trade_id', 'trade_id');
    }

    /**
     * 获取器：订单状态文字描述
     * @param $value
     * @param $data
     * @return string
     */
    public function getStateTextAttr($value, $data): string
    {
        if (!static::isServiceOrderData($data)) {
            return $this->getPhysicalOrderStateText($value, $data);
        }
        // 服务单状态映射（兼容旧订单状态）
        if ($data['order_status'] == OrderStatusEnum::COMPLETED) {
            return '已完成';
        }
        if ($data['order_status'] == OrderStatusEnum::CANCELLED) {
            return $data['pay_status'] == PayStatusEnum::SUCCESS ? '已退款' : '已关闭';
        }
        if ($data['order_status'] == OrderStatusEnum::APPLY_CANCEL) {
            return $data['pay_status'] == PayStatusEnum::SUCCESS ? '退款处理中' : '待退款';
        }
        if ($this->hasActiveRefundState($data)) {
            return '退款处理中';
        }
        $pendingPayment = $this->getPendingVirtualPaymentConvergence($data);
        if (!empty($pendingPayment)) {
            return !empty($pendingPayment['is_paid_like']) ? '支付成功待入账' : '支付确认中';
        }
        if ($data['pay_status'] == PayStatusEnum::PENDING) {
            return '待支付';
        }
        if ($data['pay_status'] == PayStatusEnum::SUCCESS && $data['delivery_status'] == DeliveryStatusEnum::NOT_DELIVERED) {
            return '待联系';
        }
        if (
            $data['pay_status'] == PayStatusEnum::SUCCESS
            && $data['delivery_status'] == DeliveryStatusEnum::DELIVERED
            && $data['receipt_status'] == ReceiptStatusEnum::NOT_RECEIVED
        ) {
            return '服务中';
        }
        if ($data['order_status'] != OrderStatusEnum::NORMAL) {
            return OrderStatusEnum::data()[$data['order_status']]['name'];
        }
        return $value ?: '服务中';
    }

    /**
     * 获取实物订单状态文案
     * @param string $value
     * @param array $data
     * @return string
     */
    private function getPhysicalOrderStateText($value, array $data): string
    {
        $value = (string)$value;
        if ($data['order_status'] == OrderStatusEnum::COMPLETED) {
            return '已完成';
        }
        if ($data['order_status'] == OrderStatusEnum::CANCELLED) {
            return $data['pay_status'] == PayStatusEnum::SUCCESS ? '已退款' : '已关闭';
        }
        if ($data['order_status'] == OrderStatusEnum::APPLY_CANCEL) {
            return $data['pay_status'] == PayStatusEnum::SUCCESS ? '退款处理中' : '待退款';
        }
        if ($this->hasActiveRefundState($data)) {
            return '退款处理中';
        }
        $pendingPayment = $this->getPendingVirtualPaymentConvergence($data);
        if (!empty($pendingPayment)) {
            return !empty($pendingPayment['is_paid_like']) ? '支付成功待入账' : '支付确认中';
        }
        if ($data['pay_status'] == PayStatusEnum::PENDING) {
            return '待支付';
        }
        if ($data['pay_status'] == PayStatusEnum::SUCCESS && $data['delivery_status'] == DeliveryStatusEnum::NOT_DELIVERED) {
            return '待发货';
        }
        if (
            $data['pay_status'] == PayStatusEnum::SUCCESS
            && $data['delivery_status'] == DeliveryStatusEnum::DELIVERED
            && $data['receipt_status'] == ReceiptStatusEnum::NOT_RECEIVED
        ) {
            return '待收货';
        }
        return $value ?: '待处理';
    }

    /**
     * 是否存在进行中的退款（优先使用已加载关联，其次回查主表）
     * @param array $data
     * @return bool
     */
    private function hasActiveRefundState(array $data): bool
    {
        foreach (($this['goods'] ?? []) as $goods) {
            if (!empty($goods['refund']) && (int)$goods['refund']['status'] === 0) {
                return true;
            }
        }
        if (empty($data['order_id'])) {
            return false;
        }
        return (new OrderRefund)->where('order_id', '=', (int)$data['order_id'])
            ->where('type', '=', RefundTypeEnum::SERVICE)
            ->where('status', '=', 0)
            ->count() > 0;
    }

    /**
     * 是否存在最近的虚拟支付待收口尝试
     * @param array $data
     * @param int $freshSeconds
     * @return bool
     */
    protected function hasPendingVirtualPaymentConvergence(array $data, int $freshSeconds = 600): bool
    {
        return $this->getPendingVirtualPaymentConvergence($data, $freshSeconds) !== null;
    }

    /**
     * 获取最近的虚拟支付待收口摘要
     * 这里不再单纯依赖固定 TTL，而是更看重 trade 是否仍处于未终态。
     * @param array $data
     * @param int $freshSeconds
     * @return array|null
     */
    protected function getPendingVirtualPaymentConvergence(array $data, int $freshSeconds = 600): ?array
    {
        if ((int)($data['pay_status'] ?? 0) !== PayStatusEnum::PENDING) {
            return null;
        }
        if ((int)($data['order_status'] ?? 0) !== OrderStatusEnum::NORMAL) {
            return null;
        }
        $orderId = (int)($data['order_id'] ?? 0);
        if ($orderId <= 0) {
            return null;
        }
        $trade = (new PaymentTrade())
            ->where('order_id', '=', $orderId)
            ->where('platform', '=', 'wechat_virtual')
            ->where('trade_state', 'not in', [TradeStatusEnum::SUCCESS, TradeStatusEnum::REFUND])
            ->order(['trade_id' => 'desc'])
            ->find();
        if (empty($trade)) {
            return null;
        }
        $snapshot = PaymentTrade::decodePayloadSnapshot((string)($trade['payload_snapshot'] ?? ''));
        $queryOrder = (array)($snapshot['query_order'] ?? []);
        $timerQueryOrder = (array)($snapshot['timer_query_order'] ?? []);
        $hasPayNotify = !empty($snapshot['pay_notify']);
        $queryStatus = isset($queryOrder['status']) ? (int)$queryOrder['status'] : null;
        $timerQueryStatus = isset($timerQueryOrder['status']) ? (int)$timerQueryOrder['status'] : null;
        $isPendingStatus = in_array($queryStatus, [0, 1], true) || in_array($timerQueryStatus, [0, 1], true);
        $isPaidLikeStatus = $hasPayNotify
            || in_array($queryStatus, [2, 3], true)
            || in_array($timerQueryStatus, [2, 3], true);
        if (!$isPaidLikeStatus) {
            return null;
        }
        $queryAt = (int)($queryOrder['queried_at'] ?? 0);
        $timerQueryAt = (int)($timerQueryOrder['queried_at'] ?? 0);
        $recentAt = max($queryAt, $timerQueryAt, (int)($trade['update_time'] ?? 0), (int)($trade['create_time'] ?? 0));
        return [
            'trade_id' => (int)$trade['trade_id'],
            'out_trade_no' => (string)($trade['out_trade_no'] ?? ''),
            'query_status' => $queryStatus,
            'timer_query_status' => $timerQueryStatus,
            'recent_at' => $recentAt,
            'has_pay_notify' => $hasPayNotify,
            'is_paid_like' => $isPaidLikeStatus,
        ];
    }

    /**
     * 获取器：订单金额(含优惠折扣)
     * @param $value
     * @param $data
     * @return string
     */
    public function getOrderPriceAttr($value, $data): string
    {
        // 兼容旧数据：订单金额
        if ($value == 0) {
            return helper::bcadd(helper::bcsub($data['total_price'], $data['coupon_money']), $data['update_price']);
        }
        return $value;
    }

    /**
     * 获取器：改价金额（差价）
     * @param $value
     * @return array
     */
    public function getUpdatePriceAttr($value): array
    {
        return [
            'symbol' => $value < 0 ? '-' : '+',
            'value' => sprintf('%.2f', abs((float)$value))
        ];
    }

    /**
     * 获取器：付款时间
     * @param $value
     * @return false|string
     */
    public function getPayTimeAttr($value)
    {
        return \format_time($value);
    }

    /**
     * 获取器：发货时间
     * @param $value
     * @return false|string
     */
    public function getDeliveryTimeAttr($value)
    {
        return \format_time($value);
    }

    /**
     * 获取器：收货时间
     * @param $value
     * @return false|string
     */
    public function getReceiptTimeAttr($value)
    {
        return \format_time($value);
    }

    /**
     * 获取器：来源记录的参数
     * @param $json
     * @return array
     */
    public function getOrderSourceDataAttr($json): array
    {
        return $json ? helper::jsonDecode($json) : [];
    }

    /**
     * 修改器：来源记录的参数
     * @param array $data
     * @return string
     */
    public function setOrderSourceDataAttr(array $data): string
    {
        return helper::jsonEncode($data);
    }

    /**
     * 获取器：游戏平台
     * @param $value
     * @param $data
     * @return string
     */
    public function getGamePlatformAttr($value, $data): string
    {
        return $this->getServiceContactField($data, 'game_platform');
    }

    /**
     * 获取器：游戏账号ID
     * @param $value
     * @param $data
     * @return string
     */
    public function getGameAccountIdAttr($value, $data): string
    {
        return $this->getServiceContactField($data, 'game_account_id');
    }

    /**
     * 获取器：联系方式
     * @param $value
     * @param $data
     * @return string
     */
    public function getContactMobileAttr($value, $data): string
    {
        return $this->getServiceContactField($data, 'contact_mobile');
    }

    /**
     * 获取器：成年人下单确认
     * @param $value
     * @param $data
     * @return int
     */
    public function getAdultConfirmAttr($value, $data): int
    {
        $serviceContact = static::getServiceContactData($data);
        return static::normalizeAdultConfirmValue($serviceContact['adult_confirm'] ?? 0);
    }

    /**
     * 获取服务联系信息字段
     * @param array $data
     * @param string $field
     * @return string
     */
    private function getServiceContactField(array $data, string $field): string
    {
        $serviceContact = static::getServiceContactData($data);
        return (string)($serviceContact[$field] ?? '');
    }

    /**
     * 获取服务联系人信息
     * @param array|self $order
     * @return array
     */
    public static function getServiceContactData($order): array
    {
        return static::normalizeServiceContactData(static::getRawServiceContactData($order));
    }

    /**
     * 获取原始服务单联系信息
     * @param array|self $order
     * @return array
     */
    private static function getRawServiceContactData($order): array
    {
        $sourceData = $order['order_source_data'] ?? [];
        if (is_string($sourceData)) {
            $sourceData = helper::jsonDecode($sourceData) ?: [];
        }
        return (array)($sourceData['service_contact'] ?? []);
    }

    /**
     * 统一服务单联系信息结构
     * @param array $serviceContact
     * @return array
     */
    public static function normalizeServiceContactData(array $serviceContact): array
    {
        return [
            'game_platform' => trim((string)($serviceContact['game_platform'] ?? '')),
            'game_account_id' => trim((string)($serviceContact['game_account_id'] ?? '')),
            'contact_mobile' => trim((string)($serviceContact['contact_mobile'] ?? '')),
            'adult_confirm' => static::normalizeAdultConfirmValue($serviceContact['adult_confirm'] ?? 0),
        ];
    }

    /**
     * 规范化成年人下单确认值
     * @param mixed $value
     * @return int
     */
    public static function normalizeAdultConfirmValue($value): int
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if (is_numeric($value)) {
            return (int)$value === 1 ? 1 : 0;
        }
        $normalized = strtolower(trim((string)$value));
        return in_array($normalized, ['1', 'true', 'yes', 'on'], true) ? 1 : 0;
    }

    /**
     * 游戏平台文案
     * @param string $value
     * @return string
     */
    public static function getServiceGamePlatformText(string $value): string
    {
        if ($value === 'pc') {
            return '端游';
        }
        if ($value === 'mobile') {
            return '手游';
        }
        return '';
    }

    /**
     * 成年人下单确认文案
     * @param mixed $value
     * @return string
     */
    public static function getAdultConfirmText($value): string
    {
        return static::normalizeAdultConfirmValue($value) === 1 ? '已确认' : '未确认';
    }

    /**
     * 是否为服务订单
     * @param array|self $order
     * @return bool
     */
    public static function isServiceOrderData($order): bool
    {
        $serviceContact = static::getServiceContactData($order);
        if (
            !empty($serviceContact['game_platform'])
            || !empty($serviceContact['game_account_id'])
            || !empty($serviceContact['contact_mobile'])
            || static::normalizeAdultConfirmValue($serviceContact['adult_confirm'] ?? 0) === 1
        ) {
            return true;
        }
        $rawServiceContact = static::getRawServiceContactData($order);
        return !empty($rawServiceContact['contact_name'])
            || !empty($rawServiceContact['contact_mobile'])
            || !empty($rawServiceContact['time_preference']);
    }

    /**
     * 获取服务订单退款模式
     * @param array|self $order
     * @return string
     */
    public static function getServiceRefundMode($order, ?int $excludeRefundId = null): string
    {
        if (!static::isServiceOrderData($order)) {
            return self::SERVICE_REFUND_MODE_NONE;
        }
        if ((int)($order['pay_status'] ?? 0) !== PayStatusEnum::SUCCESS) {
            return self::SERVICE_REFUND_MODE_NONE;
        }
        if ((int)($order['order_status'] ?? 0) !== OrderStatusEnum::NORMAL) {
            return self::SERVICE_REFUND_MODE_NONE;
        }
        if (static::hasActiveServiceRefund($order, $excludeRefundId)) {
            return self::SERVICE_REFUND_MODE_NONE;
        }
        if ((int)($order['delivery_status'] ?? 0) === DeliveryStatusEnum::NOT_DELIVERED) {
            return self::SERVICE_REFUND_MODE_AUTO;
        }
        if (
            (int)($order['delivery_status'] ?? 0) === DeliveryStatusEnum::DELIVERED
            && (int)($order['receipt_status'] ?? 0) === ReceiptStatusEnum::NOT_RECEIVED
        ) {
            return self::SERVICE_REFUND_MODE_AUDIT;
        }
        return self::SERVICE_REFUND_MODE_NONE;
    }

    /**
     * 当前是否允许用户申请退款
     * @param array|self $order
     * @return bool
     */
    public static function canApplyServiceRefund($order): bool
    {
        if (static::isServiceOrderData($order)) {
            return static::getServiceRefundMode($order) !== self::SERVICE_REFUND_MODE_NONE;
        }
        return (int)($order['pay_status'] ?? 0) === PayStatusEnum::SUCCESS
            && (int)($order['order_status'] ?? 0) === OrderStatusEnum::NORMAL
            && (int)($order['delivery_status'] ?? 0) === DeliveryStatusEnum::NOT_DELIVERED
            && !static::hasActiveServiceRefund($order);
    }

    /**
     * 是否存在进行中的服务退款
     * @param array|self $order
     * @return bool
     */
    public static function hasActiveServiceRefund($order, ?int $excludeRefundId = null): bool
    {
        $data = $order instanceof self ? $order->getData() : (array)$order;
        foreach (($order['goods'] ?? []) as $goods) {
            if (
                !empty($goods['refund'])
                && (int)$goods['refund']['status'] === RefundStatusEnum::NORMAL
                && ($excludeRefundId === null || (int)($goods['refund']['order_refund_id'] ?? 0) !== $excludeRefundId)
            ) {
                return true;
            }
        }
        if (empty($data['order_id'])) {
            return false;
        }
        $query = (new OrderRefund)->where('order_id', '=', (int)$data['order_id'])
            ->where('type', '=', RefundTypeEnum::SERVICE)
            ->where('status', '=', RefundStatusEnum::NORMAL);
        if ($excludeRefundId !== null) {
            $query->where('order_refund_id', '<>', $excludeRefundId);
        }
        return $query->count() > 0;
    }

    /**
     * 获取整单可退金额
     * @param array|self $order
     * @return string
     */
    public static function getRefundableAmount($order): string
    {
        return sprintf('%.2f', (float)($order['pay_price'] ?? 0));
    }

    /**
     * 生成订单号
     * @return string
     */
    public function orderNo(): string
    {
        return OrderService::createOrderNo();
    }

    /**
     * 订单详情
     * @param $where
     * @param array $with
     * @return static|array|null
     */
    public static function detail($where, array $with = [])
    {
        is_array($where) ? $filter = $where : $filter['order_id'] = (int)$where;
        if (!array_key_exists('is_delete', $filter)) {
            $filter['is_delete'] = 0;
        }
        return self::get($filter, $with);
    }

    /**
     * 待支付订单详情
     * @param string $orderNo 订单号
     * @return null|static
     */
    public static function getPayDetail(string $orderNo): ?Order
    {
        $where = ['order_no' => $orderNo, 'is_delete' => 0];
        return static::detail($where, ['goods', 'user']);
    }

    /**
     * 批量获取订单列表
     * @param array $orderIds
     * @param array $with
     * @return array
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     */
    public function getListByIds(array $orderIds, array $with = []): array
    {
        $data = $this->getListByInArray('order_id', $orderIds, $with);
        return helper::arrayColumn2Key($data, 'order_id');
    }

    /**
     * 批量获取订单列表
     * @param $field
     * @param $data
     * @param array $with
     * @return \think\Collection
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    private function getListByInArray($field, $data, array $with = []): \think\Collection
    {
        return $this->with($with)
            ->where($field, 'in', $data)
            ->where('is_delete', '=', 0)
            ->select();
    }

    /**
     * 根据订单号批量查询
     * @param $orderNos
     * @param array $with
     * @return \think\Collection
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getListByOrderNos($orderNos, array $with = []): \think\Collection
    {
        return $this->getListByInArray('order_no', $orderNos, $with);
    }

    /**
     * 批量更新订单
     * @param $orderIds
     * @param $data
     * @return bool|false
     */
    public function onBatchUpdate($orderIds, $data): bool
    {
        return static::updateBase($data, [['order_id', 'in', $orderIds]]);
    }

    /**
     * 更新订单来源记录ID
     * @param int $orderId
     * @param int $soureId
     * @return bool
     */
    public static function updateOrderSourceId(int $orderId, int $soureId): bool
    {
        return static::updateBase(['order_source_id' => $soureId], $orderId);
    }

    /**
     * 记录是否已同步微信小程序发货信息管理
     * @param int $orderId
     * @param bool $status
     * @return bool
     */
    public static function updateSyncWeixinShipping(int $orderId, bool $status): bool
    {
        return static::updateBase(['sync_weixin_shipping' => (int)$status], $orderId);
    }
}
