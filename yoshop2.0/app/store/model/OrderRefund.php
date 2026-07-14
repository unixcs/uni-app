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

use app\store\model\User as UserModel;
use app\common\model\OrderRefund as OrderRefundModel;
use app\common\model\PaymentTrade as PaymentTradeModel;
use app\common\model\PaymentIosRefundInquiry as IosRefundInquiryModel;
use app\common\service\order\IosRefundRisk as IosRefundRiskService;
use app\common\enum\order\DeliveryStatus as DeliveryStatusEnum;
use app\common\enum\order\PayStatus as PayStatusEnum;
use app\common\enum\order\ReceiptStatus as ReceiptStatusEnum;
use app\common\enum\order\refund\RefundType as RefundTypeEnum;
use app\common\enum\order\refund\AuditStatus as AuditStatusEnum;
use app\common\enum\order\refund\RefundStatus as RefundStatusEnum;
use app\common\enum\order\OrderStatus as OrderStatusEnum;
use app\common\service\Message as MessageService;
use app\common\service\Order as OrderService;
use app\common\service\order\Refund as RefundService;

/**
 * 售后单模型
 * Class OrderRefund
 * @package app\api\model
 */
class OrderRefund extends OrderRefundModel
{
    /**
     * 追加字段
     * @var array
     */
    protected $append = [
        'can_audit',
        'state_text',
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
    ];


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
     * 获取器：售后单状态文字描述
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
     * 当前门店售后单查询对象
     * @return \think\db\Query
     */
    private static function queryForStore()
    {
        return (new static)
            ->alias('refund')
            ->where('refund.store_id', '=', (int)static::$storeId);
    }

