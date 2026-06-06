<?php
// +----------------------------------------------------------------------
// | 服务单E2E自动化命令
// +----------------------------------------------------------------------
declare (strict_types=1);

namespace app\common\command;

use app\common\enum\order\DeliveryStatus as DeliveryStatusEnum;
use app\common\enum\order\DeliveryType as DeliveryTypeEnum;
use app\common\enum\order\OrderStatus as OrderStatusEnum;
use app\common\enum\order\PayStatus as PayStatusEnum;
use app\common\enum\order\ReceiptStatus as ReceiptStatusEnum;
use app\common\enum\order\refund\AuditStatus as RefundAuditStatusEnum;
use app\common\enum\order\refund\RefundStatus as RefundStatusEnum;
use app\common\enum\order\refund\RefundType as RefundTypeEnum;
use app\common\enum\payment\Method as PaymentMethodEnum;
use app\common\model\Order as OrderModel;
use app\common\model\User as UserModel;
use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;
use think\facade\Db;
use RuntimeException;

class ServiceOrderE2eAutomation extends Command
{
    private const FIXTURES = ['service-order', 'physical-order', 'mixed-order', 'refunding', 'completed-service'];
    private const STORE_ID = 10001;
    private const DEBUG_MOBILE = '19900000000';
    private const REAL_ORDER_REMARK_PREFIX = 'E2E_REAL:';

    private string $evidenceDir = '/opt/yoshop/runtime/service-order-e2e';

    protected function configure()
    {
        $this->setName('service-order:e2e')
            ->addArgument('action', Argument::OPTIONAL, 'identity/plan/run/fixtures/cleanup/check', 'identity')
            ->addOption('identity', null, Option::VALUE_OPTIONAL, '测试身份标识', 'debug')
            ->addOption('target', null, Option::VALUE_OPTIONAL, '运行目标: all/h5/mp-weixin/backend', 'all')
            ->addOption('h5-url', null, Option::VALUE_OPTIONAL, 'H5地址', 'http://localhost/')
            ->addOption('backend-url', null, Option::VALUE_OPTIONAL, '后台地址', 'http://localhost/store/')
            ->addOption('backend-user', null, Option::VALUE_OPTIONAL, '后台用户名', 'admin')
            ->addOption('backend-pass', null, Option::VALUE_OPTIONAL, '后台密码', 'yinghuo')
            ->addOption('wechat-cli', null, Option::VALUE_OPTIONAL, '微信开发者工具CLI路径', '/mnt/d/Program/soft/wechattools/cli.bat')
            ->addOption('hbuilderx-cli', null, Option::VALUE_OPTIONAL, 'HBuilderX CLI路径', '/mnt/d/Program/tools/HBuilderX/cli.exe')
            ->addOption('windows-uniapp-dir', null, Option::VALUE_OPTIONAL, 'Windows镜像uni-app目录', 'D:\\Program\\0\\home\\0\\yoshop1\\yoshop2.0-uniapp')
            ->addOption('fixture', null, Option::VALUE_OPTIONAL, 'fixture名称(all/service-order/physical-order/mixed-order/refunding/completed-service/cleanup)', 'all')
            ->addOption('check', null, Option::VALUE_OPTIONAL, 'check范围(all/h5/mp-weixin/backend)', 'all')
            ->addOption('mode', null, Option::VALUE_OPTIONAL, '运行模式(real/legacy/all)', 'real')
            ->addOption('service-goods-id', null, Option::VALUE_OPTIONAL, '真实下单使用的服务商品ID', '')
            ->addOption('service-goods-sku-id', null, Option::VALUE_OPTIONAL, '真实下单使用的服务商品SKU ID', '')
            ->addOption('min-balance', null, Option::VALUE_OPTIONAL, '调试用户最低余额', '200')
            ->addOption('evidence-dir', null, Option::VALUE_OPTIONAL, '失败证据输出目录', '/opt/yoshop/runtime/service-order-e2e')
            ->addOption('dry-run', null, Option::VALUE_NONE, '只输出计划，不执行外部命令')
            ->setDescription('服务单E2E自动化入口');
    }

    protected function execute(Input $input, Output $output)
    {
        $action = (string)$input->getArgument('action');
        $this->evidenceDir = rtrim((string)$input->getOption('evidence-dir'), '/');

        if ($action === 'identity') {
            return $this->renderIdentity($output, (string)$input->getOption('identity'));
        }

        $config = $this->buildConfig($input);

        if ($action === 'plan') {
            $this->renderPlan($output, $config);
            return 0;
        }

        if ($action === 'run') {
            $this->renderPlan($output, $config);
            if ((bool)$input->getOption('dry-run')) {
                return 0;
            }
            return $this->runExternalPlan($output, $config);
        }

        if ($action === 'fixtures') {
            return $this->runFixtures($output, $config, (string)$input->getOption('fixture')) ? 0 : 1;
        }

        if ($action === 'cleanup') {
            return $this->cleanupGeneratedFixtures($output) ? 0 : 1;
        }

        if ($action === 'check') {
            return $this->runChecks($output, $config, (string)$input->getOption('check')) ? 0 : 1;
        }

        $output->writeln(sprintf('<error>不支持的 action: %s</error>', $action));
        return 1;
    }

    private function renderIdentity(Output $output, string $identity): int
    {
        $output->writeln('service-order:e2e identity');
        $output->writeln(sprintf('identity: %s', $identity));
        $output->writeln('debug_test_mobile: 19900000000');
        $output->writeln('debug_login_endpoint: /passport/loginDebug');
        return 0;
    }

    /** @return array<string, mixed> */
    private function buildConfig(Input $input): array
    {
        return [
            'target' => $this->normalizeTargets((string)$input->getOption('target')),
            'h5_url' => rtrim((string)$input->getOption('h5-url'), '/') . '/',
            'backend_url' => rtrim((string)$input->getOption('backend-url'), '/') . '/',
            'backend_user' => (string)$input->getOption('backend-user'),
            'backend_pass' => (string)$input->getOption('backend-pass'),
            'wechat_cli' => (string)$input->getOption('wechat-cli'),
            'hbuilderx_cli' => (string)$input->getOption('hbuilderx-cli'),
            'windows_uniapp_dir' => (string)$input->getOption('windows-uniapp-dir'),
            'mode' => $this->normalizeMode((string)$input->getOption('mode')),
            'service_goods_id' => (int)$input->getOption('service-goods-id'),
            'service_goods_sku_id' => trim((string)$input->getOption('service-goods-sku-id')),
            'min_balance' => (float)$input->getOption('min-balance'),
        ];
    }

    private function normalizeMode(string $mode): string
    {
        $mode = trim($mode);
        if ($mode === '') {
            return 'real';
        }
        if (!in_array($mode, ['real', 'legacy', 'all'], true)) {
            throw new RuntimeException(sprintf('不支持的 mode: %s', $mode));
        }
        return $mode;
    }

