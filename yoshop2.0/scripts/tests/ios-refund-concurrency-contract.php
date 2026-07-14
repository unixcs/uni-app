<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use app\api\model\OrderRefund as ApiOrderRefund;
use app\api\model\User as ApiUser;
use app\api\service\User as ApiUserService;
use app\common\enum\order\iosRefund\RiskSource;
use app\common\enum\order\iosRefund\RiskStatus;
use app\common\enum\order\refund\AuditStatus;
use app\common\enum\order\refund\RefundStatus;
use app\common\enum\order\refund\RefundType;
use app\common\model\Order as CommonOrder;
use app\common\model\OrderGoods;
use app\common\model\OrderRefund as CommonOrderRefund;
use app\common\model\PaymentTrade;
use app\common\service\order\IosRefundRisk;
use app\store\model\Order as StoreOrder;
use app\store\model\OrderRefund as StoreOrderRefund;
use cores\BaseModel;
use think\App;
use think\facade\Db;

(new App())->initialize();

function expectSame($expected, $actual, string $case): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            '%s expected=%s actual=%s',
            $case,
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
    fwrite(STDOUT, "ok {$case}\n");
}

function childMain(string $action, int $orderId, int $storeId, array $args = []): void
{
    BaseModel::$storeId = $storeId;
    try {
        if ($action === 'claim') {
            $tradeId = (int)($args[0] ?? 0);
            $claim = IosRefundRisk::claimProvideGoodsDispatchIfAllowed(
                $orderId,
                $tradeId,
                'concurrency_contract'
            );
            $result = (bool)($claim['claimed'] ?? false);
            $error = (string)($claim['reason'] ?? $claim['state'] ?? '');
        } elseif ($action === 'apply') {
            $goodsId = (int)($args[0] ?? 0);
            $userId = (int)($args[1] ?? 0);
            $content = (string)($args[2] ?? 'IOS_REFUND_CONCURRENCY_CHILD_APPLY');
            $loginUser = ApiUser::detail($userId);
            if (empty($loginUser)) {
                throw new RuntimeException('child API user fixture missing');
            }
            $currentUser = new ReflectionProperty(ApiUserService::class, 'currentLoginUser');
            $currentUser->setAccessible(true);
            $currentUser->setValue(null, $loginUser);
            $result = (new ApiOrderRefund)->apply($goodsId, ['content' => $content]);
            $error = '';
        } else {
            $order = StoreOrder::detail($orderId);
            if (empty($order)) {
                throw new RuntimeException('child order fixture missing');
            }
            $result = $action === 'start' ? $order->startService() : $order->completeService();
            $error = (string)$order->getError();
        }
        echo json_encode([
            'ok' => true,
            'result' => (bool)$result,
            'error' => $error,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), PHP_EOL;
        exit(0);
    } catch (Throwable $e) {
        fwrite(STDERR, $e->getMessage() . PHP_EOL);
        exit(2);
    }
}

if (($argv[1] ?? '') === '--child') {
    childMain((string)($argv[2] ?? ''), (int)($argv[3] ?? 0), (int)($argv[4] ?? 0), array_slice($argv, 5));
}

if (getenv('IOS_REFUND_CONCURRENCY_TEST') !== '1') {
    throw new RuntimeException('refusing to mutate a fixture without IOS_REFUND_CONCURRENCY_TEST=1');
}

function spawnChild(string $action, int $orderId, int $storeId, array $args = []): array
{
    $pipes = [];
    $process = proc_open(
        array_merge([PHP_BINARY, __FILE__, '--child', $action, (string)$orderId, (string)$storeId], array_map('strval', $args)),
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        dirname(__DIR__, 2)
    );
    if (!is_resource($process)) {
        throw new RuntimeException("failed to start {$action} child");
    }
    fclose($pipes[0]);
    usleep(250000);
    $status = proc_get_status($process);
    if (empty($status['running'])) {
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        throw new RuntimeException("{$action} child did not block on order lock: {$stdout} {$stderr}");
    }
    return [$process, $pipes];
}

function awaitChild(array $child): array
{
    [$process, $pipes] = $child;
    $deadline = microtime(true) + 10;
    do {
        $status = proc_get_status($process);
        if (empty($status['running'])) {
            break;
        }
        usleep(50000);
    } while (microtime(true) < $deadline);

    $status = proc_get_status($process);
    if (!empty($status['running'])) {
        proc_terminate($process);
        throw new RuntimeException('child timed out after order lock release');
    }
    $stdout = trim((string)stream_get_contents($pipes[1]));
    $stderr = trim((string)stream_get_contents($pipes[2]));
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);
    if ($stderr !== '') {
        throw new RuntimeException("child failed: {$stderr}");
    }
    $lines = explode("\n", $stdout);
    $decoded = json_decode((string)end($lines), true);
    if (!is_array($decoded) || empty($decoded['ok'])) {
        throw new RuntimeException("invalid child response: {$stdout}; json_error=" . json_last_error_msg());
    }
    return $decoded;
}

