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
use app\common\model\PaymentTrade as PaymentTradeModel;
use app\common\model\PaymentIosRefundInquiry as IosRefundInquiryModel;
use app\common\enum\order\refund\AuditStatus as AuditStatusEnum;
use app\common\enum\order\refund\RefundStatus as RefundStatusEnum;
use app\common\enum\order\refund\RefundType as RefundTypeEnum;
use app\common\enum\order\DeliveryStatus as DeliveryStatusEnum;
use app\common\enum\order\OrderStatus as OrderStatusEnum;
use app\common\enum\order\PayStatus as PayStatusEnum;
use app\common\enum\order\iosRefund\RiskSource as IosRiskSourceEnum;
use app\common\enum\order\iosRefund\RiskStatus as IosRiskStatusEnum;
use app\common\service\order\IosRefundRisk as IosRefundRiskService;
use app\common\service\order\Refund as RefundService;
use app\common\library\helper;
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
            $projection = [
                'state' => $status,
                'state_text' => '已退款',
                'service_state' => 'refunded',
                'service_state_text' => '已退款',
                'audit_status' => $auditStatus,
                'is_terminal' => true,
            ];
        } elseif ($status === RefundStatusEnum::CANCELLED) {
            $projection = [
                'state' => $status,
                'state_text' => '已取消',
                'service_state' => 'cancelled',
                'service_state_text' => '已取消',
                'audit_status' => $auditStatus,
                'is_terminal' => true,
            ];
        } elseif ($status === RefundStatusEnum::REJECTED) {
            $projection = [
                'state' => $status,
                'state_text' => '退款已拒绝',
                'service_state' => 'rejected',
                'service_state_text' => '退款已拒绝',
                'audit_status' => $auditStatus,
                'is_terminal' => true,
            ];
        } elseif ($auditStatus === AuditStatusEnum::WAIT) {
            $projection = [
                'state' => $status,
                'state_text' => '退款审核中',
                'service_state' => 'reviewing',
                'service_state_text' => '退款审核中',
                'audit_status' => $auditStatus,
                'is_terminal' => false,
            ];
        } else {
            $projection = [
                'state' => $status,
                'state_text' => '退款处理中',
                'service_state' => 'processing',
                'service_state_text' => '退款处理中',
                'audit_status' => $auditStatus,
                'is_terminal' => false,
            ];
        }

        $orderData = $data['orderData'] ?? [];
        $trade = PaymentTradeModel::resolveVirtualTradeForRefundContext(
            (int)($data['order_id'] ?? ($orderData['order_id'] ?? 0)),
            (int)($orderData['trade_id'] ?? 0),
            (int)($data['order_refund_id'] ?? 0)
        );
        if (empty($orderData) && !empty($data['order_id'])) {
            $orderData = \app\common\model\Order::detail((int)$data['order_id']);
        }
        $riskProjection = IosRefundRiskService::buildProjection(
            $orderData,
            $trade,
            $data,
            !empty($data['ios_refund_latest_inquiry']) ? (array)$data['ios_refund_latest_inquiry'] : null
        );
        $projection = array_merge($projection, $riskProjection, [
            'refund_entry_mode' => (string)($riskProjection['refund_entry_mode'] ?? 'developer_refund'),
            'refund_guidance' => (string)($riskProjection['refund_guidance'] ?? ''),
            'display_state' => (string)($riskProjection['refund_display_state'] ?? ''),
            'display_state_text' => (string)($riskProjection['refund_display_state_text'] ?? ''),
        ]);
        if (!empty($projection['ios_apple_refund_required']) && $projection['display_state_text'] !== '') {
            $projection['state_text'] = $projection['display_state_text'];
            $projection['service_state'] = $projection['display_state'] ?: $projection['service_state'];
            $projection['service_state_text'] = $projection['display_state_text'];
        }
        return $projection;
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
        'refund_guidance',
        'refund_entry_mode',
        'ios_apple_refund_required',
        'ios_refund_risk_status',
        'ios_refund_risk_text',
        'ios_refund_inquiry_received',
        'latest_ios_refund_inquiry',
        'merchant_refund_review_status',
        'display_state',
        'display_state_text',
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
        return $this->getServiceProjectionForAttr((array)$data)['state_text'] ?? (string)$value;
    }

    /**
     * 获取器：服务售后状态标识
     * @param $value
     * @param $data
     * @return string
     */
    public function getServiceStateAttr($value, $data): string
    {
        return $this->getServiceProjectionForAttr((array)$data)['service_state'] ?? '';
    }

    /**
     * 获取器：服务售后状态文字
     * @param $value
     * @param $data
     * @return string
     */
    public function getServiceStateTextAttr($value, $data): string
    {
        return $this->getServiceProjectionForAttr((array)$data)['service_state_text'] ?? (string)$value;
    }

    /**
     * 获取器：退款引导文案
     * @param $value
     * @param $data
     * @return string
     */
    public function getRefundGuidanceAttr($value, $data): string
    {
        return $this->getServiceProjectionForAttr((array)$data)['refund_guidance'] ?? '';
    }

    /**
     * 获取器：退款入口模式
     * @param $value
     * @param $data
     * @return string
     */
    public function getRefundEntryModeAttr($value, $data): string
    {
        return $this->getServiceProjectionForAttr((array)$data)['refund_entry_mode'] ?? 'developer_refund';
    }

    /**
     * 获取器：是否需要 iOS App Store 退款引导
     * @param $value
     * @param $data
     * @return bool
     */
    public function getIosAppleRefundRequiredAttr($value, $data): bool
    {
        return !empty($this->getServiceProjectionForAttr((array)$data)['ios_apple_refund_required']);
    }

    /**
     * 获取器：iOS退款风险与问询投影。
     */
    public function getIosRefundRiskStatusAttr($value, $data): int
    {
        return (int)($this->getServiceProjectionForAttr((array)$data)['ios_refund_risk_status'] ?? 0);
    }

    public function getIosRefundRiskTextAttr($value, $data): string
    {
        return (string)($this->getServiceProjectionForAttr((array)$data)['ios_refund_risk_text'] ?? '');
    }

    public function getIosRefundInquiryReceivedAttr($value, $data): bool
    {
        return (bool)($this->getServiceProjectionForAttr((array)$data)['ios_refund_inquiry_received'] ?? false);
    }

    public function getLatestIosRefundInquiryAttr($value, $data)
    {
        return $this->getServiceProjectionForAttr((array)$data)['latest_ios_refund_inquiry'] ?? null;
    }

    public function getMerchantRefundReviewStatusAttr($value, $data)
    {
        return $this->getServiceProjectionForAttr((array)$data)['merchant_refund_review_status'] ?? null;
    }

    /**
     * 获取器：退款展示状态
     * @param $value
     * @param $data
     * @return string
     */
    public function getDisplayStateAttr($value, $data): string
    {
        return $this->getServiceProjectionForAttr((array)$data)['display_state'] ?? '';
    }

    /**
     * 获取器：退款展示状态文案
     * @param $value
     * @param $data
     * @return string
     */
    public function getDisplayStateTextAttr($value, $data): string
    {
        return $this->getServiceProjectionForAttr((array)$data)['display_state_text'] ?? '';
    }

    /**
     * 获取器：动作标识
     * @param $value
     * @param $data
     * @return array
     */
    public function getActionFlagsAttr($value, $data): array
    {
        $projection = $this->getServiceProjectionForAttr((array)$data);
        $isIos = !empty($projection['ios_apple_refund_required']);
        return [
            'can_cancel' => !$isIos && $data['status'] == RefundStatusEnum::NORMAL && $data['audit_status'] == AuditStatusEnum::WAIT,
            'can_reapply' => !$isIos && $data['status'] == RefundStatusEnum::REJECTED,
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
        $list = $this->with(['orderGoods.image', 'orderData'])
            ->alias('refund')
            ->field('refund.*')
            ->join('order', 'order.order_id = refund.order_id')
            ->where($filter)
            ->where('refund.user_id', '=', $userId)
            ->where('order.is_delete', '=', 0)
            ->order(['refund.create_time' => 'desc'])
            ->paginate(15);
        $orderIds = [];
        foreach ($list as $item) {
            $orderIds[] = (int)$item['order_id'];
        }
        $inquiryMap = IosRefundInquiryModel::latestMapByOrderIds($orderIds);
        foreach ($list as $item) {
            $item['ios_refund_latest_inquiry'] = $inquiryMap[(int)$item['order_id']] ?? null;
        }
        return $list;
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
        $latestInquiry = IosRefundInquiryModel::latestByOrderId((int)$detail['order_id']);
        $detail['ios_refund_latest_inquiry'] = $latestInquiry ? IosRefundInquiryModel::project($latestInquiry->toArray()) : null;
        $detail['ios_refund_inquiry_timeline'] = IosRefundInquiryModel::timelineByOrderId((int)$detail['order_id']);
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
        if (IosRefundRiskService::isLocked($order)) {
            throwError('该iOS订单已提交退款申请或进入App Store退款流程，不可重复申请');
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
        $refundTrade = PaymentTradeModel::resolveVirtualTradeForRefundContext(
            (int)$goods['order_id'],
            (int)($order['trade_id'] ?? 0),
            0
        );
        if (!empty($refundTrade)
            && (int)($order['trade_id'] ?? 0) > 0
            && (int)$refundTrade['trade_id'] !== (int)$order['trade_id']) {
            // 历史虚拟尝试不能覆盖当前最终支付交易的退款路由。
            $refundTrade = null;
        }
        if (!empty($refundTrade)) {
            // 必须在 apply() 的订单/退款/交易锁事务之外访问微信，避免网络等待扩大锁区间。
            $refundTrade = (new RefundService())->convergeVirtualPaymentChannelForRefund($order, $refundTrade);
        }
        $refundProjection = PaymentTradeModel::buildVirtualRefundProjection($refundTrade);
        $goods['refund_entry_mode'] = (string)($refundProjection['refund_entry_mode'] ?? 'developer_refund');
        $goods['ios_apple_refund_required'] = (bool)($refundProjection['ios_apple_refund_required'] ?? false);
        $goods['refund_guidance'] = (string)($refundProjection['refund_guidance'] ?? '');
        $goods['refund_display_state_text'] = (string)($refundProjection['refund_display_state_text'] ?? '');
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
        $userId = UserService::getCurrentLoginUserId();
        $goods = $this->getRefundGoods($orderGoodsId);

        return $this->transaction(function () use ($orderGoodsId, $data, $goods, $userId) {
            // 固定锁顺序：order -> all service refunds -> final trade。
            $lockedOrder = (new OrderModel)
                ->where('order_id', '=', (int)$goods['order_id'])
                ->where('user_id', '=', $userId)
                ->lock(true)
                ->find();
            if (empty($lockedOrder)) {
                throwError('订单不存在或不属于当前用户');
            }
            $lockedRefunds = (new static)
                ->where('type', '=', RefundTypeEnum::SERVICE)
                ->where('order_id', '=', (int)$lockedOrder['order_id'])
                ->order(['order_refund_id' => 'asc'])
                ->lock(true)
                ->select();
            $lockedTrade = null;
            if ((int)($lockedOrder['trade_id'] ?? 0) > 0) {
                $lockedTrade = (new PaymentTradeModel)
                    ->where('trade_id', '=', (int)$lockedOrder['trade_id'])
                    ->lock(true)
                    ->find();
            }

            $order = OrderModel::detail(['order_id' => (int)$lockedOrder['order_id'], 'user_id' => $userId], ['goods']);
            if (empty($order)) {
                throwError('订单不存在或不属于当前用户');
            }
            $refundMode = OrderModel::isServiceOrderData($order)
                ? OrderModel::getServiceRefundMode($order)
                : OrderModel::SERVICE_REFUND_MODE_AUTO;
            if ($refundMode === OrderModel::SERVICE_REFUND_MODE_NONE) {
                throwError('当前服务阶段不允许申请退款');
            }

            $isIosApple = !empty($lockedTrade)
                && (int)$lockedTrade['trade_id'] === (int)$lockedOrder['trade_id']
                && (int)$lockedTrade['order_id'] === (int)$lockedOrder['order_id']
                && (int)$lockedTrade['store_id'] === (int)$lockedOrder['store_id']
                && (int)$lockedTrade['user_id'] === (int)$lockedOrder['user_id']
                && PaymentTradeModel::isIosAppleVirtualTrade($lockedTrade);
            if ($isIosApple && (int)($lockedOrder['ios_refund_risk_status'] ?? IosRiskStatusEnum::NONE) !== IosRiskStatusEnum::NONE) {
                throwError('该iOS订单已提交退款申请或进入App Store退款流程，不可重复申请');
            }
            if (!$lockedRefunds->isEmpty()) {
                // iOS 已结束/驳回的本地申请也不能重建；其他渠道保持原有“进行中不可重复”语义。
                foreach ($lockedRefunds as $existingRefund) {
                    if ($isIosApple || (int)$existingRefund['status'] === RefundStatusEnum::NORMAL) {
                        throwError('当前订单已存在退款申请');
                    }
                }
            }

            $isAutoRefund = $refundMode === OrderModel::SERVICE_REFUND_MODE_AUTO;
            $saveData = [
                'order_goods_id' => $orderGoodsId,
                'order_id' => (int)$goods['order_id'],
                'user_id' => $userId,
                'type' => RefundTypeEnum::SERVICE,
                'apply_desc' => (string)($data['content'] ?? '用户申请退款'),
                'audit_status' => $isAutoRefund ? AuditStatusEnum::REVIEWED : AuditStatusEnum::WAIT,
                'status' => RefundStatusEnum::NORMAL,
                'store_id' => self::$storeId,
            ];
            if ($this->save($saveData) === false) {
                throwError('退款单创建失败');
            }

            if ($isIosApple) {
                if (!IosRefundRiskService::lockOrder($lockedOrder, IosRiskSourceEnum::LOCAL_APPLY)) {
                    throwError('建立iOS退款服务冻结失败');
                }
                $snapshot = PaymentTradeModel::decodePayloadSnapshot((string)($lockedTrade['payload_snapshot'] ?? ''));
                $snapshot['virtual_refund'] = array_merge((array)($snapshot['virtual_refund'] ?? []), [
                    'status' => 'waiting_ios_apple_refund',
                    'requested_at' => time(),
                    'order_refund_id' => (int)$this['order_refund_id'],
                    'ios_refund_required' => true,
                    'source' => 'refund_apply',
                    'message' => 'iOS Apple虚拟支付订单等待用户前往App Store申请及Apple最终通知',
                ]);
                if ($lockedTrade->save([
                    'payload_snapshot' => helper::jsonEncode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]) === false) {
                    throwError('记录iOS退款申请交易快照失败');
                }
                return true;
            }

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
