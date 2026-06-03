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

namespace app\store\model;

use app\store\model\User as UserModel;
use app\common\model\OrderRefund as OrderRefundModel;
use app\common\enum\order\DeliveryStatus as DeliveryStatusEnum;
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
        $detail = static::detail($orderRefundId, [
            'orderData', 'orderGoods.image', 'user'
        ]);
        if (!$detail || (int)$detail['type'] !== RefundTypeEnum::SERVICE) {
            return false;
        }
        return $detail;
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
        $order = Order::detail($this['order_id']);
        $isServiceOrder = Order::isServiceOrderData($order);

        if (!$isServiceOrder) {
            $this->error = '当前售后单仅支持服务订单退款审核';
            return false;
        }

        if ($data['audit_status'] == AuditStatusEnum::REJECTED && empty($data['refuse_desc'])) {
            $this->error = '请输入拒绝原因';
            return false;
        }
        if ($data['audit_status'] == AuditStatusEnum::REVIEWED && $order['order_status'] != OrderStatusEnum::NORMAL) {
            $this->error = '当前订单状态不允许退款';
            return false;
        }
        $this->transaction(function () use ($data, $order) {
            $order = Order::detail($this['order_id']);
            $saveData = [
                'audit_status' => $data['audit_status'],
                'refuse_desc' => $data['refuse_desc'] ?? ''
            ];
            if ($data['audit_status'] == AuditStatusEnum::REJECTED) {
                $saveData['status'] = RefundStatusEnum::REJECTED;
                $this->save($saveData);
            }
            if ($data['audit_status'] == AuditStatusEnum::REVIEWED) {
                $refundMoney = sprintf('%.2f', min((float)$order['pay_price'], (float)$this['orderGoods']['total_pay_price']));
                if (!(new RefundService)->handle($order, $refundMoney)) {
                    throwError('执行订单退款失败');
                }
                OrderService::cancelEvent($order);
                if ($order->save(['order_status' => OrderStatusEnum::CANCELLED]) === false) {
                    throwError('更新订单状态失败');
                }
                $saveData['status'] = RefundStatusEnum::COMPLETED;
                $saveData['refund_money'] = $refundMoney;
                $this->save($saveData);
            }
            // 发送消息通知
            MessageService::send('order.refund', [
                'refund' => $this,                  // 售后单信息
                'order_no' => $order['order_no']    // 订单信息
            ], $this['store_id']);
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
     * 获取待处理售后单数量
     * @return int
     */
    public function getRefundTotal(): int
    {
        return $this->where('type', '=', RefundTypeEnum::SERVICE)
            ->where('status', '=', RefundStatusEnum::NORMAL)
            ->count();
    }
}