function competeAfterOrderLock(int $orderId, int $storeId, string $childAction, callable $winner, array $childArgs = []): array
{
    $child = null;
    Db::startTrans();
    try {
        $locked = Db::name('order')->where('order_id', $orderId)->lock(true)->find();
        if (empty($locked)) {
            throw new RuntimeException('failed to lock order fixture');
        }
        $child = spawnChild($childAction, $orderId, $storeId, $childArgs);
        $winnerResult = $winner();
        Db::commit();
        $childResult = awaitChild($child);
        return [$winnerResult, $childResult];
    } catch (Throwable $e) {
        try {
            Db::rollback();
        } catch (Throwable $ignored) {
        }
        if ($child !== null) {
            try {
                awaitChild($child);
            } catch (Throwable $ignored) {
            }
        }
        throw $e;
    }
}

$trade = (new PaymentTrade)
    ->where('platform', '=', 'wechat_virtual')
    ->where('channel_class', '=', 20)
    ->where('trade_state', 'in', [20, 30])
    ->order(['trade_id' => 'desc'])
    ->find();
if (empty($trade)) {
    throw new RuntimeException('no iOS Apple fixture trade available');
}
$orderId = (int)$trade['order_id'];
$order = Db::name('order')->where('order_id', $orderId)->find();
$goods = (new OrderGoods)->where('order_id', $orderId)->order(['order_goods_id' => 'asc'])->find();
$refundRows = Db::name('order_refund')->where('order_id', $orderId)->select()->toArray();
if (empty($order) || empty($goods) || empty($refundRows) || (int)$order['trade_id'] !== (int)$trade['trade_id']) {
    throw new RuntimeException('bound iOS order/goods/refund fixture unavailable');
}
$orderSnapshot = $order;
$tradeSnapshot = Db::name('payment_trade')->where('trade_id', (int)$trade['trade_id'])->find();
$refundSnapshots = [];
foreach ($refundRows as $row) {
    $refundSnapshots[(int)$row['order_refund_id']] = $row;
}
$originalRefundIds = array_keys($refundSnapshots);
$storeId = (int)$order['store_id'];
$userId = (int)$order['user_id'];
$marker = 'IOS_REFUND_CONCURRENCY_' . getmypid();

$resetFixture = static function () use (
    $orderId,
    $orderSnapshot,
    $tradeSnapshot,
    $refundSnapshots,
    $originalRefundIds,
    $marker
): void {
    Db::name('payment_ios_refund_inquiry')
        ->where('order_id', $orderId)
        ->whereLike('request_reason', $marker . '%')
        ->delete();
    Db::name('order_refund')
        ->where('order_id', $orderId)
        ->whereNotIn('order_refund_id', $originalRefundIds)
        ->delete();
    foreach ($refundSnapshots as $refundId => $snapshot) {
        Db::name('order_refund')->where('order_refund_id', $refundId)->update($snapshot);
    }
    Db::name('payment_trade')->where('trade_id', (int)$tradeSnapshot['trade_id'])->update($tradeSnapshot);
    Db::name('order')->where('order_id', $orderId)->update($orderSnapshot);
};