    /**
     * 获取售后单列表
     * @param array $param
     * @return iterable|\think\model\Collection|\think\Paginator
     */
    public function getList(array $param = [])
    {
        // 检索查询条件
        $filter = $this->getFilter($param);
        // 获取列表数据
        $list = $this->with(['orderGoods.image', 'orderData', 'user.avatar'])
            ->alias('refund')
            ->field('refund.*, order.order_no')
            ->join('order', 'order.order_id = refund.order_id')
            ->join('user', 'user.user_id = order.user_id')
            ->where($filter)
            ->where('order.is_delete', '=', 0)
            ->where('refund.store_id', '=', (int)static::$storeId)
            ->order(['refund.create_time' => 'desc', 'refund.' . $this->getPk()])
            ->paginate(10);
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
     * 获取售后单详情
     * @param int $orderRefundId
     * @return OrderRefund|false|null
     */
    public function getDetail(int $orderRefundId)
    {
        $detail = static::detailForStore($orderRefundId, [
            'orderData', 'orderGoods.image', 'user'
        ]);
        if (!$detail || (int)$detail['type'] !== RefundTypeEnum::SERVICE) {
            return false;
        }
        $latestInquiry = IosRefundInquiryModel::latestByOrderId((int)$detail['order_id']);
        $detail['ios_refund_latest_inquiry'] = $latestInquiry ? IosRefundInquiryModel::project($latestInquiry->toArray()) : null;
        $detail['ios_refund_inquiry_timeline'] = IosRefundInquiryModel::timelineByOrderId((int)$detail['order_id']);
        return $detail;
    }

    /**
     * 获取当前门店售后单详情
     * @param int $orderRefundId
     * @param array $with
     * @return static|array|null
     */
    public static function detailForStore(int $orderRefundId, array $with = [])
    {
        return static::queryForStore()
            ->field('refund.*')
            ->join('order', 'order.order_id = refund.order_id')
            ->where('order.is_delete', '=', 0)
            ->with($with)
            ->find($orderRefundId);
    }

    /**
     * 检索查询条件
     * @param array $param
     * @return array
     */
    private function getFilter(array $param = []): array
    {
        // 默认查询条件
        $params = $this->setQueryDefaultValue($param, [
            'searchType' => '',     // 关键词类型 (10订单号 20会员昵称 30会员ID)
            'searchValue' => '',    // 关键词内容
            'refundType' => -1,      // 售后类型
            'refundStatus' => -1,    // 售后单状态
            'betweenTime' => [],    // 申请时间
        ]);
        // 检查查询条件
        $filter = [
            ['refund.type', '=', RefundTypeEnum::SERVICE]
        ];
        // 关键词
        if (!empty($params['searchValue'])) {
            $searchWhere = [
                10 => ['order.order_no', 'like', "%{$params['searchValue']}%"],
                20 => ['user.nick_name', 'like', "%{$params['searchValue']}%"],
                30 => ['order.user_id', '=', (int)$params['searchValue']]
            ];
            array_key_exists($params['searchType'], $searchWhere) && $filter[] = $searchWhere[$params['searchType']];
        }
        // 起止时间
        if (!empty($params['betweenTime'])) {
            $times = between_time($params['betweenTime']);
            $filter[] = ['refund.create_time', '>=', $times['start_time']];
            $filter[] = ['refund.create_time', '<', $times['end_time'] + 86400];
        }
        // 售后类型
        if ($params['refundType'] > -1 && (int)$params['refundType'] !== RefundTypeEnum::SERVICE) {
            $filter[] = ['refund.order_refund_id', '=', 0];
        }
        // 处理状态
        $params['refundStatus'] > -1 && $filter[] = ['refund.status', '=', (int)$params['refundStatus']];
        return $filter;
    }

    /**
     * 商家审核
     * @param array $data
     * @return bool
     */
    public function audit(array $data): bool
    {
        if ((int)$this['type'] !== RefundTypeEnum::SERVICE) {
            $this->error = '当前售后单不支持该操作';
            return false;
        }
        $targetAudit = (int)($data['audit_status'] ?? -1);
        if (!in_array($targetAudit, [AuditStatusEnum::REVIEWED, AuditStatusEnum::REJECTED], true)) {
            $this->error = '审核状态不合法';
            return false;
        }
        if ($targetAudit === AuditStatusEnum::REJECTED && empty($data['refuse_desc'])) {
            $this->error = '请输入拒绝原因';
            return false;
        }

        $this->transaction(function () use ($data, $targetAudit) {
            // 固定锁顺序：order -> all service refunds -> final trade -> inquiries。
            $order = (new Order)
                ->where('order_id', '=', (int)$this['order_id'])
                ->where('store_id', '=', (int)static::$storeId)
                ->lock(true)
                ->find();
            if (empty($order) || !Order::isServiceOrderData($order)) {
                throwError('当前售后单仅支持服务订单退款审核');
            }
            $refunds = static::queryForStore()
                ->where('refund.order_id', '=', (int)$order['order_id'])
                ->where('refund.type', '=', RefundTypeEnum::SERVICE)
                ->order(['refund.order_refund_id' => 'asc'])
                ->lock(true)
                ->select();
            $refund = null;
            foreach ($refunds as $candidate) {
                if ((int)$candidate['order_refund_id'] === (int)$this['order_refund_id']) {
                    $refund = $candidate;
                    break;
                }
            }
            if (empty($refund)) {
                throwError('未找到该售后单记录');
            }
            $trade = null;
            if ((int)($order['trade_id'] ?? 0) > 0) {
                $trade = (new PaymentTradeModel)
                    ->where('trade_id', '=', (int)$order['trade_id'])
                    ->lock(true)
                    ->find();
            }
            $inquiries = (new IosRefundInquiryModel)
                ->where('order_id', '=', (int)$order['order_id'])
                ->order(['inquiry_id' => 'asc'])
                ->lock(true)
                ->select();

            if (!$refund->isReviewableInServiceOrder($order)) {
                throwError('当前退款单不允许审核');
            }
            $isIosApple = IosRefundRiskService::isLocked($order)
                || (!empty($trade) && PaymentTradeModel::isIosAppleVirtualTrade($trade));
            $saveData = [
                'audit_status' => $targetAudit,
                'refuse_desc' => (string)($data['refuse_desc'] ?? ''),
            ];

            if ($isIosApple) {
                if ($targetAudit === AuditStatusEnum::REJECTED) {
                    foreach ($inquiries as $inquiry) {
                        if ((int)$inquiry['result_code'] === 0) {
                            throwError('已向Apple建议退款，商家审核不能再改为驳回');
                        }
                    }
                    $saveData['status'] = RefundStatusEnum::REJECTED;
                } else {
                    // 商家同意只改变审核事实；iOS 不调用开发者主动退款接口。
                    $saveData['status'] = RefundStatusEnum::NORMAL;
                    $saveData['refuse_desc'] = '';
                }
                if ($refund->save($saveData) === false) {
                    throwError('更新退款审核状态失败');
                }
            } elseif ($targetAudit === AuditStatusEnum::REJECTED) {
                $saveData['status'] = RefundStatusEnum::REJECTED;
                if ($refund->save($saveData) === false) {
                    throwError('更新退款审核状态失败');
                }
            } else {
                $goods = (new OrderGoods)
                    ->where('order_id', '=', (int)$order['order_id'])
                    ->select();
                $order->setRelation('goods', $goods);
                if (!empty($trade)) {
                    $order->setRelation('trade', $trade);
                }
                $refund->executeFullRefundAndCloseOrder($order, $saveData);
            }

            $this->data($refund->getData());
            MessageService::send('order.refund', [
                'refund' => $refund,
                'order_no' => $order['order_no'],
            ], $refund['store_id']);
        });
        return true;
    }

    /**
     * 确认收货并退款
     * @param array $data
     * @return bool
     */
    public function receipt(array $data): bool
    {
        $this->error = '当前售后单不支持确认收货';
        return false;
    }

    /**
     * 获取器：是否允许商家审核
     * @param $value
     * @param $data
     * @return bool
     */
    public function getCanAuditAttr($value, $data): bool
    {
        if ((int)($data['type'] ?? 0) !== RefundTypeEnum::SERVICE) {
            return false;
        }
        $order = $this['orderData'] ?? null;
        if (empty($order) && !empty($data['order_id'])) {
            $order = Order::detail((int)$data['order_id'], ['goods']);
        }
        if (empty($order) || !Order::isServiceOrderData($order)) {
            return false;
        }
        return $this->isReviewableInServiceOrder($order);
    }

    /**
     * 获取待处理售后单数量
     * @return int
     */
    public function getRefundTotal(): int
    {
        return $this->alias('refund')
            ->join('order', 'order.order_id = refund.order_id')
            ->where('refund.type', '=', RefundTypeEnum::SERVICE)
            ->where('refund.store_id', '=', (int)static::$storeId)
            ->where('refund.status', '=', RefundStatusEnum::NORMAL)
            ->where('order.is_delete', '=', 0)
            ->count();
    }

    /**
     * 自动完成退款
     * @param string $applyDesc
     * @return bool
     */
    public function completeAutoRefund(string $applyDesc = ''): bool
    {
        if ((int)$this['type'] !== RefundTypeEnum::SERVICE) {
            $this->error = '当前售后单不支持该操作';
            return false;
        }
        return $this->transaction(function () use ($applyDesc) {
            $order = Order::detail($this['order_id'], ['goods', 'trade']);
            if (empty($order)) {
                throwError('订单不存在');
            }
            if (Order::getServiceRefundMode($order, (int)$this['order_refund_id']) !== Order::SERVICE_REFUND_MODE_AUTO) {
                throwError('当前订单状态不允许自动退款');
            }
            if ($applyDesc !== '') {
                $this->save(['apply_desc' => $applyDesc]);
            }
            $saveData = [
                'audit_status' => AuditStatusEnum::REVIEWED,
                'refuse_desc' => '',
            ];
            $this->executeFullRefundAndCloseOrder($order, $saveData);
            MessageService::send('order.refund', [
                'refund' => $this,
                'order_no' => $order['order_no']
            ], $this['store_id']);
            return true;
        });
    }

    /**
     * 执行整单退款并关闭订单
     * @param Order $order
     * @param array $saveData
     */
    private function executeFullRefundAndCloseOrder(Order $order, array $saveData): void
    {
        $refundMoney = Order::getRefundableAmount($order);
        if (!(new RefundService)->handle($order, $refundMoney, [
            'order_refund_id' => (int)$this['order_refund_id'],
        ])) {
            throwError('执行订单退款失败');
        }
        $saveData['refund_money'] = $refundMoney;
        if ((string)($order['trade']['platform'] ?? '') === 'wechat_virtual') {
            $saveData['status'] = RefundStatusEnum::NORMAL;
            if ($this->save($saveData) === false) {
                throwError('更新退款单状态失败');
            }
            return;
        }
        OrderService::cancelEvent($order);
        if ($order->save(['order_status' => OrderStatusEnum::CANCELLED]) === false) {
            throwError('更新订单状态失败');
        }
        $saveData['status'] = RefundStatusEnum::COMPLETED;
        if ($this->save($saveData) === false) {
            throwError('更新退款单状态失败');
        }
    }

    /**
     * 是否为服务中可审核退款单
     * @param Order $order
     * @return bool
     */
    private function isReviewableInServiceOrder(Order $order): bool
    {
        if ((int)$this['status'] !== RefundStatusEnum::NORMAL) {
            return false;
        }
        if ((int)$this['audit_status'] !== AuditStatusEnum::WAIT) {
            return false;
        }
        if ((int)$order['order_status'] !== OrderStatusEnum::NORMAL) {
            return false;
        }
        return (int)$order['pay_status'] === PayStatusEnum::SUCCESS
            && (int)$order['delivery_status'] === DeliveryStatusEnum::DELIVERED
            && (int)$order['receipt_status'] === ReceiptStatusEnum::NOT_RECEIVED;
    }
}