    private function normalizeTargets(string $targets): array
    {
        $targets = trim($targets);
        if ($targets === '' || $targets === 'all') {
            return ['h5', 'mp-weixin', 'backend'];
        }
        $items = array_values(array_filter(array_map('trim', explode(',', $targets))));
        foreach ($items as $item) {
            if (!in_array($item, ['h5', 'mp-weixin', 'backend'], true)) {
                throw new RuntimeException(sprintf('不支持的 target: %s', $item));
            }
        }
        return array_values(array_unique($items));
    }

    private function renderPlan(Output $output, array $config): void
    {
        $output->writeln('service-order:e2e runner');
        $output->writeln(sprintf('targets: %s', implode(', ', $config['target'])));
        $output->writeln(sprintf('h5_url: %s', $config['h5_url']));
        $output->writeln(sprintf('backend_url: %s', $config['backend_url']));
        $output->writeln(sprintf('backend_user: %s', $config['backend_user']));
        $output->writeln(sprintf('wechat_cli: %s', $config['wechat_cli']));
        $output->writeln(sprintf('hbuilderx_cli: %s', $config['hbuilderx_cli']));
        $output->writeln(sprintf('windows_uniapp_dir: %s', $config['windows_uniapp_dir']));
        $output->writeln(sprintf('mode: %s', $config['mode']));
        $output->writeln(sprintf('service_goods_id: %d', (int)$config['service_goods_id']));
        $output->writeln(sprintf('service_goods_sku_id: %s', (string)$config['service_goods_sku_id']));
        $output->writeln(sprintf('min_balance: %s', (string)$config['min_balance']));
        $output->writeln('steps: check -> cleanup');
    }

    private function runExternalPlan(Output $output, array $config): int
    {
        $output->writeln('<info>dry-run only: external orchestration kept minimal for safe execution</info>');
        $this->ensureEvidenceDir();
        $this->writeJson('plan-' . date('YmdHis') . '.json', $config);
        return 0;
    }

    private function runFixtures(Output $output, array $config, string $fixtureOption): bool
    {
        $fixtures = $fixtureOption === 'all' ? self::FIXTURES : array_values(array_filter(array_map('trim', explode(',', $fixtureOption))));
        foreach ($fixtures as $fixture) {
            if (!in_array($fixture, self::FIXTURES, true)) {
                throw new RuntimeException(sprintf('不支持的 fixture: %s', $fixture));
            }
            $row = $this->ensureFixture($fixture);
            $output->writeln(sprintf('<info>fixture ok:</info> %s => %d / %s', $fixture, $row['order_id'], $row['order_no']));
        }
        $this->writeJson('fixtures-' . date('YmdHis') . '.json', ['fixtures' => $fixtures, 'config' => $config]);
        return true;
    }

    private function cleanupGeneratedFixtures(Output $output): bool
    {
        $counts = $this->cleanupGeneratedData();
        $output->writeln(sprintf('<info>cleanup ok:</info> orders=%d goods=%d refunds=%d', $counts['orders'], $counts['goods'], $counts['refunds']));
        $this->writeJson('cleanup-' . date('YmdHis') . '.json', $counts);
        return true;
    }

    private function runChecks(Output $output, array $config, string $checkOption): bool
    {
        $checks = $this->normalizeChecks($checkOption);
        $client = new ServiceOrderE2eHttpClient($this->evidenceDir);
        $tokens = $this->loginTokens($client, $config);

        if ($config['mode'] === 'legacy' || $config['mode'] === 'all') {
            $fixtures = $this->ensureAllFixtures();

            if (isset($checks['h5'])) {
                $this->checkH5($client, $fixtures, $tokens['h5'], $config['h5_url']);
                $output->writeln('<info>h5 legacy checks ok</info>');
            }
            if (isset($checks['mp-weixin'])) {
                $this->checkMiniProgram($client, $fixtures, $tokens['h5'], $config);
                $output->writeln('<info>mp-weixin legacy checks ok</info>');
            }
            if (isset($checks['backend'])) {
                $this->checkBackend($client, $fixtures, $tokens['backend'], $config['backend_url']);
                $output->writeln('<info>backend legacy checks ok</info>');
            }
        }

        if ($config['mode'] === 'real' || $config['mode'] === 'all') {
            $result = $this->runRealOrderChecks($client, $tokens, $config, array_keys($checks));
            foreach ($result['messages'] as $message) {
                $output->writeln('<info>' . $message . '</info>');
            }
        }

        $this->writeJson('check-' . date('YmdHis') . '.json', ['mode' => $config['mode'], 'checks' => array_keys($checks)]);
        return true;
    }

