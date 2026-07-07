<?php
// +----------------------------------------------------------------------
// | 服务单巡检命令
// +----------------------------------------------------------------------
declare (strict_types=1);

namespace app\common\command;

use app\common\enum\order\DeliveryType as DeliveryTypeEnum;
use app\common\enum\order\refund\RefundStatus as RefundStatusEnum;
use app\common\enum\order\refund\RefundType as RefundTypeEnum;
use app\common\model\Order;
use app\common\model\OrderRefund;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

class InspectServiceOrders extends Command
{
    protected function configure()
    {
        $this->setName('inspect:service-orders')
            ->addOption('limit', null, Option::VALUE_OPTIONAL, '输出条数', 50)
            ->setDescription('巡检服务单和历史混合订单');
    }

    protected function execute(Input $input, Output $output)
    {
        $limit = max(1, (int)$input->getOption('limit'));
        $rows = Order::withoutField([])
            ->where('is_delete', '=', 0)
            ->order(['order_id' => 'desc'])
            ->select();

        $stats = [
            'physical_clean' => 0,
            'service_clean' => 0,
            'mixed_dirty' => 0,
            'service_suspicious' => 0,
        ];
        $samples = [];

        foreach ($rows as $order) {
            $deliveryType = (int)$order['delivery_type'];
            $serviceContact = Order::getServiceContactData($order);
            $hasContact = !empty($serviceContact['contact_name']) || !empty($serviceContact['contact_mobile']) || !empty($serviceContact['time_preference']);
            $isDeliveryService = $deliveryType === DeliveryTypeEnum::NOTHING;
            $hasActiveRefund = (new OrderRefund)
                ->where('type', '=', RefundTypeEnum::SERVICE)
                ->where('order_id', '=', (int)$order['order_id'])
                ->where('status', '=', RefundStatusEnum::NORMAL)
                ->count() > 0;

            $bucket = 'physical_clean';
            if ($isDeliveryService && $hasContact) {
                $bucket = 'service_clean';
            } elseif (!$isDeliveryService && $hasContact) {
                $bucket = 'mixed_dirty';
            } elseif ($isDeliveryService && !$hasContact) {
                $bucket = 'service_suspicious';
            }
            $stats[$bucket]++;

            if (count($samples) < $limit && $bucket !== 'physical_clean') {
                $samples[] = [
                    'order_id' => (int)$order['order_id'],
                    'order_no' => (string)$order['order_no'],
                    'bucket' => $bucket,
                    'delivery_type' => $deliveryType,
                    'contact_name' => (string)($serviceContact['contact_name'] ?? ''),
                    'contact_mobile' => (string)($serviceContact['contact_mobile'] ?? ''),
                    'time_preference' => (string)($serviceContact['time_preference'] ?? ''),
                    'has_active_refund' => $hasActiveRefund ? 'Y' : 'N',
                ];
            }
        }

        $output->writeln('服务单巡检结果');
        foreach ($stats as $name => $count) {
            $output->writeln(sprintf('%s: %d', $name, $count));
        }
        $output->writeln('样本:');
        foreach ($samples as $sample) {
            $output->writeln(json_encode($sample, JSON_UNESCAPED_UNICODE));
        }
    }
}
