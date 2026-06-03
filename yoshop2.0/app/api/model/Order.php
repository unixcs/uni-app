<?php
// +----------------------------------------------------------------------
// | 萤火商城系统 [ 致力于通过产品和服务，帮助商家高效化开拓市场 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2017~2025 https://www.yiovo.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed 这不是一个自由软件，不允许对程序代码以任何形式任何目的的再发行
// +----------------------------------------------------------------------
// | Author: 萤火科技 <admin@yiovo.com>
// +----------------------------------------------------------------------
declare (strict_types=1);

namespace app\api\model;

use app\api\model\{Goods as GoodsModel, OrderRefund as OrderRefundModel, Setting as SettingModel};
use app\api\service\{User as UserService, order\source\Factory as OrderSourceFactory};
use app\common\model\Order as OrderModel;
use app\common\service\{Order as OrderService, order\Complete as OrderCompleteService};
use app\common\enum\{
    order\DataType as DataTypeEnum,
    payment\Method as PaymentMethodEnum,
    order\PayStatus as PayStatusEnum,
    order\OrderStatus as OrderStatusEnum,
    order\DeliveryType as DeliveryTypeEnum,
    order\ReceiptStatus as ReceiptStatusEnum,
    order\DeliveryStatus as DeliveryStatusEnum
};
use app\common\library\helper;
use app\common\enum\order\refund\RefundType as RefundTypeEnum;
use app\common\enum\order\refund\RefundStatus as RefundStatusEnum;
use app\common\enum\order\refund\AuditStatus as RefundAuditStatusEnum;
use cores\exception\BaseException;

/**
 * 订单模型
 * Class Order
 * @package app\api\model
 */
class Order extends OrderModel
{
    /**
     * 隐藏字段
     * @var array
     */
    protected $hidden = [
        'merchant_remark',
        'buyer_remark',
        'trade_id',
        'order_source_data',
        'delivery_type',
        'delivery_status',
        'receipt_status',
        'delivery_time',
        'receipt_time',
        'address_id',
        'is_settled',
        'is_delete',
        'store_id',
        'update_time'
    ];

    /**
     * 追加字段
     * @var array
     */
    protected $append = [
        'state_text',
        'service_state',
        'service_state_text',
        'package_name',
        'package_goods',
        'payment_amount',
        'service_contact',
        'remark',
        'time_preference',
        'service_times',
        'timestamps',
        'is_service_order',
        'action_flags',
        'refund_state',
        'refund_state_text',
        'refund_info',
        'can_cancel',
        'can_pay',
        'can_apply_refund',
        'can_refund',
    ];

    // 信息提示
    private string $message = '';

    /**
     * 立即购买：获取订单商品列表
     * @param int $goodsId 商品ID
     * @param string $goodsSkuId 商品SKU
     * @param int $goodsNum 购买数量
     * @return mixed
     * @throws BaseException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getOrderGoodsListByNow(int $goodsId, string $goodsSkuId, int $goodsNum)
    {
        // 获取商品列表
        $model = new GoodsModel;
        $goodsList = $model->setEnableGradeMoney(false)->getListByIdsFromApi([$goodsId]);
        if ($goodsList->isEmpty()) {
            throwError('未找到商品信息');
        }
        // 隐藏冗余的属性
        $goodsList->hidden(GoodsModel::getHidden(['content', 'goods_images', 'images']));
        foreach ($goodsList as &$item) {
            // 商品sku信息
            $item['skuInfo'] = GoodsModel::getSkuInfo($item, $goodsSkuId, false);
            // 商品封面 (优先sku封面)
            $item['goods_image'] = $item['skuInfo']['goods_image'] ?: $item['goods_image'];
            // 商品单价
            $item['goods_price'] = $item['skuInfo']['goods_price'];
            // 商品购买数量
            $item['total_num'] = $goodsNum;
            // 商品SKU索引
            $item['goods_sku_id'] = $item['skuInfo']['goods_sku_id'];
            // 商品购买总金额
            $item['total_price'] = helper::bcmul($item['goods_price'], $goodsNum);
        }
        return $goodsList;
    }

    /**
     * 获取用户订单列表
     * @param string $dataType 订单类型 (all全部 payment待付款 deliver待发货 received待收货 comment待评价)
     * @return \think\Paginator
     * @throws BaseException
     * @throws \think\db\exception\DbException
     */
    public function getList(string $dataType = 'all'): \think\Paginator
    {
        // 设置订单类型条件
        $dataTypeFilter = $this->getFilterDataType($dataType);
        // 当前用户ID
        $userId = UserService::getCurrentLoginUserId();
        $query = $this->with(['goods' => ['image', 'refund']])
            ->where('user_id', '=', $userId)
            ->where('is_delete', '=', 0);
        if (in_array($dataType, [DataTypeEnum::APPLY_CANCEL, 'refund'], true)) {
            $refundOrderIds = $this->getRefundingOrderIds($userId);
            $query->where('order_id', 'in', empty($refundOrderIds) ? [0] : $refundOrderIds);
        } else {
            $query->where($dataTypeFilter);
        }
        // 查询列表数据
        return $query->order(['create_time' => 'desc'])->paginate(15);
    }

