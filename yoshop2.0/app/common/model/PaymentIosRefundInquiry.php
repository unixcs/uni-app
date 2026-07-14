<?php
// +----------------------------------------------------------------------
// | Apple iOS 退款问询历史模型
// +----------------------------------------------------------------------
declare (strict_types=1);

namespace app\common\model;

use cores\BaseModel;

class PaymentIosRefundInquiry extends BaseModel
{
    protected $name = 'payment_ios_refund_inquiry';
    protected $pk = 'inquiry_id';

    public static function latestByOrderId(int $orderId)
    {
        if ($orderId <= 0) {
            return null;
        }
        return (new static)
            ->where('order_id', '=', $orderId)
            ->order(['received_at' => 'desc', 'inquiry_id' => 'desc'])
            ->find();
    }

    public static function timelineByOrderId(int $orderId): array
    {
        if ($orderId <= 0) {
            return [];
        }
        $rows = (new static)
            ->where('order_id', '=', $orderId)
            ->order(['received_at' => 'asc', 'inquiry_id' => 'asc'])
            ->select();
        return array_map([static::class, 'project'], $rows->toArray());
    }

    /**
     * 批量读取当前页订单的最新问询，避免列表逐行查询。
     * @param array<int, int> $orderIds
     * @return array<int, array>
     */
    public static function latestMapByOrderIds(array $orderIds): array
    {
        $orderIds = array_values(array_unique(array_filter(array_map('intval', $orderIds))));
        if (empty($orderIds)) {
            return [];
        }
        $rows = (new static)
            ->where('order_id', 'in', $orderIds)
            ->order(['order_id' => 'asc', 'received_at' => 'desc', 'inquiry_id' => 'desc'])
            ->select();
        $map = [];
        foreach ($rows as $row) {
            $orderId = (int)$row['order_id'];
            if (!isset($map[$orderId])) {
                $map[$orderId] = static::project($row->toArray());
            }
        }
        return $map;
    }

    /**
     * 对 API/UI 只暴露决策所需低敏字段，不返回原始 payload、指纹或迁移键。
     */
    public static function project(array $row): array
    {
        $fields = [
            'inquiry_id', 'order_id', 'order_refund_id', 'trade_id',
            'binding_status', 'request_reason', 'service_stage',
            'order_status', 'delivery_status', 'receipt_status', 'audit_status',
            'result_code', 'result_info', 'evidence', 'response_ms', 'received_at',
        ];
        $projection = [];
        foreach ($fields as $field) {
            if (array_key_exists($field, $row)) {
                $projection[$field] = $row[$field];
            }
        }
        return $projection;
    }
}
