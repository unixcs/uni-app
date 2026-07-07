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

namespace app\api\model;

use app\api\service\User as UserService;
use app\api\model\OrderGoods as OrderGoodsModel;
use app\common\model\Order as OrderModel;
use app\common\model\OrderRefund as OrderRefundModel;
use app\common\enum\order\refund\AuditStatus as AuditStatusEnum;
use app\common\enum\order\refund\RefundStatus as RefundStatusEnum;
use app\common\enum\order\refund\RefundType as RefundTypeEnum;
use app\common\enum\order\DeliveryStatus as DeliveryStatusEnum;
use app\common\enum\order\OrderStatus as OrderStatusEnum;
use app\common\enum\order\PayStatus as PayStatusEnum;
use app\store\model\OrderRefund as StoreOrderRefundModel;
use cores\exception\BaseException;

/**
 * 售后单模型
 * Class OrderRefund
 * @package app\api\model
 */
class OrderRefund extends OrderRefundModel
{
    /**
     * 构建统一的服务退款状态投影
     * @param array $data
     * @return array
     */
    public static function buildServiceProjection(array $data): array
    {
        $status = (int)($data['status'] ?? RefundStatusEnum::NORMAL);
        $auditStatus = (int)($data['audit_status'] ?? AuditStatusEnum::WAIT);

        if ($status === RefundStatusEnum::COMPLETED) {
            return [
                'state' => $status,
                'state_text' => '已退款',
                'service_state' => 'refunded',
                'service_state_text' => '已退款',
                'audit_status' => $auditStatus,
                'is_terminal' => true,
            ];
        }

        if ($status === RefundStatusEnum::CANCELLED) {
            return [
                'state' => $status,
                'state_text' => '已取消',
                'service_state' => 'cancelled',
                'service_state_text' => '已取消',
                'audit_status' => $auditStatus,
                'is_terminal' => true,
            ];
        }

        if ($status === RefundStatusEnum::REJECTED) {
            return [
                'state' => $status,
                'state_text' => '退款已拒绝',
                'service_state' => 'rejected',
                'service_state_text' => '退款已拒绝',
                'audit_status' => $auditStatus,
                'is_terminal' => true,
            ];
        }

        if ($auditStatus === AuditStatusEnum::WAIT) {
            return [
                'state' => $status,
                'state_text' => '退款审核中',
                'service_state' => 'reviewing',
                'service_state_text' => '退款审核中',
                'audit_status' => $auditStatus,
                'is_terminal' => false,
            ];
        }

        return [
            'state' => $status,
            'state_text' => '退款处理中',
            'service_state' => 'processing',
            'service_state_text' => '退款处理中',
            'audit_status' => $auditStatus,
            'is_terminal' => false,
        ];
    }

    /**
     * 隐藏字段
     * @var array
     */
    protected $hidden = [
        'store_id',
        'update_time',
        'type',
        'is_user_send',
        'express_id',
        'express_no',
        'address_id'
    ];

    /**
     * 追加字段
     * @var array
     */
    protected $append = [
        'state_text',   // 售后单状态文字描述
        'service_state',
        'service_state_text',
        'action_flags',
    ];

    /**
     * 售后单状态文字描述
     * @param $value
     * @param $data
     * @return string
     */
    public function getStateTextAttr($value, $data): string
    {
        return static::buildServiceProjection((array)$data)['state_text'] ?? (string)$value;
    }

    /**
     * 获取器：服务售后状态标识
     * @param $value
     * @param $data
     * @return string
     */
    public function getServiceStateAttr($value, $data): string
    {
        return static::buildServiceProjection((array)$data)['service_state'] ?? '';
    }