    /**
     * 取消订单
     * @return bool|mixed
     */
    public function cancel()
    {
        // 判断订单是否允许取消
        $orderSource = OrderSourceFactory::getFactory($this['order_source']);
        if (!$orderSource->checkOrderByCancel($this)) {
            $this->error = $orderSource->getError();
            return false;
        }
        // 订单是否已支付
        $isPay = $this['pay_status'] == PayStatusEnum::SUCCESS;
        // 提示信息
        $this->message = $isPay ? '退款申请已提交，需等待商家审核' : '订单已取消成功';
        // 订单取消事件
        return $this->transaction(function () use ($isPay) {
            if ($isPay) {
                $firstGoods = $this['goods'][0] ?? null;
                if (empty($firstGoods['order_goods_id'])) {
                    throwError('订单商品信息不存在，无法申请退款');
                }
                $refundModel = new OrderRefundModel;
                if (!$refundModel->apply((int)$firstGoods['order_goods_id'], ['content' => '用户申请取消订单'])) {
                    throwError($refundModel->getError() ?: '提交退款申请失败');
                }
                return true;
            }
            // 订单取消事件
            !$isPay && OrderService::cancelEvent($this, false);
            // 更新订单状态: 未付款订单直接关闭
            return $this->save(['order_status' => OrderStatusEnum::CANCELLED]);
        });
    }

    /**
     * 确认收货
     * @return bool|mixed
     */
    public function receipt()
    {
        $this->error = '服务订单不支持确认收货，请等待商家完成服务';
        return false;
    }

    /**
     * 获取当前用户订单数量
     * @param string $dataType 订单类型 (all全部 payment待付款 deliver待发货 received待收货 comment待评价)
     * @return int
     * @throws BaseException
     */
    public function getCount(string $dataType = 'all'): int
    {
        // 设置订单类型条件
        $dataTypeFilter = $this->getFilterDataType($dataType);
        // 当前用户ID
        $userId = UserService::getCurrentLoginUserId();
        $query = $this->where('user_id', '=', $userId)
            ->where('order_status', '<>', 20)
            ->where('is_delete', '=', 0);
        if (in_array($dataType, [DataTypeEnum::APPLY_CANCEL, 'refund'], true)) {
            $refundOrderIds = $this->getRefundingOrderIds($userId);
            $query->where('order_id', 'in', empty($refundOrderIds) ? [0] : $refundOrderIds);
        } else {
            $query->where($dataTypeFilter);
        }
        // 查询数据
        return $query->count();
    }

