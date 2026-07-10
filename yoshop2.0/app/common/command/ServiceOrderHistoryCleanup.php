<?php
// +----------------------------------------------------------------------
// | 历史服务订单 soft-delete 隐藏命令
// +----------------------------------------------------------------------
declare (strict_types=1);

namespace app\common\command;

use app\common\enum\order\DeliveryType as DeliveryTypeEnum;
use app\common\model\Order as OrderModel;
use app\common\enum\order\OrderStatus as OrderStatusEnum;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\facade\Db;

class ServiceOrderHistoryCleanup extends Command
{
    protected function configure()
    {
        $this->setName('service-order:history-cleanup')
            ->addOption('before-time', null, Option::VALUE_REQUIRED, '显式 cutoff_time，支持时间戳或 strtotime 可解析时间')
            ->addOption('mode', null, Option::VALUE_OPTIONAL, 'dry-run / soft-delete', 'dry-run')
            ->setDescription('按 cutoff_time 隐藏历史服务订单，默认仅 dry-run 预览');
    }

    protected function execute(Input $input, Output $output)
    {
        $mode = trim((string)$input->getOption('mode'));
        if (!in_array($mode, ['dry-run', 'soft-delete'], true)) {
            $output->writeln('<error>mode 只支持 dry-run 或 soft-delete</error>');
            return 1;
        }

        $beforeTimeRaw = trim((string)$input->getOption('before-time'));
        $beforeTime = $this->parseBeforeTime($beforeTimeRaw);
        if ($beforeTime <= 0) {
            $output->writeln('<error>请通过 --before-time 显式传入有效 cutoff_time</error>');
            return 1;
        }

        $orders = Db::name('order')
            ->where('delivery_type', '=', DeliveryTypeEnum::NOTHING)
            ->where('is_delete', '=', 0)
            ->where('create_time', '<', $beforeTime)
            ->field('order_id,order_no,user_id,pay_status,order_status,delivery_status,receipt_status,pay_price,trade_id,create_time,update_time,delivery_type,order_source_data')
            ->order('create_time', 'asc')
            ->select()
            ->toArray();

        $orders = array_values(array_filter($orders, static function (array $row): bool {
            return OrderModel::isServiceOrderData($row);
        }));

        $orderIds = array_map(static fn(array $row): int => (int)$row['order_id'], $orders);
        $summary = $this->buildSummary($orders, $orderIds);

        $output->writeln('历史服务订单 soft-delete hide');
        $output->writeln('mode: ' . $mode);
        $output->writeln('before_time: ' . date('Y-m-d H:i:s', $beforeTime) . ' (' . $beforeTime . ')');
        foreach ($summary as $key => $value) {
            $output->writeln(sprintf('%s: %s', $key, (string)$value));
        }
        $output->writeln('samples:');
        foreach (array_slice($orders, 0, 10) as $row) {
            $output->writeln(json_encode($row, JSON_UNESCAPED_UNICODE));
        }

        if ($mode === 'dry-run' || empty($orderIds)) {
            return 0;
        }

        $backupFile = $this->writeBackup($orders, $summary, $beforeTime);
        Db::name('order')
            ->where('order_id', 'in', $orderIds)
            ->update([
                'is_delete' => 1,
                'update_time' => time(),
            ]);

        $output->writeln('<info>soft-delete done</info>');
        $output->writeln('backup_file: ' . $backupFile);
        return 0;
    }

    /**
     * @param string $raw
     * @return int
     */
    private function parseBeforeTime(string $raw): int
    {
        if ($raw === '') {
            return 0;
        }
        if (ctype_digit($raw)) {
            return (int)$raw;
        }
        $timestamp = strtotime($raw);
        return $timestamp === false ? 0 : (int)$timestamp;
    }

    /**
     * @param array<int, array<string, mixed>> $orders
     * @param int[] $orderIds
     * @return array<string, int>
     */
    private function buildSummary(array $orders, array $orderIds): array
    {
        $summary = [
            'orders' => count($orders),
            'pending_pay' => 0,
            'pending_contact' => 0,
            'in_service' => 0,
            'completed' => 0,
            'cancelled' => 0,
            'order_goods_rows' => 0,
            'refund_rows' => 0,
            'payment_trade_rows' => 0,
        ];

        foreach ($orders as $row) {
            $payStatus = (int)($row['pay_status'] ?? 0);
            $orderStatus = (int)($row['order_status'] ?? 0);
            $deliveryStatus = (int)($row['delivery_status'] ?? 0);
            $receiptStatus = (int)($row['receipt_status'] ?? 0);
            if ($payStatus === 10 && $orderStatus === 10) {
                $summary['pending_pay']++;
                continue;
            }
            if ($payStatus === 20 && $orderStatus === 10 && $deliveryStatus === 10) {
                $summary['pending_contact']++;
                continue;
            }
            if ($payStatus === 20 && $orderStatus === 10 && $deliveryStatus === 20 && $receiptStatus === 10) {
                $summary['in_service']++;
                continue;
            }
            if ($orderStatus === OrderStatusEnum::COMPLETED) {
                $summary['completed']++;
                continue;
            }
            if ($orderStatus === OrderStatusEnum::CANCELLED) {
                $summary['cancelled']++;
            }
        }

        if (!empty($orderIds)) {
            $summary['order_goods_rows'] = (int)Db::name('order_goods')->where('order_id', 'in', $orderIds)->count();
            $summary['refund_rows'] = (int)Db::name('order_refund')->where('order_id', 'in', $orderIds)->count();
            $summary['payment_trade_rows'] = (int)Db::name('payment_trade')->where('order_id', 'in', $orderIds)->count();
        }

        return $summary;
    }

    /**
     * @param array<int, array<string, mixed>> $orders
     * @param array<string, int> $summary
     * @param int $beforeTime
     */
    private function writeBackup(array $orders, array $summary, int $beforeTime): string
    {
        $dir = runtime_path() . 'service-order-cleanup';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $file = $dir . '/history-cleanup-' . date('YmdHis') . '.json';
        file_put_contents($file, json_encode([
            'created_at' => date('c'),
            'before_time' => $beforeTime,
            'before_time_text' => date('Y-m-d H:i:s', $beforeTime),
            'summary' => $summary,
            'orders' => $orders,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        return $file;
    }
}
