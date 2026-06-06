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

namespace app\common\service\order;

use app\common\library\helper;
use app\common\model\User as UserModel;
use app\common\model\Order as OrderModel;
use app\common\model\OrderRefund as OrderRefundModel;
use app\common\model\store\Setting as SettingModel;
use app\common\model\user\PointsLog as PointsLogModel;
use app\common\enum\Setting as SettingEnum;
use app\common\enum\order\refund\AuditStatus as AuditStatusEnum;
use app\common\enum\order\refund\RefundStatus as RefundStatusEnum;
use app\common\enum\order\refund\RefundType as RefundTypeEnum;
use app\common\service\BaseService;

/**
 * 已完成订单结算服务类
 * Class Complete
 * @package app\common\service\order
 */
class Complete extends BaseService
{
    // 订单模型
    /* @var OrderModel $model */
    private OrderModel $model;

    // 用户模型
    /* @var UserModel $model */
    private UserModel $UserModel;

    /**
     * 构造方法
     * Complete constructor.
     */
    public function initialize()
    {
        $this->model = new OrderModel;
        $this->UserModel = new UserModel;
    }

    /**
     * 执行订单完成后的操作
     * @param iterable $orderList
     * @param int $storeId
     * @return bool
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function complete(iterable $orderList, int $storeId): bool
    {
        // 已完成订单结算
        // 条件：后台订单流程设置 - 已完成订单设置0天不允许申请售后
        if (SettingModel::getItem(SettingEnum::TRADE, $storeId)['order']['refund_days'] == 0) {
            $this->settled($orderList);
        }
        return true;
    }

    /**
     * 执行订单结算
     * @param $orderList
     * @return bool
     */
    public function settled($orderList): bool
    {
        // 订单id集
        $orderIds = helper::getArrayColumn($orderList, 'order_id');
        // 累积用户实际消费金额
        $this->setIncUserExpend($orderList);
        // 处理订单赠送的积分
        $this->setGiftPointsBonus($orderList);
        // 将订单设置为已结算
        $this->model->onBatchUpdate($orderIds, ['is_settled' => 1, 'settled_time' => \time()]);
        return true;
    }

    /**
     * 处理订单赠送的积分
     * @param $orderList
     * @return void
     */
    private function setGiftPointsBonus($orderList): void
    {
        // 计算用户所得积分
        $userData = [];
        $logData = [];
        $completedRefundMap = $this->getCompletedRefundMap($orderList);
        foreach ($orderList as $order) {
            // 计算用户所得积分
            $pointsBonus = $order['points_bonus'];
            if ($pointsBonus <= 0) continue;
            // 减去服务订单已完成退款对应的赠送积分
            if (isset($completedRefundMap[$order['order_id']])) {
                $pointsBonus = 0;
            }
            // 计算用户所得积分
            !isset($userData[$order['user_id']]) && $userData[$order['user_id']] = 0;
            $userData[$order['user_id']] += $pointsBonus;
            // 整理用户积分变动明细
            $logData[] = [
                'user_id' => $order['user_id'],
                'value' => $pointsBonus,
                'describe' => "订单赠送：{$order['order_no']}",
                'store_id' => $order['store_id'],
            ];
        }
        if (!empty($userData)) {
            // 累积到会员表记录
            $this->UserModel->onBatchIncPoints($userData);
            // 批量新增积分明细记录
            (new PointsLogModel)->onBatchAdd($logData);
        }
    }

    /**
     * 累积用户实际消费金额
     * @param $orderList
     * @return void
     */
    private function setIncUserExpend($orderList): void
    {
        // 计算并累积实际消费金额(需减去售后退款的金额)
        $userData = [];
        $completedRefundMap = $this->getCompletedRefundMap($orderList);
        foreach ($orderList as $order) {
            // 订单实际支付金额
            $expendMoney = $order['pay_price'];
            // 减去订单退款的金额（以售后主表为准）
            if (isset($completedRefundMap[$order['order_id']])) {
                $expendMoney = helper::bcsub($expendMoney, $completedRefundMap[$order['order_id']]['refund_money']);
            }
            !isset($userData[$order['user_id']]) && $userData[$order['user_id']] = 0.00;
            if ($expendMoney > 0) {
                $userData[$order['user_id']] = helper::bcadd($userData[$order['user_id']], $expendMoney);
            }
        }
        // 累积到会员表记录
        $this->UserModel->onBatchIncExpendMoney($userData);
    }

    /**
     * 获取订单已完成退款映射
     * @param iterable $orderList
     * @return array
     */
    private function getCompletedRefundMap(iterable $orderList): array
    {
        $orderIds = array_values(array_filter(array_unique(helper::getArrayColumn($orderList, 'order_id'))));
        if (empty($orderIds)) {
            return [];
        }
        $refundList = (new OrderRefundModel)
            ->where('type', '=', RefundTypeEnum::SERVICE)
            ->where('order_id', 'in', $orderIds)
            ->where('audit_status', '=', AuditStatusEnum::REVIEWED)
            ->where('status', '=', RefundStatusEnum::COMPLETED)
            ->order(['create_time' => 'desc', 'order_refund_id' => 'desc'])
            ->select();
        $data = [];
        foreach ($refundList as $refund) {
            $orderId = (int)$refund['order_id'];
            if (!isset($data[$orderId])) {
                $data[$orderId] = [
                    'refund_money' => (string)$refund['refund_money'],
                ];
            }
        }
        return $data;
    }
}