    /**
     * 设置订单类型条件
     * @param string $dataType
     * @return array
     */
    private function getFilterDataType(string $dataType): array
    {
        // 筛选条件
        $filter = [];
        // 订单数据类型
        switch ($dataType) {
            case 'all':
                break;
            case 'payment':
                $filter[] = ['pay_status', '=', PayStatusEnum::PENDING];
                $filter[] = ['order_status', '=', OrderStatusEnum::NORMAL];
                break;
            case DataTypeEnum::CONTACT:
                $filter = [
                    ['pay_status', '=', PayStatusEnum::SUCCESS],
                    ['delivery_status', '=', DeliveryStatusEnum::NOT_DELIVERED],
                    ['order_status', '=', OrderStatusEnum::NORMAL]
                ];
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
            case 'received':
                $filter = [
                    ['pay_status', '=', PayStatusEnum::SUCCESS],
                    ['delivery_status', '=', DeliveryStatusEnum::DELIVERED],
                    ['receipt_status', '=', ReceiptStatusEnum::NOT_RECEIVED],
                    ['order_status', '=', OrderStatusEnum::NORMAL]
                ];
                break;
            case 'comment':
                $filter[] = ['order.order_id', '=', 0];
                break;
            case DataTypeEnum::COMPLETE:
                $filter[] = ['order_status', '=', OrderStatusEnum::COMPLETED];
                break;
            case DataTypeEnum::CANCEL:
                $filter[] = ['order_status', '=', OrderStatusEnum::CANCELLED];
                break;
            case DataTypeEnum::APPLY_CANCEL:
            case 'refund':
                break;
        }
        return $filter;
    }

    /**
     * 获取用户订单详情(含关联数据)
     * @param int $orderId 订单ID
     * @param bool $onlyCurrentUser 只查询当前登录用户的记录
     * @return Order|array|null
     * @throws BaseException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public static function getUserOrderDetail(int $orderId, bool $onlyCurrentUser = true)
    {
        // 查询订单记录
        $with = ['goods' => ['image', 'refund']];
        $order = static::getDetail($orderId, $with, $onlyCurrentUser);
        // 该订单是否允许申请售后
        $order['can_refund'] = static::isAllowRefund($order);
        return $order;
    }

    /**
     * 获取未支付的订单详情(用于订单支付)
     * @param int $orderId 订单ID
     * @return array
     * @throws BaseException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public static function getUnpaidOrderDetail(int $orderId): array
    {
        // 获取订单详情
        $orderInfo = static::getDetail($orderId);
        // 验证订单状态
        if ($orderInfo['order_status'] != OrderStatusEnum::NORMAL) {
            throwError('当前订单状态不允许支付');
        }
        // 未支付订单的过期时间
        $orderCloseTime = SettingModel::getOrderCloseTime() * 60 * 60;
        // 订单超时截止时间
        $expirationTime = $orderInfo->getData('create_time') + $orderCloseTime;
        if ($orderCloseTime > 0 && $expirationTime <= time()) {
            throwError('当前订单支付已超时，请重新下单');
        }
        // 仅返回需要的数据
        return [
            'orderId' => $orderInfo['order_id'],
            'order_no' => $orderInfo['order_no'],
            'pay_price' => $orderInfo['pay_price'],
            'pay_status' => $orderInfo['pay_status'],
            'order_status' => $orderInfo['order_status'],
            'create_time' => $orderInfo['create_time'],
            'showExpiration' => $orderCloseTime > 0,
            'expirationTime' => \format_time($expirationTime),
        ];
    }

    /**
     * 获取用户订单详情(仅订单记录)
     * @param int $orderId
     * @param array $with
     * @param bool $onlyCurrentUser 只查询当前登录用户的记录
     * @return Order|array|null
     * @throws BaseException
     */
    public static function getDetail(int $orderId, array $with = [], bool $onlyCurrentUser = true)
    {
        // 查询条件
        $where = ['order_id' => $orderId];
        $onlyCurrentUser && $where['user_id'] = UserService::getCurrentLoginUserId();
        // 查询订单记录
        $order = static::detail($where, $with);
        empty($order) && throwError('订单不存在');
        return $order;
    }

