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
    protected $append = ['can_audit'];

    /**
     * 当前门店售后单查询对象
     * @return \think\db\Query
     */
    private static function queryForStore()
    {
        return (new static)->where('store_id', '=', (int)static::$storeId);
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
        return $this->with(['orderGoods.image', 'orderData', 'user.avatar'])
            ->alias('refund')
            ->field('refund.*, order.order_no')
            ->join('order', 'order.order_id = refund.order_id')
            ->join('user', 'user.user_id = order.user_id')
            ->where($filter)
            ->where('order.is_delete', '=', 0)
            ->where('refund.store_id', '=', (int)static::$storeId)
            ->order(['refund.create_time' => 'desc', 'refund.' . $this->getPk()])
            ->paginate(10);
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
            ->alias('refund')
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
        if (!in_array((int)($data['audit_status'] ?? -1), [AuditStatusEnum::REVIEWED, AuditStatusEnum::REJECTED], true)) {
            $this->error = '审核状态不合法';
            return false;
        }
        $order = Order::detail($this['order_id'], ['goods', 'trade']);
        $isServiceOrder = Order::isServiceOrderData($order);

        if (!$isServiceOrder) {
            $this->error = '当前售后单仅支持服务订单退款审核';
            return false;
        }

        if ($data['audit_status'] == AuditStatusEnum::REJECTED && empty($data['refuse_desc'])) {
            $this->error = '请输入拒绝原因';
            return false;
        }
        if (!$this->isReviewableInServiceOrder($order)) {
            $this->error = '当前退款单不允许审核';
            return false;
        }
        $this->transaction(function () use ($data) {
            $refund = static::queryForStore()
                ->lock(true)
                ->find((int)$this['order_refund_id']);
            if (empty($refund)) {
                throwError('未找到该售后单记录');
            }
            $order = Order::detail($refund['order_id'], ['goods', 'trade']);
            if (empty($order) || !Order::isServiceOrderData($order)) {
                throwError('当前售后单仅支持服务订单退款审核');
            }
            if (!$refund->isReviewableInServiceOrder($order)) {
                throwError('当前退款单不允许审核');
            }
            $saveData = [
                'audit_status' => $data['audit_status'],
                'refuse_desc' => $data['refuse_desc'] ?? ''
            ];
            if ($data['audit_status'] == AuditStatusEnum::REJECTED) {
                $saveData['status'] = RefundStatusEnum::REJECTED;
                $refund->save($saveData);
            }
            if ($data['audit_status'] == AuditStatusEnum::REVIEWED) {
                $refund->executeFullRefundAndCloseOrder($order, $saveData);
            }
            $this->data($refund->getData());
            // 发送消息通知
            MessageService::send('order.refund', [
                'refund' => $refund,                // 售后单信息
                'order_no' => $order['order_no']    // 订单信息
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
