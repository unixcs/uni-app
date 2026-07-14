<?php
/**
 * 商家订单详情真实数据投影回归（只读）。
 * Usage: cd yoshop2.0 && php ../.trellis/tasks/07-12-store-order-detail-blank/research/store-order-detail-regression.php --order-no=334517902292559509
 */
declare(strict_types=1);

require dirname(__DIR__, 4) . '/yoshop2.0/vendor/autoload.php';

$options = getopt('', ['order-no:']);
$orderNo = trim((string)($options['order-no'] ?? ''));
if ($orderNo === '') {
    fwrite(STDERR, "--order-no is required\n");
    exit(2);
}

$app = new \think\App();
$app->initialize();
\cores\BaseModel::$storeId = 10001;

$row = \think\facade\Db::name('order')->where('order_no', '=', $orderNo)->find();
if (empty($row)) {
    throw new RuntimeException('order not found');
}
$detail = (new \app\store\model\Order())->getDetail((int)$row['order_id']);
if (empty($detail)) {
    throw new RuntimeException('store order detail projection is empty');
}
$data = $detail->toArray();
$checks = [
    'order_no_matches' => (string)($data['order_no'] ?? '') === $orderNo,
    'order_id_present' => (int)($data['order_id'] ?? 0) > 0,
    'paid' => (int)($data['pay_status'] ?? 0) === 20,
    'goods_present' => count((array)($data['goods'] ?? [])) > 0,
    'user_present' => (int)($data['user']['user_id'] ?? 0) > 0,
    'trade_present' => (int)($data['trade']['trade_id'] ?? 0) > 0,
    'virtual_summary_enabled' => !empty($data['virtual_payment_summary']['enabled']),
    'notify_time_present' => (int)($data['virtual_payment_summary']['last_notify_time'] ?? 0) > 0,
    'action_flags_present' => isset($data['backend_action_flags']) && is_array($data['backend_action_flags']),
];

echo json_encode([
    'order_no' => $orderNo,
    'order_id' => (int)$data['order_id'],
    'goods_count' => count((array)$data['goods']),
    'trade_id' => (int)($data['trade']['trade_id'] ?? 0),
    'notify_times' => (int)($data['virtual_payment_summary']['notify_times'] ?? 0),
    'last_notify_time' => (int)($data['virtual_payment_summary']['last_notify_time'] ?? 0),
    'checks' => $checks,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), PHP_EOL;

if (in_array(false, $checks, true)) {
    exit(1);
}