    /** @param array<string, mixed> $tokens
     *  @param array<int, string> $checks
     *  @return array{messages: array<int, string>, run_id: string, service_goods_id:int, unpaid_order_id:int, paid_order_id:int, completed_order_id:int, refunded_order_id:int}
     */
    private function runRealOrderChecks($client, array $tokens, array $config, array $checks): array
    {
        $runId = 'run-' . date('YmdHis') . '-' . random_int(100, 999);
        $goods = $this->resolveServiceGoods($config);
        $this->ensureDebugUserBalance((float)$config['min_balance']);

        $unpaidOrderId = $this->createRealServiceOrder($client, $config['h5_url'], $tokens['h5'], $goods, $runId, 'UNPAID');
        $paidOrderId = $this->createRealServiceOrder($client, $config['h5_url'], $tokens['h5'], $goods, $runId, 'PAID');
        $refundedOrderId = $this->createRealServiceOrder($client, $config['h5_url'], $tokens['h5'], $goods, $runId, 'REFUND');
        $completedOrderId = $this->createRealServiceOrder($client, $config['h5_url'], $tokens['h5'], $goods, $runId, 'COMPLETE');
        $auditApprovedOrderId = $this->createRealServiceOrder($client, $config['h5_url'], $tokens['h5'], $goods, $runId, 'AUDIT_APPROVE');
        $auditRejectedOrderId = $this->createRealServiceOrder($client, $config['h5_url'], $tokens['h5'], $goods, $runId, 'AUDIT_REJECT');
        $duplicateRefundOrderId = $this->createRealServiceOrder($client, $config['h5_url'], $tokens['h5'], $goods, $runId, 'DUPLICATE');

        $this->payOrderWithBalance($client, $config['h5_url'], $tokens['h5'], $paidOrderId);
        $this->payOrderWithBalance($client, $config['h5_url'], $tokens['h5'], $refundedOrderId);
        $this->payOrderWithBalance($client, $config['h5_url'], $tokens['h5'], $completedOrderId);
        $this->payOrderWithBalance($client, $config['h5_url'], $tokens['h5'], $auditApprovedOrderId);
        $this->payOrderWithBalance($client, $config['h5_url'], $tokens['h5'], $auditRejectedOrderId);
        $this->payOrderWithBalance($client, $config['h5_url'], $tokens['h5'], $duplicateRefundOrderId);

        $backendBase = $this->getBackendApiBase($config['backend_url']);

        $this->refundBeforeService($client, $backendBase, $tokens['backend'], $refundedOrderId);
        $this->startService($client, $backendBase, $tokens['backend'], $auditApprovedOrderId);
        $approvedRefundId = $this->applyRefund($client, $config['h5_url'], $tokens['h5'], $auditApprovedOrderId);
        $this->auditRefund($client, $backendBase, $tokens['backend'], $approvedRefundId, RefundAuditStatusEnum::REVIEWED);

        $this->startService($client, $backendBase, $tokens['backend'], $auditRejectedOrderId);
        $rejectedRefundId = $this->applyRefund($client, $config['h5_url'], $tokens['h5'], $auditRejectedOrderId);
        $this->auditRefund($client, $backendBase, $tokens['backend'], $rejectedRefundId, RefundAuditStatusEnum::REJECTED, 'E2E reject');

        $this->startService($client, $backendBase, $tokens['backend'], $duplicateRefundOrderId);
        $duplicateRefundFirstId = $this->applyRefund($client, $config['h5_url'], $tokens['h5'], $duplicateRefundOrderId);
        $this->applyRefundExpectFailure($client, $config['h5_url'], $tokens['h5'], $duplicateRefundOrderId, '当前订单已存在进行中的售后单');

        $this->startService($client, $backendBase, $tokens['backend'], $completedOrderId);
        $this->completeService($client, $backendBase, $tokens['backend'], $completedOrderId);
        $this->applyRefundExpectFailure($client, $config['h5_url'], $tokens['h5'], $completedOrderId, '当前服务阶段不允许申请退款');

        $messages = [];

        if (in_array('h5', $checks, true)) {
            $this->assertRealH5Scenarios($client, $config['h5_url'], $tokens['h5'], $unpaidOrderId, $paidOrderId, $refundedOrderId, $completedOrderId, $auditApprovedOrderId, $auditRejectedOrderId, $duplicateRefundOrderId, $runId);
            $messages[] = 'h5 real-order checks ok';
        }
        if (in_array('backend', $checks, true)) {
            $this->assertRealBackendScenarios($client, $backendBase, $tokens['backend'], $unpaidOrderId, $paidOrderId, $refundedOrderId, $completedOrderId, $auditApprovedOrderId, $auditRejectedOrderId, $duplicateRefundOrderId, $runId);
            $messages[] = 'backend real-order checks ok';
        }
        if (in_array('mp-weixin', $checks, true)) {
            $this->assertRealMiniProgramScenarios($client, $config, $tokens['h5'], $paidOrderId, $runId);
            $messages[] = 'mp-weixin real-order checks ok';
        }

        $this->writeJson('real-order-' . date('YmdHis') . '.json', [
            'run_id' => $runId,
            'service_goods_id' => $goods['goods_id'],
            'service_goods_sku_id' => $goods['goods_sku_id'],
            'orders' => [
                'unpaid' => $unpaidOrderId,
                'paid' => $paidOrderId,
                'refunded' => $refundedOrderId,
                'completed' => $completedOrderId,
                'audit_approved' => $auditApprovedOrderId,
                'audit_rejected' => $auditRejectedOrderId,
                'duplicate_refund' => $duplicateRefundOrderId,
            ],
            'refund_ids' => [
                'approved' => $approvedRefundId,
                'rejected' => $rejectedRefundId,
                'duplicate_first' => $duplicateRefundFirstId,
            ],
        ]);

        return [
            'messages' => $messages,
            'run_id' => $runId,
            'service_goods_id' => (int)$goods['goods_id'],
            'unpaid_order_id' => $unpaidOrderId,
            'paid_order_id' => $paidOrderId,
            'completed_order_id' => $completedOrderId,
            'refunded_order_id' => $refundedOrderId,
        ];
    }

    private function normalizeChecks(string $checkOption): array
    {
        $checkOption = trim($checkOption);
        if ($checkOption === '' || $checkOption === 'all') {
            return ['h5' => true, 'mp-weixin' => true, 'backend' => true];
        }
        $items = array_values(array_filter(array_map('trim', explode(',', $checkOption))));
        $allowed = ['h5' => true, 'mp-weixin' => true, 'backend' => true];
        $result = [];
        foreach ($items as $item) {
            if (!isset($allowed[$item])) {
                throw new RuntimeException(sprintf('不支持的 check: %s', $item));
            }
            $result[$item] = true;
        }
        return $result;
    }

