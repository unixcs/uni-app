<?php
// +----------------------------------------------------------------------
// | 历史测试服务单清理命令
// +----------------------------------------------------------------------
declare (strict_types=1);

namespace app\common\command;

use app\common\enum\order\DeliveryType as DeliveryTypeEnum;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\facade\Db;

class ServiceOrderHistoryCleanup extends Command
{
    private const DEFAULT_USER_IDS = '10001,10003,10004';
    private const STORE_ID = 10001;

    protected function configure()
    {
        $this->setName('service-order:history-cleanup')
            ->addOption('user-ids', null, Option::VALUE_OPTIONAL, '要清理的测试用户ID，逗号分隔', self::DEFAULT_USER_IDS)
            ->addOption('mode', null, Option::VALUE_OPTIONAL, 'dry-run / soft-delete', 'dry-run')
            ->setDescription('清理历史手工测试留下的服务单，默认只预览，不直接删');
    }

    protected function execute(Input $input, Output $output)
    {
        $mode = trim((string)$input->getOption('mode'));
        if (!in_array($mode, ['dry-run', 'soft-delete'], true)) {
            $output->writeln('<error>mode 只支持 dry-run 或 soft-delete</error>');
            return 1;
        }

        $userIds = $this->parseUserIds((string)$input->getOption('user-ids'));
        if (empty($userIds)) {
            $output->writeln('<error>没有拿到可清理的 user_id</error>');
            return 1;
        }

        $orders = Db::name('order')
            ->where('store_id', '=', self::STORE_ID)
            ->where('delivery_type', '=', DeliveryTypeEnum::NOTHING)
            ->where('is_delete', '=', 0)
            ->where('user_id', 'in', $userIds)
            ->field('order_id,order_no,user_id,pay_status,order_status,delivery_status,receipt_status,pay_price,trade_id,create_time,update_time')
            ->order('order_id', 'desc')
            ->select()
            ->toArray();

        $orderIds = array_map(static fn(array $row): int => (int)$row['order_id'], $orders);
        $summary = $this->buildSummary($orders, $orderIds);

        $output->writeln('历史测试服务单清理');
        $output->writeln('mode: ' . $mode);
        $output->writeln('user_ids: ' . implode(',', $userIds));
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

        $backupFile = $this->writeBackup($orders, $summary, $userIds);
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

    /** @return int[] */
    private function parseUserIds(string $raw): array
    {
        $rows = array_filter(array_map('trim', explode(',', $raw)), static fn(string $value): bool => $value !== '');
        $userIds = [];
        foreach ($rows as $value) {
            if (ctype_digit($value)) {
                $userIds[] = (int)$value;
            }
        }
        return array_values(array_unique(array_filter($userIds, static fn(int $value): bool => $value > 0)));
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
            if ($orderStatus === 20) {
                $summary['completed']++;
                continue;
            }
            if ($orderStatus === 30) {
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
     * @param int[] $userIds
     */
    private function writeBackup(array $orders, array $summary, array $userIds): string
    {
        $dir = runtime_path() . 'service-order-cleanup';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $file = $dir . '/history-cleanup-' . date('YmdHis') . '.json';
        file_put_contents($file, json_encode([
            'created_at' => date('c'),
            'user_ids' => $userIds,
            'summary' => $summary,
            'orders' => $orders,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        return $file;
    }
}