try {
    BaseModel::$storeId = $storeId;
    $loginUser = ApiUser::detail($userId);
    if (empty($loginUser)) {
        throw new RuntimeException('API user fixture unavailable');
    }
    $currentUser = new ReflectionProperty(ApiUserService::class, 'currentLoginUser');
    $currentUser->setAccessible(true);
    $currentUser->setValue(null, $loginUser);

    // Local apply owns the order lock first; the concurrent service start must re-read LOCKED and fail.
    $resetFixture();
    Db::name('order_refund')->where('order_id', $orderId)->update([
        'type' => RefundType::RETURN,
        'status' => RefundStatus::REJECTED,
    ]);
    Db::name('order')->where('order_id', $orderId)->update([
        'order_status' => 10,
        'pay_status' => 20,
        'delivery_status' => 10,
        'receipt_status' => 10,
        'ios_refund_risk_status' => RiskStatus::NONE,
        'ios_refund_risk_source' => '',
        'ios_refund_risk_time' => 0,
    ]);
    [$applyResult, $startResult] = competeAfterOrderLock(
        $orderId,
        $storeId,
        'start',
        static function () use ($goods, $marker) {
            return (new ApiOrderRefund)->apply((int)$goods['order_goods_id'], [
                'content' => $marker . '_LOCAL_APPLY',
            ]);
        }
    );
    expectSame(true, (bool)$applyResult, 'local apply wins its locked transaction');
    expectSame(false, (bool)$startResult['result'], 'concurrent start rechecks and rejects after local apply');
    $afterApply = CommonOrder::detail($orderId);
    expectSame(RiskStatus::LOCKED, (int)$afterApply['ios_refund_risk_status'], 'local apply commits LOCKED before start returns');
    expectSame(10, (int)$afterApply['delivery_status'], 'rejected concurrent start leaves service unstarted');

    // The opposite order is also valid: start commits first, then the waiting apply must re-read IN_PROGRESS and create WAIT.
    $resetFixture();
    Db::name('order_refund')->where('order_id', $orderId)->update([
        'type' => RefundType::RETURN,
        'status' => RefundStatus::REJECTED,
    ]);
    Db::name('order')->where('order_id', $orderId)->update([
        'order_status' => 10,
        'pay_status' => 20,
        'delivery_status' => 10,
        'receipt_status' => 10,
        'ios_refund_risk_status' => RiskStatus::NONE,
        'ios_refund_risk_source' => '',
        'ios_refund_risk_time' => 0,
    ]);
    [$startWinner, $waitingApply] = competeAfterOrderLock(
        $orderId,
        $storeId,
        'apply',
        static function () use ($orderId) {
            $storeOrder = StoreOrder::detail($orderId);
            return $storeOrder->startService();
        },
        [(int)$goods['order_goods_id'], $userId, $marker . '_START_FIRST_APPLY']
    );
    expectSame(true, (bool)$startWinner, 'start can commit before a waiting local apply');
    expectSame(true, (bool)$waitingApply['result'], 'waiting local apply rechecks the started service and succeeds');
    $afterStartFirst = CommonOrder::detail($orderId);
    $startFirstRefund = (new CommonOrderRefund)
        ->where('order_id', $orderId)
        ->where('type', RefundType::SERVICE)
        ->where('apply_desc', $marker . '_START_FIRST_APPLY')
        ->find();
    expectSame(20, (int)$afterStartFirst['delivery_status'], 'start-first order preserves service-start history');
    expectSame(RiskStatus::LOCKED, (int)$afterStartFirst['ios_refund_risk_status'], 'waiting apply freezes after start commits');
    expectSame(AuditStatus::WAIT, (int)$startFirstRefund['audit_status'], 'start-first apply uses current IN_PROGRESS state');

    // Authenticated inquiry owns the order lock first; concurrent completion must wait, re-read and fail.
    $resetFixture();
    $refundId = (int)array_key_first($refundSnapshots);
    Db::name('order_refund')->where('order_refund_id', $refundId)->update([
        'type' => RefundType::SERVICE,
        'status' => RefundStatus::NORMAL,
        'audit_status' => AuditStatus::WAIT,
    ]);
    Db::name('order')->where('order_id', $orderId)->update([
        'order_status' => 10,
        'pay_status' => 20,
        'delivery_status' => 20,
        'receipt_status' => 10,
        'ios_refund_risk_status' => RiskStatus::NONE,
        'ios_refund_risk_source' => '',
        'ios_refund_risk_time' => 0,
    ]);
    $inquiryPayload = [
        'Event' => 'xpay_subscribe_ios_refund_query_notify',
        'pay_order_id' => (string)$trade['out_trade_no'],
        'refund_request_reason' => $marker . '_INQUIRY_VS_COMPLETE',
    ];
    [$inquiryResult, $completeResult] = competeAfterOrderLock(
        $orderId,
        $storeId,
        'complete',
        static function () use ($trade, $inquiryPayload) {
            return (new IosRefundRisk)->handleInquiry((string)$trade['out_trade_no'], $inquiryPayload);
        }
    );
    expectSame(1, (int)$inquiryResult['result_code'], 'in-progress WAIT inquiry recommends rejection');
    expectSame(false, (bool)$completeResult['result'], 'concurrent completion rechecks and rejects after inquiry');
    $afterInquiry = CommonOrder::detail($orderId);
    expectSame(RiskStatus::LOCKED, (int)$afterInquiry['ios_refund_risk_status'], 'inquiry commits LOCKED before completion returns');
    expectSame(10, (int)$afterInquiry['receipt_status'], 'rejected concurrent completion preserves receipt state');

    // Inquiry risk commit and provide_goods dispatch claim share the same order lock linearization point.
    $resetFixture();
    Db::name('order_refund')->where('order_refund_id', $refundId)->update([
        'type' => RefundType::SERVICE,
        'status' => RefundStatus::NORMAL,
        'audit_status' => AuditStatus::REVIEWED,
    ]);
    Db::name('order')->where('order_id', $orderId)->update([
        'order_status' => 30,
        'pay_status' => 20,
        'delivery_status' => 20,
        'receipt_status' => 20,
        'ios_refund_risk_status' => RiskStatus::NONE,
        'ios_refund_risk_source' => '',
        'ios_refund_risk_time' => 0,
    ]);
    $dispatchPayload = [
        'Event' => 'xpay_subscribe_ios_refund_query_notify',
        'pay_order_id' => (string)$trade['out_trade_no'],
        'refund_request_reason' => $marker . '_INQUIRY_VS_DISPATCH',
    ];
    [$dispatchInquiry, $dispatchClaim] = competeAfterOrderLock(
        $orderId,
        $storeId,
        'claim',
        static function () use ($trade, $dispatchPayload) {
            return (new IosRefundRisk)->handleInquiry((string)$trade['out_trade_no'], $dispatchPayload);
        },
        [(int)$trade['trade_id']]
    );
    expectSame(1, (int)$dispatchInquiry['result_code'], 'completed inquiry rejects refund recommendation while freezing risk');
    expectSame(false, (bool)$dispatchClaim['result'], 'dispatch claim waiting behind inquiry cannot start after LOCKED');
    expectSame('ios_refund_risk_locked', (string)$dispatchClaim['error'], 'dispatch rejection exposes structured risk reason');

    // Merchant approval commits while inquiry is blocked; inquiry must read REVIEWED after the lock release.
    $resetFixture();
    Db::name('order_refund')->where('order_refund_id', $refundId)->update([
        'type' => RefundType::SERVICE,
        'status' => RefundStatus::NORMAL,
        'audit_status' => AuditStatus::WAIT,
        'refuse_desc' => '',
    ]);
    Db::name('order')->where('order_id', $orderId)->update([
        'order_status' => 10,
        'pay_status' => 20,
        'delivery_status' => 20,
        'receipt_status' => 10,
        'ios_refund_risk_status' => RiskStatus::LOCKED,
        'ios_refund_risk_source' => RiskSource::LOCAL_APPLY,
        'ios_refund_risk_time' => time(),
    ]);
    $auditPayload = [
        'Event' => 'xpay_subscribe_ios_refund_query_notify',
        'pay_order_id' => (string)$trade['out_trade_no'],
        'refund_request_reason' => $marker . '_AUDIT_VS_INQUIRY',
    ];
    Db::startTrans();
    $inquiryChild = null;
    try {
        Db::name('order')->where('order_id', $orderId)->lock(true)->find();
        Db::name('order_refund')->where('order_id', $orderId)->where('type', RefundType::SERVICE)->lock(true)->select();
        Db::name('payment_trade')->where('trade_id', (int)$trade['trade_id'])->lock(true)->find();
        $inquiryChild = proc_open(
            [PHP_BINARY, '-r', sprintf(
                'require %s; (new think\\App())->initialize(); echo json_encode((new app\\common\\service\\order\\IosRefundRisk())->handleInquiry(%s, %s), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), PHP_EOL;',
                var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true),
                var_export((string)$trade['out_trade_no'], true),
                var_export($auditPayload, true)
            )],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $auditPipes,
            dirname(__DIR__, 2)
        );
        if (!is_resource($inquiryChild)) {
            throw new RuntimeException('failed to start inquiry child');
        }
        fclose($auditPipes[0]);
        usleep(250000);
        expectSame(true, (bool)proc_get_status($inquiryChild)['running'], 'inquiry blocks behind merchant audit order lock');
        $storeRefund = StoreOrderRefund::detail($refundId);
        $auditResult = $storeRefund->audit(['audit_status' => AuditStatus::REVIEWED]);
        Db::commit();
        $deadline = microtime(true) + 10;
        do {
            $status = proc_get_status($inquiryChild);
            if (empty($status['running'])) break;
            usleep(50000);
        } while (microtime(true) < $deadline);
        $auditStdout = trim((string)stream_get_contents($auditPipes[1]));
        $auditStderr = trim((string)stream_get_contents($auditPipes[2]));
        fclose($auditPipes[1]);
        fclose($auditPipes[2]);
        proc_close($inquiryChild);
        if ($auditStderr !== '') {
            throw new RuntimeException('inquiry child failed: ' . $auditStderr);
        }
        $auditLines = explode("\n", $auditStdout);
        $auditInquiryResult = json_decode((string)end($auditLines), true);
        expectSame(true, (bool)$auditResult, 'merchant approval commits while inquiry is waiting');
        expectSame(0, (int)($auditInquiryResult['result_code'] ?? -1), 'waiting inquiry reads latest REVIEWED state');
    } catch (Throwable $e) {
        try { Db::rollback(); } catch (Throwable $ignored) {}
        if (is_resource($inquiryChild)) proc_terminate($inquiryChild);
        throw $e;
    }

    fwrite(STDOUT, "PASS iOS refund two-connection concurrency contracts\n");
} finally {
    $resetFixture();
}