    /** @return array<string, mixed> */
    private function loginTokens($client, array $config): array
    {
        $h5 = $client->postJson($config['h5_url'] . 'index.php?s=/api/passport/loginDebug', [], ['platform' => 'H5']);
        $backendBase = $this->getBackendApiBase($config['backend_url']);
        $backend = $client->postJson($backendBase . 'index.php?s=/store/passport/login', [
            'username' => $config['backend_user'],
            'password' => $config['backend_pass'],
        ]);
        if (empty($h5['data']['data']['token']) || empty($backend['data']['data']['token'])) {
            $this->writeFailure('login', ['h5' => $h5, 'backend' => $backend]);
            throw new RuntimeException('登录 token 获取失败');
        }
        return [
            'h5' => (string)$h5['data']['data']['token'],
            'backend' => (string)$backend['data']['data']['token'],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private function ensureAllFixtures(): array
    {
        $rows = [];
        foreach (self::FIXTURES as $fixture) {
            $rows[$fixture] = $this->ensureFixture($fixture);
        }
        return $rows;
    }

    /** @return array{order_id:int,order_no:string} */
    private function ensureFixture(string $fixture): array
    {
        $existing = $this->findGeneratedFixture($fixture);
        if (!empty($existing)) {
            return ['order_id' => (int)$existing['order_id'], 'order_no' => (string)$existing['order_no']];
        }
        $source = $this->findSourceOrder($fixture);
        if (empty($source)) {
            throw new RuntimeException(sprintf('未找到源订单: %s', $fixture));
        }
        return $this->cloneFixture($fixture, $source);
    }

    /** @return array<string, mixed> */
    private function findGeneratedFixture(string $fixture): array
    {
        return Db::name('order')
            ->where('order_no', 'like', 'E2E%')
            ->where('user_id', '=', $this->getOrCreateDebugUserId())
            ->order('order_id', 'desc')
            ->select()
            ->filter(function ($row) use ($fixture) {
                $sourceData = $this->decodeJson((string)($row['order_source_data'] ?? ''));
                return (string)($sourceData['service_order_e2e']['fixture'] ?? '') === $fixture;
            })
            ->first() ?: [];
    }

    /** @return array<string, mixed> */
    private function findSourceOrder(string $fixture): array
    {
        $rows = Db::name('order')->where('is_delete', '=', 0)->order('order_id', 'desc')->select()->toArray();

        if ($fixture === 'service-order') {
            foreach ($rows as $row) {
                if (OrderModel::isServiceOrderData($row)) {
                    return $row;
                }
            }
            return [];
        }
        if ($fixture === 'mixed-order') {
            foreach ($rows as $row) {
                $service = OrderModel::getServiceContactData($row);
                if ((int)$row['delivery_type'] !== DeliveryTypeEnum::NOTHING && !empty($service)) {
                    return $row;
                }
            }
            return [];
        }
        if ($fixture === 'refunding') {
            $row = Db::name('order')->alias('o')->join('order_refund r', 'r.order_id = o.order_id')->where('o.is_delete', '=', 0)->where('r.type', '=', RefundTypeEnum::SERVICE)->where('r.status', '=', RefundStatusEnum::NORMAL)->order('o.order_id', 'desc')->field('o.*')->find();
            return $row ?: [];
        }
        if ($fixture === 'physical-order') {
            foreach ($rows as $row) {
                if (!OrderModel::isServiceOrderData($row)) {
                    return $row;
                }
            }
            return [];
        }
        if ($fixture === 'completed-service') {
            foreach ($rows as $row) {
                if (OrderModel::isServiceOrderData($row)) {
                    return $row;
                }
            }
            return [];
        }
        return [];
    }

    /** @param array<string, mixed> $source */
    private function cloneFixture(string $fixture, array $source): array
    {
        $debugUserId = $this->getOrCreateDebugUserId();
        $newOrderNo = $this->generateFixtureOrderNo($fixture);
        $sourceData = $this->decodeJson((string)($source['order_source_data'] ?? ''));
        $sourceData['service_contact'] = $this->composeServiceContact($fixture, $source, $sourceData['service_contact'] ?? []);
        $sourceData['service_order_e2e'] = [
            'fixture' => $fixture,
            'source_order_id' => (int)$source['order_id'],
            'created_at' => date('c'),
        ];

        $orderRow = $source;
        unset($orderRow['order_id']);
        $orderRow['order_no'] = $newOrderNo;
        $orderRow['order_source_data'] = json_encode($sourceData, JSON_UNESCAPED_UNICODE);
        $orderRow['create_time'] = time();
        $orderRow['update_time'] = time();
        $orderRow['is_delete'] = 0;
        $orderRow['store_id'] = self::STORE_ID;
        $orderRow['user_id'] = $debugUserId;
        $orderRow['order_status'] = in_array($fixture, ['service-order', 'physical-order', 'mixed-order', 'refunding'], true)
            ? OrderStatusEnum::NORMAL
            : OrderStatusEnum::COMPLETED;
        $orderRow['delivery_status'] = in_array($fixture, ['service-order', 'refunding', 'mixed-order'], true)
            ? DeliveryStatusEnum::NOT_DELIVERED
            : ($fixture === 'completed-service' ? DeliveryStatusEnum::DELIVERED : (int)$orderRow['delivery_status']);
        $orderRow['receipt_status'] = in_array($fixture, ['service-order', 'refunding', 'mixed-order'], true)
            ? ReceiptStatusEnum::NOT_RECEIVED
            : ($fixture === 'completed-service' ? ReceiptStatusEnum::RECEIVED : (int)$orderRow['receipt_status']);
        $orderRow['delivery_type'] = $fixture === 'physical-order' || $fixture === 'mixed-order'
            ? max(1, (int)($source['delivery_type'] ?? DeliveryTypeEnum::EXPRESS))
            : DeliveryTypeEnum::NOTHING;
        $orderRow['pay_status'] = PayStatusEnum::SUCCESS;
        $orderRow['pay_time'] = time();
        $orderRow['delivery_time'] = $orderRow['delivery_status'] === DeliveryStatusEnum::DELIVERED ? time() : 0;
        $orderRow['receipt_time'] = $orderRow['receipt_status'] === ReceiptStatusEnum::RECEIVED ? time() : 0;

        $orderId = (int)Db::name('order')->insertGetId($orderRow);
        $goods = Db::name('order_goods')->where('order_id', '=', (int)$source['order_id'])->select()->toArray();
        foreach ($goods as $item) {
            unset($item['order_goods_id']);
            $item['order_id'] = $orderId;
            $item['user_id'] = $debugUserId;
            $item['create_time'] = time();
            $item['store_id'] = self::STORE_ID;
            Db::name('order_goods')->insert($item);
        }
        if ($fixture === 'refunding') {
            $this->ensureRefundRow($orderId, $newOrderNo);
        }
        if ($fixture === 'completed-service') {
            Db::name('order')->where('order_id', '=', $orderId)->update([
                'delivery_status' => DeliveryStatusEnum::DELIVERED,
                'receipt_status' => ReceiptStatusEnum::RECEIVED,
                'receipt_time' => time(),
            ]);
        }
        return ['order_id' => $orderId, 'order_no' => $newOrderNo];
    }

    /** @param array<string, mixed> $source */
    private function composeServiceContact(string $fixture, array $source, array $current): array
    {
        if ($fixture === 'physical-order') {
            return [];
        }
        if (!empty($current)) {
            return $current;
        }
        return $fixture === 'mixed-order'
            ? ['contact_name' => 'Legacy Mixed', 'contact_mobile' => '19900000001', 'time_preference' => 'legacy-mixed']
            : ['contact_name' => 'Service E2E', 'contact_mobile' => '19900000000', 'time_preference' => 'anytime'];
    }

    private function ensureRefundRow(int $orderId, string $orderNo): void
    {
        $goods = Db::name('order_goods')->where('order_id', '=', $orderId)->order('order_goods_id', 'asc')->find();
        if (empty($goods)) {
            throw new RuntimeException('退款 fixture 无法创建：缺少商品');
        }
        Db::name('order_refund')->insert([
            'order_goods_id' => (int)$goods['order_goods_id'],
            'order_id' => $orderId,
            'user_id' => (int)$goods['user_id'],
            'type' => RefundTypeEnum::SERVICE,
            'apply_desc' => 'E2E refunding fixture for ' . $orderNo,
            'audit_status' => 0,
            'status' => RefundStatusEnum::NORMAL,
            'store_id' => self::STORE_ID,
            'create_time' => time(),
            'update_time' => time(),
        ]);
    }

    private function generateFixtureOrderNo(string $fixture): string
    {
        $map = [
            'service-order' => 'svc',
            'physical-order' => 'phy',
            'mixed-order' => 'mix',
            'refunding' => 'ref',
            'completed-service' => 'cmp',
        ];
        return sprintf('E2E%s%s%03d', strtoupper($map[$fixture] ?? 'fix'), date('mdHis'), random_int(0, 999));
    }

    /** @return array{goods_id:int,goods_sku_id:string,goods_name:string} */
    private function resolveServiceGoods(array $config): array
    {
        $goodsId = (int)($config['service_goods_id'] ?? 0);
        if ($goodsId <= 0) {
            $goods = Db::name('goods')->alias('g')
                ->join('goods_service_rel r', 'r.goods_id = g.goods_id')
                ->where('g.is_delete', '=', 0)
                ->where('g.status', '=', 10)
                ->field('g.goods_id,g.goods_name')
                ->group('g.goods_id')
                ->order('g.goods_id', 'asc')
                ->find();
            if (empty($goods)) {
                throw new RuntimeException('未找到可用的上架服务商品');
            }
            $goodsId = (int)$goods['goods_id'];
        }

        $goods = Db::name('goods')->where('goods_id', '=', $goodsId)->where('is_delete', '=', 0)->where('status', '=', 10)->find();
        if (empty($goods)) {
            throw new RuntimeException(sprintf('指定服务商品不可用: %d', $goodsId));
        }
        $serviceIds = Db::name('goods_service_rel')->where('goods_id', '=', $goodsId)->column('service_id');
        if (empty($serviceIds)) {
            throw new RuntimeException(sprintf('指定商品不是服务商品: %d', $goodsId));
        }

        $goodsSkuId = trim((string)($config['service_goods_sku_id'] ?? ''));
        if ($goodsSkuId === '') {
            $sku = Db::name('goods_sku')->where('goods_id', '=', $goodsId)->order('goods_sku_id', 'asc')->find();
            if (empty($sku)) {
                throw new RuntimeException(sprintf('服务商品缺少 SKU: %d', $goodsId));
            }
            $goodsSkuId = (string)$sku['goods_sku_id'];
        } else {
            $sku = Db::name('goods_sku')->where('goods_id', '=', $goodsId)->where('goods_sku_id', '=', $goodsSkuId)->find();
            if (empty($sku)) {
                throw new RuntimeException(sprintf('指定服务商品 SKU 不存在: %s', $goodsSkuId));
            }
        }
        if ((int)($sku['stock_num'] ?? 0) <= 0) {
            throw new RuntimeException(sprintf('服务商品 SKU 库存不足: %s', $goodsSkuId));
        }

        return [
            'goods_id' => $goodsId,
            'goods_sku_id' => $goodsSkuId,
            'goods_name' => (string)$goods['goods_name'],
        ];
    }

    private function ensureDebugUserBalance(float $minBalance): void
    {
        $userId = $this->getOrCreateDebugUserId();
        $user = UserModel::detail($userId);
        if (empty($user)) {
            throw new RuntimeException('调试用户不存在');
        }
        $current = (float)($user['balance'] ?? 0);
        if ($current >= $minBalance) {
            return;
        }
        UserModel::setIncBalance($userId, $minBalance - $current + 10);
    }

    private function createRealServiceOrder($client, string $baseUrl, string $token, array $goods, string $runId, string $scenario): int
    {
        $remark = self::REAL_ORDER_REMARK_PREFIX . $runId . ':' . $scenario;
        $payload = [
            'goodsId' => (int)$goods['goods_id'],
            'goodsSkuId' => (string)$goods['goods_sku_id'],
            'goodsNum' => 1,
            'scene' => 'service',
            'contactName' => 'E2E Service User',
            'contactMobile' => self::DEBUG_MOBILE,
            'timePreference' => 'anytime',
            'remark' => $remark,
        ];
        $response = $client->postJson($baseUrl . 'index.php?s=/api/checkout/submit&mode=buyNow', $payload, [
            'Access-Token' => $token,
            'platform' => 'H5',
        ]);
        $orderId = (int)($response['data']['data']['orderId'] ?? 0);
        if ($orderId <= 0) {
            $this->writeFailure('real-order-create', ['scenario' => $scenario, 'payload' => $payload, 'response' => $response]);
            throw new RuntimeException(sprintf('真实下单失败: %s', $scenario));
        }
        return $orderId;
    }

    private function payOrderWithBalance($client, string $baseUrl, string $token, int $orderId): void
    {
        $response = $client->postJson($baseUrl . 'index.php?s=/api/cashier/orderPay', [
            'orderId' => $orderId,
            'method' => PaymentMethodEnum::BALANCE,
            'client' => 'H5',
        ], [
            'Access-Token' => $token,
            'platform' => 'H5',
        ]);
        if (!(bool)($response['ok'] ?? false)) {
            $this->writeFailure('real-order-pay', ['order_id' => $orderId, 'response' => $response]);
            throw new RuntimeException(sprintf('余额支付失败: %d', $orderId));
        }
    }

    private function cancelOrder($client, string $baseUrl, string $token, int $orderId): void
    {
        $response = $client->postJson($baseUrl . 'index.php?s=/api/order/cancel', ['orderId' => $orderId], [
            'Access-Token' => $token,
            'platform' => 'H5',
        ]);
        if (!(bool)($response['ok'] ?? false)) {
            $this->writeFailure('real-order-cancel', ['order_id' => $orderId, 'response' => $response]);
            throw new RuntimeException(sprintf('取消订单失败: %d', $orderId));
        }
    }

    private function startService($client, string $backendBase, string $token, int $orderId): void
    {
        $response = $client->postJson($backendBase . 'index.php?s=/store/order.event/startService&orderId=' . $orderId, [], ['Access-Token' => $token]);
        if (!(bool)($response['ok'] ?? false)) {
            $this->writeFailure('real-order-start-service', ['order_id' => $orderId, 'response' => $response]);
            throw new RuntimeException(sprintf('开始服务失败: %d', $orderId));
        }
    }

    private function completeService($client, string $backendBase, string $token, int $orderId): void
    {
        $response = $client->postJson($backendBase . 'index.php?s=/store/order.event/completeService&orderId=' . $orderId, [], ['Access-Token' => $token]);
        if (!(bool)($response['ok'] ?? false)) {
            $this->writeFailure('real-order-complete-service', ['order_id' => $orderId, 'response' => $response]);
            throw new RuntimeException(sprintf('完成服务失败: %d', $orderId));
        }
    }

    private function refundBeforeService($client, string $backendBase, string $token, int $orderId): void
    {
        $response = $client->postJson($backendBase . 'index.php?s=/store/order.event/refundBeforeService&orderId=' . $orderId, [], ['Access-Token' => $token]);
        if (!(bool)($response['ok'] ?? false)) {
            $this->writeFailure('real-order-refund-before-service', ['order_id' => $orderId, 'response' => $response]);
            throw new RuntimeException(sprintf('服务前退款失败: %d', $orderId));
        }
    }

    private function assertRealH5Scenarios($client, string $baseUrl, string $token, int $unpaidOrderId, int $paidOrderId, int $refundedOrderId, int $completedOrderId, int $auditApprovedOrderId, int $auditRejectedOrderId, int $duplicateRefundOrderId, string $runId): void
    {
        $unpaid = $this->fetchOrderDetail($client, $baseUrl, $unpaidOrderId, $token, true);
        $paid = $this->fetchOrderDetail($client, $baseUrl, $paidOrderId, $token, true);
        $refunded = $this->fetchOrderDetail($client, $baseUrl, $refundedOrderId, $token, true);
        $completed = $this->fetchOrderDetail($client, $baseUrl, $completedOrderId, $token, true);
        $auditApproved = $this->fetchOrderDetail($client, $baseUrl, $auditApprovedOrderId, $token, true);
        $auditRejected = $this->fetchOrderDetail($client, $baseUrl, $auditRejectedOrderId, $token, true);
        $duplicateRefund = $this->fetchOrderDetail($client, $baseUrl, $duplicateRefundOrderId, $token, true);

        $this->assertSame(true, (bool)($unpaid['is_service_order'] ?? false), 'real h5 unpaid service order');
        $this->assertSame('pending_payment', (string)($unpaid['service_state'] ?? ''), 'real h5 unpaid state');
        $this->assertSame(true, (bool)($unpaid['action_flags']['can_cancel'] ?? false), 'real h5 unpaid can cancel');
        $this->assertSame(false, (bool)($unpaid['action_flags']['can_apply_refund'] ?? true), 'real h5 unpaid refund disabled');

        $this->assertSame('pending_contact', (string)($paid['service_state'] ?? ''), 'real h5 paid state');
        $this->assertSame('refunded', (string)($refunded['service_state'] ?? ''), 'real h5 refunded state');
        $this->assertSame('completed', (string)($completed['service_state'] ?? ''), 'real h5 completed state');
        $this->assertSame(true, (bool)($paid['action_flags']['can_apply_refund'] ?? false), 'real h5 paid auto refund enabled');
        $this->assertSame(false, (bool)($completed['action_flags']['can_apply_refund'] ?? true), 'real h5 completed refund disabled');
        $this->assertSame('refunded', (string)($auditApproved['service_state'] ?? ''), 'real h5 audit approved refunded');
        $this->assertSame('in_service', (string)($auditRejected['service_state'] ?? ''), 'real h5 audit rejected remains in service');
        $this->assertSame('退款已拒绝', (string)($auditRejected['refund_state_text'] ?? ''), 'real h5 audit rejected refund text');
        $this->assertSame('refund_pending', (string)($duplicateRefund['service_state'] ?? ''), 'real h5 duplicate refund pending state');
        $this->assertSame(self::DEBUG_MOBILE, (string)($paid['service_contact']['contact_mobile'] ?? ''), 'real h5 service contact mobile');
        $this->assertSame(self::REAL_ORDER_REMARK_PREFIX . $runId . ':PAID', (string)($paid['remark'] ?? ''), 'real h5 run id remark');
    }

    private function assertRealBackendScenarios($client, string $backendBase, string $token, int $unpaidOrderId, int $paidOrderId, int $refundedOrderId, int $completedOrderId, int $auditApprovedOrderId, int $auditRejectedOrderId, int $duplicateRefundOrderId, string $runId): void
    {
        $unpaid = $this->fetchOrderDetail($client, $backendBase, $unpaidOrderId, $token, false);
        $paid = $this->fetchOrderDetail($client, $backendBase, $paidOrderId, $token, false);
        $refunded = $this->fetchOrderDetail($client, $backendBase, $refundedOrderId, $token, false);
        $completed = $this->fetchOrderDetail($client, $backendBase, $completedOrderId, $token, false);
        $auditApproved = $this->fetchOrderDetail($client, $backendBase, $auditApprovedOrderId, $token, false);
        $auditRejected = $this->fetchOrderDetail($client, $backendBase, $auditRejectedOrderId, $token, false);
        $duplicateRefund = $this->fetchOrderDetail($client, $backendBase, $duplicateRefundOrderId, $token, false);

        $this->assertSame(true, OrderModel::isServiceOrderData($unpaid), 'real backend unpaid service classification');
        $this->assertSame(false, (bool)($unpaid['backend_action_flags']['can_start_service'] ?? true), 'real backend unpaid cannot start');
        $this->assertSame(true, (bool)($paid['backend_action_flags']['can_start_service'] ?? false), 'real backend paid can start');
        $this->assertSame(true, (bool)($paid['backend_action_flags']['can_refund_before_service'] ?? false), 'real backend paid can auto refund before service');
        $this->assertSame(false, (bool)($completed['backend_action_flags']['can_start_service'] ?? true), 'real backend completed cannot restart');
        $this->assertSame(false, (bool)($auditApproved['backend_action_flags']['can_start_service'] ?? true), 'real backend approved refund closed');
        $this->assertSame(false, (bool)($duplicateRefund['backend_action_flags']['can_start_service'] ?? true), 'real backend duplicate pending cannot start');
        $this->assertSame((int)OrderStatusEnum::NORMAL, (int)($auditRejected['order_status'] ?? 0), 'real backend rejected refund keeps order normal');
        $this->assertSame(self::REAL_ORDER_REMARK_PREFIX . $runId . ':PAID', (string)($paid['buyer_remark'] ?? ''), 'real backend run id remark');
        $this->assertSame((int)OrderStatusEnum::COMPLETED, (int)($completed['order_status'] ?? 0), 'real backend completed status');
        $this->assertSame((int)OrderStatusEnum::CANCELLED, (int)($refunded['order_status'] ?? 0), 'real backend refunded status');
    }

    private function applyRefund($client, string $baseUrl, string $token, int $orderId): int
    {
        $detail = $this->fetchOrderDetail($client, $baseUrl, $orderId, $token, true);
        $goods = $detail['package_goods'][0]['order_goods_id'] ?? 0;
        if ((int)$goods <= 0) {
            throw new RuntimeException(sprintf('订单缺少可退款商品: %d', $orderId));
        }
        $response = $client->postJson($baseUrl . 'index.php?s=/api/refund/apply&orderGoodsId=' . (int)$goods, [
            'form' => [
                'content' => 'E2E staged refund apply',
            ],
        ], [
            'Access-Token' => $token,
            'platform' => 'H5',
        ]);
        if (!(bool)($response['ok'] ?? false)) {
            $this->writeFailure('real-refund-apply', ['order_id' => $orderId, 'response' => $response]);
            throw new RuntimeException(sprintf('申请退款失败: %d', $orderId));
        }
        $refundId = (int)Db::name('order_refund')->where('order_id', '=', $orderId)->order('order_refund_id', 'desc')->value('order_refund_id');
        if ($refundId <= 0) {
            throw new RuntimeException(sprintf('退款单创建失败: %d', $orderId));
        }
        return $refundId;
    }

    private function applyRefundExpectFailure($client, string $baseUrl, string $token, int $orderId, string $expectedMessage): void
    {
        $detail = $this->fetchOrderDetail($client, $baseUrl, $orderId, $token, true);
        $goods = $detail['package_goods'][0]['order_goods_id'] ?? 0;
        if ((int)$goods <= 0) {
            throw new RuntimeException(sprintf('订单缺少可退款商品: %d', $orderId));
        }
        $response = $client->postJson($baseUrl . 'index.php?s=/api/refund/apply&orderGoodsId=' . (int)$goods, [
            'form' => [
                'content' => 'E2E staged refund expect fail',
            ],
        ], [
            'Access-Token' => $token,
            'platform' => 'H5',
        ]);
        if ((bool)($response['ok'] ?? false)) {
            $this->writeFailure('real-refund-apply-should-fail', ['order_id' => $orderId, 'response' => $response]);
            throw new RuntimeException(sprintf('退款申请本应失败: %d', $orderId));
        }
        $message = (string)($response['data']['message'] ?? $response['data']['msg'] ?? '');
        if ($message !== $expectedMessage) {
            $this->writeFailure('real-refund-apply-fail-message', ['order_id' => $orderId, 'expected' => $expectedMessage, 'response' => $response]);
            throw new RuntimeException(sprintf('退款失败文案不匹配: %d', $orderId));
        }
    }

    private function auditRefund($client, string $backendBase, string $token, int $orderRefundId, int $auditStatus, string $refuseDesc = ''): void
    {
        $response = $client->postJson($backendBase . 'index.php?s=/store/order.refund/audit&orderRefundId=' . $orderRefundId, [
            'form' => [
                'audit_status' => $auditStatus,
                'refuse_desc' => $refuseDesc,
            ],
        ], ['Access-Token' => $token]);
        if (!(bool)($response['ok'] ?? false)) {
            $this->writeFailure('real-refund-audit', ['refund_id' => $orderRefundId, 'response' => $response]);
            throw new RuntimeException(sprintf('退款审核失败: %d', $orderRefundId));
        }
    }

    private function assertRealMiniProgramScenarios($client, array $config, string $token, int $paidOrderId, string $runId): void
    {
        $this->assertPathExists($config['wechat_cli'], 'wechat cli');
        $this->assertPathExists($config['hbuilderx_cli'], 'hbuilderx cli');
        $detail = $this->fetchOrderDetail($client, $config['h5_url'], $paidOrderId, $token, true, 'MP-WEIXIN');
        $this->assertSame(true, (bool)($detail['is_service_order'] ?? false), 'real mp service order flag');
        $this->assertSame('pending_contact', (string)($detail['service_state'] ?? ''), 'real mp paid state');
        $this->assertSame(true, (bool)($detail['action_flags']['can_apply_refund'] ?? false), 'real mp paid refund enabled');
        $this->assertSame(self::REAL_ORDER_REMARK_PREFIX . $runId . ':PAID', (string)($detail['remark'] ?? ''), 'real mp run id remark');
    }

    /** @return array{orders:int,goods:int,refunds:int} */
    private function cleanupGeneratedData(): array
    {
        $debugUserId = $this->getOrCreateDebugUserId();
        $rows = Db::name('order')
            ->where('user_id', '=', $debugUserId)
            ->where('store_id', '=', self::STORE_ID)
            ->where('is_delete', '=', 0)
            ->field('order_id,order_no,order_source_data,buyer_remark')
            ->select()
            ->toArray();
        $orderIds = [];
        foreach ($rows as $row) {
            $sourceData = $this->decodeJson((string)($row['order_source_data'] ?? ''));
            $remark = (string)($row['buyer_remark'] ?? '');
            $isLegacyFixture = !empty($sourceData['service_order_e2e']);
            $isRealRun = str_starts_with($remark, self::REAL_ORDER_REMARK_PREFIX);
            $isLegacyOrderNo = str_starts_with((string)($row['order_no'] ?? ''), 'E2E');
            if ($isLegacyFixture || $isRealRun || $isLegacyOrderNo) {
                $orderIds[] = (int)$row['order_id'];
            }
        }
        if (empty($orderIds)) {
            return ['orders' => 0, 'goods' => 0, 'refunds' => 0];
        }
        $goods = Db::name('order_goods')->where('order_id', 'in', $orderIds)->count();
        $refunds = Db::name('order_refund')->where('order_id', 'in', $orderIds)->count();
        Db::transaction(function () use ($orderIds) {
            Db::name('order_refund')->where('order_id', 'in', $orderIds)->delete();
            Db::name('order_goods')->where('order_id', 'in', $orderIds)->delete();
            Db::name('order')->where('order_id', 'in', $orderIds)->delete();
        });
        return ['orders' => count($orderIds), 'goods' => (int)$goods, 'refunds' => (int)$refunds];
    }

    /** @return array<string, mixed> */
    private function decodeJson(string $json): array
    {
        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }

    private function ensureEvidenceDir(): void
    {
        if (!is_dir($this->evidenceDir)) {
            mkdir($this->evidenceDir, 0777, true);
        }
    }

    /** @param array<string, mixed> $payload */
    private function writeJson(string $fileName, array $payload): void
    {
        $this->ensureEvidenceDir();
        file_put_contents($this->evidenceDir . '/' . $fileName, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    /** @param array<string, mixed> $payload */
    private function writeFailure(string $name, array $payload): void
    {
        $this->writeJson($name . '-' . date('YmdHis') . '.json', $payload);
        file_put_contents($this->evidenceDir . '/' . $name . '-' . date('YmdHis') . '.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO7+2WcAAAAASUVORK5CYII='));
    }

    /** @param array<string, mixed> $fixtures */
    private function checkMiniProgram($client, array $fixtures, string $token, array $config): void
    {
        $this->assertPathExists($config['wechat_cli'], 'wechat cli');
        $this->assertPathExists($config['hbuilderx_cli'], 'hbuilderx cli');
        $detail = $this->fetchOrderDetail($client, $config['h5_url'], $fixtures['service-order']['order_id'], $token, true, 'MP-WEIXIN');
        $this->assertSame(true, (bool)($detail['is_service_order'] ?? false), 'mp service order flag');
        $this->assertSame(true, (bool)($detail['action_flags']['can_apply_refund'] ?? false), 'mp service refund flag');
        $this->writeJson('mp-weixin-meta-' . date('YmdHis') . '.json', [
            'wechat_cli' => $config['wechat_cli'],
            'hbuilderx_cli' => $config['hbuilderx_cli'],
            'windows_uniapp_dir' => $config['windows_uniapp_dir'],
            'fixture_order_id' => $fixtures['service-order']['order_id'],
        ]);
    }

    /** @param array<string, mixed> $fixtures */
    private function checkH5($client, array $fixtures, string $token, string $baseUrl): void
    {
        $service = $this->fetchOrderDetail($client, $baseUrl, $fixtures['service-order']['order_id'], $token, true);
        $physical = $this->fetchOrderDetail($client, $baseUrl, $fixtures['physical-order']['order_id'], $token, true);
        $mixed = $this->fetchOrderDetail($client, $baseUrl, $fixtures['mixed-order']['order_id'], $token, true);
        $refunding = $this->fetchOrderDetail($client, $baseUrl, $fixtures['refunding']['order_id'], $token, true);
        $completed = $this->fetchOrderDetail($client, $baseUrl, $fixtures['completed-service']['order_id'], $token, true);

        $this->assertSame(true, (bool)($service['is_service_order'] ?? false), 'h5 service order flag');
        $this->assertSame(false, (bool)($physical['is_service_order'] ?? true), 'h5 physical order flag');
        $this->assertSame(true, (bool)($mixed['is_service_order'] ?? false), 'h5 mixed legacy service flag');
        $this->assertSame('待联系', (string)($service['service_state_text'] ?? ''), 'h5 service state text');
        $this->assertSame('待发货', (string)($physical['service_state_text'] ?? ''), 'h5 physical state text');
        $this->assertSame(true, (bool)($service['can_refund'] ?? false), 'h5 service refund eligibility');
        $this->assertSame(true, (bool)($physical['can_refund'] ?? false), 'h5 physical refund eligibility');
        $this->assertSame('refund_pending', (string)($refunding['service_state'] ?? ''), 'h5 refunding state');
        $this->assertSame('completed', (string)($completed['service_state'] ?? ''), 'h5 completed state');
    }

    /** @param array<string, mixed> $fixtures */
    private function checkBackend($client, array $fixtures, string $token, string $baseUrl): void
    {
        $backendBase = $this->getBackendApiBase($baseUrl);
        $service = $this->fetchOrderDetail($client, $backendBase, $fixtures['service-order']['order_id'], $token, false);
        $physical = $this->fetchOrderDetail($client, $backendBase, $fixtures['physical-order']['order_id'], $token, false);
        $mixed = $this->fetchOrderDetail($client, $backendBase, $fixtures['mixed-order']['order_id'], $token, false);
        $this->assertSame(true, OrderModel::isServiceOrderData($service), 'backend service classification');
        $this->assertSame(false, OrderModel::isServiceOrderData($physical), 'backend physical classification');
        $this->assertSame(true, OrderModel::isServiceOrderData($mixed), 'backend mixed classification');
        $this->assertSame(true, (bool)($service['backend_action_flags']['can_start_service'] ?? false), 'backend service start flag');
        $this->assertSame(false, (bool)($physical['backend_action_flags']['can_start_service'] ?? true), 'backend physical start flag');
        $this->assertSame(true, (bool)($service['backend_action_flags']['can_refund_before_service'] ?? false), 'backend service refund flag');
        $this->assertSame(false, (bool)($physical['backend_action_flags']['can_refund_before_service'] ?? true), 'backend physical refund flag');

        $startMismatch = $client->postJson($backendBase . 'index.php?s=/store/order.event/startService&orderId=' . $fixtures['physical-order']['order_id'], [], ['Access-Token' => $token]);
        $refundMismatch = $client->postJson($backendBase . 'index.php?s=/store/order.event/refundBeforeService&orderId=' . $fixtures['completed-service']['order_id'], [], ['Access-Token' => $token]);
        if (($startMismatch['data']['status'] ?? null) === 200 || ($refundMismatch['data']['status'] ?? null) === 200) {
            $this->writeFailure('backend-mismatch', ['start' => $startMismatch, 'refund' => $refundMismatch]);
            throw new RuntimeException('backend mismatch handling failed');
        }
    }

    /** @return array<string, mixed> */
    private function fetchOrderDetail($client, string $baseUrl, int $orderId, string $token, bool $isApi, string $platform = 'H5'): array
    {
        $path = $isApi ? '/api/order/detail' : '/store/order/detail';
        $headers = $isApi ? ['Access-Token' => $token, 'platform' => $platform] : ['Access-Token' => $token];
        $response = $client->getJson($baseUrl . 'index.php?s=' . $path, ['orderId' => $orderId], $headers);
        if (empty($response['data']['data']['order']) && empty($response['data']['data']['detail'])) {
            $this->writeFailure('order-detail', $response);
            throw new RuntimeException(sprintf('订单详情请求失败: %s', $path));
        }
        return $response['data']['data']['order'] ?? $response['data']['data']['detail'];
    }

    private function assertPathExists(string $path, string $label): void
    {
        if ($path === '' || !file_exists($path)) {
            $this->writeFailure($label . '-missing', ['path' => $path]);
            throw new RuntimeException(sprintf('%s 路径不存在: %s', $label, $path));
        }
    }

    /** @param mixed $actual */
    private function assertSame($expected, $actual, string $label): void
    {
        if ($expected !== $actual) {
            $this->writeFailure($label, ['expected' => $expected, 'actual' => $actual]);
            throw new RuntimeException(sprintf('%s mismatch: expected %s, got %s', $label, var_export($expected, true), var_export($actual, true)));
        }
    }

    private function getOrCreateDebugUserId(): int
    {
        $user = UserModel::detail(['mobile' => self::DEBUG_MOBILE]);
        if (!empty($user)) {
            return (int)$user['user_id'];
        }
        $service = new \app\api\service\passport\Login();
        if (!$service->loginDebug()) {
            throw new RuntimeException($service->getError() ?: '创建调试用户失败');
        }
        $user = UserModel::detail(['mobile' => self::DEBUG_MOBILE]);
        if (empty($user)) {
            throw new RuntimeException('调试用户创建后仍不存在');
        }
        return (int)$user['user_id'];
    }

    private function getBackendApiBase(string $backendUrl): string
    {
        $parts = parse_url($backendUrl);
        if ($parts === false || !is_array($parts)) {
            throw new RuntimeException('backend_url 不合法');
        }
        $scheme = (string)($parts['scheme'] ?? '');
        $host = (string)($parts['host'] ?? '');
        $port = (int)($parts['port'] ?? 0);
        if ($scheme === '' || $host === '') {
            throw new RuntimeException('backend_url 不合法');
        }
        $base = $scheme . '://' . $host;
        if ($port > 0) {
            $base .= ':' . $port;
        }
        return rtrim($base, '/') . '/';
    }
}
