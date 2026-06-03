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

use app\api\service\User as UserService;
use app\api\model\OrderGoods as OrderGoodsModel;
use app\common\model\Order as OrderModel;
use app\common\model\OrderRefund as OrderRefundModel;
use app\common\enum\order\refund\AuditStatus as AuditStatusEnum;
use app\common\enum\order\refund\RefundStatus as RefundStatusEnum;
use app\common\enum\order\refund\RefundType as RefundTypeEnum;
use app\common\enum\order\DeliveryType as DeliveryTypeEnum;
use app\common\enum\order\DeliveryStatus as DeliveryStatusEnum;
use app\common\enum\order\OrderStatus as OrderStatusEnum;
use app\common\enum\order\PayStatus as PayStatusEnum;
use cores\exception\BaseException;

/**
 * 售后单模型
 * Class OrderRefund
 * @package app\api\model
 */
class OrderRefund extends OrderRefundModel
{
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
        // 已完成
        if ($data['status'] == RefundStatusEnum::COMPLETED) {
            return '已退款';
        }
        // 已取消
        if ($data['status'] == RefundStatusEnum::CANCELLED) {
            return '已取消';
        }
        // 已拒绝
        if ($data['status'] == RefundStatusEnum::REJECTED) {
            return '已拒绝退款';
        }
        // 进行中
        if ($data['status'] == RefundStatusEnum::NORMAL) {
            if ($data['audit_status'] == AuditStatusEnum::WAIT) {
                return '退款审核中';
            }
            return '退款处理中';
        }
        return $value;
    }

    /**
     * 获取器：服务售后状态标识
     * @param $value
     * @param $data
     * @return string
     */
    public function getServiceStateAttr($value, $data): string
    {
        if ($data['status'] == RefundStatusEnum::COMPLETED) {
            return 'refunded';
        }
        if ($data['status'] == RefundStatusEnum::CANCELLED) {
            return 'cancelled';
        }
        if ($data['status'] == RefundStatusEnum::REJECTED) {
            return 'rejected';
        }
        return $data['audit_status'] == AuditStatusEnum::WAIT ? 'reviewing' : 'processing';
    }

    /**
     * 获取器：服务售后状态文字
     * @param $value
     * @param $data
     * @return string
     */
    public function getServiceStateTextAttr($value, $data): string
    {
        return $this->getStateTextAttr($value, $data);
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
            'can_cancel' => $data['status'] == RefundStatusEnum::NORMAL,
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
            ->where($filter)
            ->where('user_id', '=', $userId)
            ->order(['create_time' => 'desc'])
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
        $detail = static::detail([
            'user_id' => UserService::getCurrentLoginUserId(),
            'order_refund_id' => $orderRefundId,
            'type' => RefundTypeEnum::SERVICE,
        ], $with);
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
        return (new static)->where('user_id', '=', $userId)
            ->where('type', '=', RefundTypeEnum::SERVICE)
            ->where('status', '=', 0)
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
        if ($order['pay_status'] != PayStatusEnum::SUCCESS) {
            throwError('未支付订单不允许申请售后');
        }
        if ($order['order_status'] != OrderStatusEnum::NORMAL) {
            throwError('当前订单状态不允许申请售后');
        }
        if ($order['delivery_status'] != DeliveryStatusEnum::NOT_DELIVERED) {
            throwError('服务已开始，不允许申请售后');
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
            // 新增售后单记录
            $this->save([
                'order_goods_id' => $orderGoodsId,
                'order_id' => $goods['order_id'],
                'user_id' => UserService::getCurrentLoginUserId(),
                'type' => RefundTypeEnum::SERVICE,
                'apply_desc' => $data['content'],
                'audit_status' => AuditStatusEnum::WAIT,
                'status' => 0,
                'store_id' => self::$storeId
            ]);
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