    /**
     * 获取当前用户待处理的订单数量
     * @return array
     * @throws BaseException
     */
    public function getTodoCounts(): array
    {
        return [
            'payment' => $this->getCount('payment'),      // 待支付
            'contact' => $this->getCount('contact'),      // 待联系
            'delivery' => $this->getCount('delivery'),    // 服务中
            'in_service' => $this->getCount('delivery'),  // 服务中（主key）
            'refund' => OrderRefundModel::getCountByUnderway(),  // 退款处理中
            'received' => $this->getCount('received'),    // 兼容旧待收货
            'saleRefund' => OrderRefundModel::getCountByUnderway(),  // 旧售后单统计兼容
        ];
    }

    /**
     * 获取器：订单状态文字描述
     * @param $value
     * @param $data
     * @return string
     */
    public function getStateTextAttr($value, $data): string
    {
        return parent::getStateTextAttr($value, $data);
    }

    /**
     * 获取器：服务状态标识
     * @param $value
     * @param $data
     * @return string
     */
    public function getServiceStateAttr($value, $data): string
    {
        if (!static::isServiceOrderData($data)) {
            return $this->getPhysicalOrderState($data);
        }
        if ($data['order_status'] == OrderStatusEnum::COMPLETED) {
            return 'completed';
        }
        if ($data['order_status'] == OrderStatusEnum::CANCELLED) {
            return $data['pay_status'] == PayStatusEnum::SUCCESS ? 'refunded' : 'closed';
        }
        if ($data['order_status'] == OrderStatusEnum::APPLY_CANCEL) {
            return $data['pay_status'] == PayStatusEnum::SUCCESS ? 'refund_pending' : 'closing';
        }
        if (static::hasActiveRefund($this)) {
            return 'refund_pending';
        }
        if ($data['pay_status'] == PayStatusEnum::PENDING) {
            return 'pending_payment';
        }
        if ($data['pay_status'] == PayStatusEnum::SUCCESS && $data['delivery_status'] == DeliveryStatusEnum::NOT_DELIVERED) {
            return 'pending_contact';
        }
        if (
            $data['pay_status'] == PayStatusEnum::SUCCESS
            && $data['delivery_status'] == DeliveryStatusEnum::DELIVERED
            && $data['receipt_status'] == ReceiptStatusEnum::NOT_RECEIVED
        ) {
            return 'in_service';
        }
        return 'service';
    }

    /**
     * 获取实物订单状态标识
     * @param array $data
     * @return string
     */
    private function getPhysicalOrderState(array $data): string
    {
        if ($data['order_status'] == OrderStatusEnum::COMPLETED) {
            return 'completed';
        }
        if ($data['order_status'] == OrderStatusEnum::CANCELLED) {
            return $data['pay_status'] == PayStatusEnum::SUCCESS ? 'refunded' : 'closed';
        }
        if ($data['order_status'] == OrderStatusEnum::APPLY_CANCEL) {
            return $data['pay_status'] == PayStatusEnum::SUCCESS ? 'refund_pending' : 'closing';
        }
        if (static::hasActiveRefund($this)) {
            return 'refund_pending';
        }
        if ($data['pay_status'] == PayStatusEnum::PENDING) {
            return 'pending_payment';
        }
        if ($data['pay_status'] == PayStatusEnum::SUCCESS && $data['delivery_status'] == DeliveryStatusEnum::NOT_DELIVERED) {
            return 'pending_delivery';
        }
        if (
            $data['pay_status'] == PayStatusEnum::SUCCESS
            && $data['delivery_status'] == DeliveryStatusEnum::DELIVERED
            && $data['receipt_status'] == ReceiptStatusEnum::NOT_RECEIVED
        ) {
            return 'pending_receipt';
        }
        return 'normal';
    }

    /**
     * 获取器：服务状态文字
     * @param $value
     * @param $data
     * @return string
     */
    public function getServiceStateTextAttr($value, $data): string
    {
        return $this->getStateTextAttr($value, $data);
    }

    /**
     * 获取器：套餐名称
     * @param $value
     * @return string
     */
    public function getPackageNameAttr($value): string
    {
        $first = $this['goods'][0] ?? [];
        return (string)($first['goods_name'] ?? '');
    }