    /**
     * 获取器：服务售后状态文字
     * @param $value
     * @param $data
     * @return string
     */
    public function getServiceStateTextAttr($value, $data): string
    {
        return static::buildServiceProjection((array)$data)['service_state_text'] ?? (string)$value;
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
            'can_cancel' => $data['status'] == RefundStatusEnum::NORMAL && $data['audit_status'] == AuditStatusEnum::WAIT,
            'can_reapply' => $data['status'] == RefundStatusEnum::REJECTED,
        ];
    }

    /**
     * 获取用户售后单列表
     * @param int $state 售后单状态
     * @return \think\Paginator
     * @throws BaseException
     * @throws \think\db\exception\DbException
     */
    public function getList(int $state = -1): \think\Paginator
    {
        // 检索查询条件
        $filter = [
            ['type', '=', RefundTypeEnum::SERVICE]
        ];
        // 售后单状态
        $state > -1 && $filter[] = ['status', '=', $state];
        // 当前用户ID
        $userId = UserService::getCurrentLoginUserId();
        // 查询列表记录
        return $this->with(['orderGoods.image', 'orderData'])
            ->alias('refund')
            ->field('refund.*')
            ->join('order', 'order.order_id = refund.order_id')
            ->where($filter)
            ->where('refund.user_id', '=', $userId)
            ->where('order.is_delete', '=', 0)
            ->order(['refund.create_time' => 'desc'])
            ->paginate(15);
    }

    /**
     * 获取当前用户的售后单详情
     * @param int $orderRefundId 售后单ID
     * @param bool $isWith 是否关联
     * @return static|null
     * @throws BaseException
     */
    public static function getDetail(int $orderRefundId, bool $isWith = false): ?self
    {
        // 关联查询
        $with = $isWith ? ['orderGoods' => ['image'], 'orderData'] : [];
        // 获取记录
        $detail = (new static)->with($with)
            ->alias('refund')
            ->field('refund.*')
            ->join('order', 'order.order_id = refund.order_id')
            ->where('refund.user_id', '=', UserService::getCurrentLoginUserId())
            ->where('refund.order_refund_id', '=', $orderRefundId)
            ->where('refund.type', '=', RefundTypeEnum::SERVICE)
            ->where('order.is_delete', '=', 0)
            ->find();
        empty($detail) && throwError('未找到该售后单');
        return $detail;
    }

    /**
     * 获取当前用户的售后单数量(进行中的)
     * @return int
     * @throws BaseException
     */
    public static function getCountByUnderway(): int
    {
        $userId = UserService::getCurrentLoginUserId();
        return (new static)
            ->alias('refund')
            ->join('order', 'order.order_id = refund.order_id')
            ->where('refund.user_id', '=', $userId)
            ->where('refund.type', '=', RefundTypeEnum::SERVICE)
            ->where('refund.status', '=', 0)
            ->where('order.is_delete', '=', 0)
            ->count();
    }

    /**
     * 订单商品详情
     * @param int $orderGoodsId
     * @return OrderGoods|array|null
     * @throws BaseException
     */
    public function getRefundGoods(int $orderGoodsId)
    {
        $userId = UserService::getCurrentLoginUserId();
        $goods = OrderGoodsModel::detail(['order_goods_id' => $orderGoodsId, 'user_id' => $userId]);
        if (empty($goods)) {
            throwError('未找到订单商品信息');
        }
        $order = OrderModel::detail(['order_id' => $goods['order_id'], 'user_id' => $userId]);
        if (empty($order)) {
            throwError('订单不存在或不属于当前用户');
        }
        if ($this->hasActiveRefundByOrderId((int)$goods['order_id'])) {
            throwError('当前订单已存在进行中的售后单');
        }
        if ($order['pay_status'] != PayStatusEnum::SUCCESS) {
            throwError('未支付订单不允许申请售后');
        }
        if (OrderModel::isServiceOrderData($order)) {
            $refundMode = OrderModel::getServiceRefundMode($order);
            if ($refundMode === OrderModel::SERVICE_REFUND_MODE_NONE) {
                throwError('当前服务阶段不允许申请退款');
            }
        } elseif ($order['order_status'] != OrderStatusEnum::NORMAL) {
            throwError('当前订单状态不允许申请售后');
        } elseif ($order['delivery_status'] != DeliveryStatusEnum::NOT_DELIVERED) {
            throwError('当前订单状态不允许申请售后');
        }
        return $goods;
    }

    /**
     * 用户发货
     * @param array $data
     * @return bool|false
     */
    public function delivery(array $data): bool
    {
        $this->error = '当前售后单不支持物流发货';
        return false;
    }

    /**
     * 新增售后单记录
     * @param int $orderGoodsId 订单商品ID
     * @param array $data 用户提交的表单数据
     * @return mixed
     * @throws BaseException
     */
    public function apply(int $orderGoodsId, array $data)
    {
        // 订单商品详情
        $goods = $this->getRefundGoods($orderGoodsId);
        if ($this->hasActiveRefundByOrderId((int)$goods['order_id'])) {
            throwError('当前订单已存在进行中的售后单');
        }
        return $this->transaction(function () use ($orderGoodsId, $data, $goods) {
            $order = OrderModel::detail(['order_id' => $goods['order_id'], 'user_id' => UserService::getCurrentLoginUserId()], ['goods']);
            if (empty($order)) {
                throwError('订单不存在或不属于当前用户');
            }
            if ($this->hasActiveRefundByOrderId((int)$goods['order_id'])) {
                throwError('当前订单已存在进行中的售后单');
            }

            $refundMode = OrderModel::isServiceOrderData($order)
                ? OrderModel::getServiceRefundMode($order)
                : OrderModel::SERVICE_REFUND_MODE_AUTO;

            if ($refundMode === OrderModel::SERVICE_REFUND_MODE_NONE) {
                throwError('当前服务阶段不允许申请退款');
            }

            $isAutoRefund = $refundMode === OrderModel::SERVICE_REFUND_MODE_AUTO;
            // 新增售后单记录
            $this->save([
                'order_goods_id' => $orderGoodsId,
                'order_id' => $goods['order_id'],
                'user_id' => UserService::getCurrentLoginUserId(),
                'type' => RefundTypeEnum::SERVICE,
                'apply_desc' => (string)($data['content'] ?? '用户申请退款'),
                'audit_status' => $isAutoRefund ? AuditStatusEnum::REVIEWED : AuditStatusEnum::WAIT,
                'status' => RefundStatusEnum::NORMAL,
                'store_id' => self::$storeId
            ]);

            if ($isAutoRefund) {
                $storeRefund = StoreOrderRefundModel::detail((int)$this['order_refund_id'], ['orderGoods']);
                if (empty($storeRefund)) {
                    throwError('退款单创建失败');
                }
                if (!$storeRefund->completeAutoRefund($data['content'] ?? '用户申请退款')) {
                    throwError($storeRefund->getError() ?: '自动退款失败');
                }
            }
            return true;
        });
    }

    /**
     * 当前订单是否存在进行中的售后单
     * @param int $orderId
     * @return bool
     */
    private function hasActiveRefundByOrderId(int $orderId): bool
    {
        return (new static)
            ->where('type', '=', RefundTypeEnum::SERVICE)
            ->where('order_id', '=', $orderId)
            ->where('status', '=', RefundStatusEnum::NORMAL)
            ->count() > 0;
    }
}