    /**
     * 获取器：套餐商品信息
     * @param $value
     * @return array
     */
    public function getPackageGoodsAttr($value): array
    {
        $items = [];
        foreach ($this['goods'] ?? [] as $goods) {
            $items[] = [
                'order_goods_id' => (int)($goods['order_goods_id'] ?? 0),
                'goods_id' => (int)($goods['goods_id'] ?? 0),
                'goods_name' => (string)($goods['goods_name'] ?? ''),
                'goods_image' => (string)($goods['goods_image'] ?? ''),
                'goods_price' => (string)($goods['goods_price'] ?? ''),
                'total_num' => (int)($goods['total_num'] ?? 0),
                'total_price' => (string)($goods['total_price'] ?? ''),
                'goods_props' => $goods['goods_props'] ?? [],
            ];
        }
        return $items;
    }

    /**
     * 获取器：支付金额
     * @param $value
     * @return string
     */
    public function getPaymentAmountAttr($value): string
    {
        return (string)$this['pay_price'];
    }

    /**
     * 获取器：服务联系信息
     * @param $value
     * @return array
     */
    public function getServiceContactAttr($value): array
    {
        return [
            'contact_name' => (string)$this['contact_name'],
            'contact_mobile' => (string)$this['contact_mobile'],
            'time_preference' => (string)$this['time_preference'],
        ];
    }

    /**
     * 获取器：买家备注
     * @param $value
     * @return string
     */
    public function getRemarkAttr($value): string
    {
        return (string)($this['buyer_remark'] ?? '');
    }

    /**
     * 获取器：服务时间信息
     * @param $value
     * @return array
     */
    public function getServiceTimesAttr($value): array
    {
        return [
            'created_at' => \format_time((int)$this->getData('create_time')),
            'paid_at' => \format_time((int)$this->getData('pay_time')),
            'service_started_at' => \format_time((int)$this->getData('delivery_time')),
            'completed_at' => \format_time((int)$this->getData('receipt_time')),
        ];
    }

    /**
     * 获取器：服务状态时间戳
     * @param $value
     * @return array
     */
    public function getTimestampsAttr($value): array
    {
        return $this->getServiceTimesAttr($value);
    }

    /**
     * 获取器：动作标识
     * @param $value
     * @param $data
     * @return array
     */
    public function getActionFlagsAttr($value, $data): array
    {
        return [
            'can_cancel' => $this->getCanCancelAttr($value, $data),
            'can_pay' => $this->getCanPayAttr($value, $data),
            'can_apply_refund' => $this->getCanApplyRefundAttr($value, $data),
        ];
    }

    /**
     * 获取器：是否服务单
     * @param $value
     * @param $data
     * @return bool
     */
    public function getIsServiceOrderAttr($value, $data): bool
    {
        return static::isServiceOrderData($data);
    }

    /**
     * 获取器：服务售后状态
     * @param $value
     * @param $data
     * @return int
     */
    public function getRefundStateAttr($value, $data): int
    {
        $refund = $this->getLatestRefund();
        return empty($refund) ? 0 : (int)$refund['status'];
    }

    /**
     * 获取器：服务售后状态文字
     * @param $value
     * @param $data
     * @return string
     */
    public function getRefundStateTextAttr($value, $data): string
    {
        $refund = $this->getLatestRefund();
        if (empty($refund)) {
            return '';
        }
        if ($refund['status'] == RefundStatusEnum::NORMAL) {
            return $refund['audit_status'] == RefundAuditStatusEnum::WAIT ? '退款审核中' : '退款处理中';
        }
        if ($refund['status'] == RefundStatusEnum::REJECTED) {
            return '退款已拒绝';
        }
        if ($refund['status'] == RefundStatusEnum::COMPLETED) {
            return '退款成功';
        }
        if ($refund['status'] == RefundStatusEnum::CANCELLED) {
            return '退款已取消';
        }
        return '';
    }

    /**
     * 获取器：退款信息摘要
     * @param $value
     * @return array|null
     */
    public function getRefundInfoAttr($value): ?array
    {
        $refund = $this->getLatestRefund();
        if (empty($refund)) {
            return null;
        }
        return [
            'order_refund_id' => (int)($refund['order_refund_id'] ?? 0),
            'state' => (int)($refund['status'] ?? 0),
            'state_text' => $this->getRefundStateTextAttr('', []),
            'service_state' => (string)$this['refund_state'],
            'service_state_text' => (string)$this['refund_state_text'],
            'apply_desc' => (string)($refund['apply_desc'] ?? ''),
            'refuse_desc' => (string)($refund['refuse_desc'] ?? ''),
            'refund_money' => $refund['refund_money'] ?? '',
            'audit_status' => (int)($refund['audit_status'] ?? 0),
        ];
    }

    /**
     * 获取器：是否允许申请售后
     * @param $value
     * @param $data
     * @return bool
     */
    public function getCanRefundAttr($value, $data): bool
    {
        return static::isAllowRefund($this);
    }

    /**
     * 获取器：是否允许取消
     * @param $value
     * @param $data
     * @return bool
     */
    public function getCanCancelAttr($value, $data): bool
    {
        return $data['pay_status'] == PayStatusEnum::PENDING && $data['order_status'] == OrderStatusEnum::NORMAL;
    }

    /**
     * 获取器：是否允许支付
     * @param $value
     * @param $data
     * @return bool
     */
    public function getCanPayAttr($value, $data): bool
    {
        return $data['pay_status'] == PayStatusEnum::PENDING && $data['order_status'] == OrderStatusEnum::NORMAL;
    }

    /**
     * 获取器：是否允许申请退款
     * @param $value
     * @param $data
     * @return bool
     */
    public function getCanApplyRefundAttr($value, $data): bool
    {
        return static::isAllowRefund($this);
    }

    // 返回提示信息
    public function getMessage(): string
    {
        return $this->message;
    }

    /**
     * 当前订单是否允许申请售后
     * @param Order $order
     * @return bool
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    private static function isAllowRefund(self $order): bool
    {
        return $order['pay_status'] == PayStatusEnum::SUCCESS
            && $order['order_status'] == OrderStatusEnum::NORMAL
            && $order['delivery_status'] == DeliveryStatusEnum::NOT_DELIVERED
            && !static::hasActiveRefund($order);
    }

    /**
     * 是否存在进行中的售后单
     * @param self $order
     * @return bool
     */
    private static function hasActiveRefund(self $order): bool
    {
        if (empty($order['order_id'])) {
            return false;
        }
        return (new OrderRefundModel)
            ->where('type', '=', RefundTypeEnum::SERVICE)
            ->where('order_id', '=', (int)$order['order_id'])
            ->where('status', '=', RefundStatusEnum::NORMAL)
            ->count() > 0;
    }

    /**
     * 获取进行中的售后单
     * @return array|null
     */
    private function getActiveRefund(): ?array
    {
        if (empty($this['order_id'])) {
            return null;
        }
        $refund = (new OrderRefundModel)
            ->where('type', '=', RefundTypeEnum::SERVICE)
            ->where('order_id', '=', (int)$this['order_id'])
            ->where('status', '=', RefundStatusEnum::NORMAL)
            ->order(['create_time' => 'desc', 'order_refund_id' => 'desc'])
            ->find();
        return empty($refund) ? null : $refund->toArray();
    }

    /**
     * 获取最近一条售后单
     * @return array|null
     */
    private function getLatestRefund(): ?array
    {
        if (empty($this['order_id'])) {
            return null;
        }
        $refund = (new OrderRefundModel)
            ->where('type', '=', RefundTypeEnum::SERVICE)
            ->where('order_id', '=', (int)$this['order_id'])
            ->order(['create_time' => 'desc', 'order_refund_id' => 'desc'])
            ->find();
        return empty($refund) ? null : $refund->toArray();
    }

    /**
     * 获取当前用户退款中的订单ID列表
     * @param int $userId
     * @return array
     */
    private function getRefundingOrderIds(int $userId): array
    {
        return array_values(array_unique((new OrderRefundModel)
            ->where('type', '=', RefundTypeEnum::SERVICE)
            ->where('user_id', '=', $userId)
            ->where('status', '=', RefundStatusEnum::NORMAL)
            ->column('order_id')));
    }
}
