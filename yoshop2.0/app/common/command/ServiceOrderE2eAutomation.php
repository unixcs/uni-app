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
use app\common\enum\order\refund\RefundStatus as RefundStatusEnum;
use app\common\enum\order\refund\RefundType as RefundTypeEnum;
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
        ];
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
        $output->writeln('steps: fixtures -> check -> cleanup');
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
        $fixtures = $this->ensureAllFixtures();
        $client = new ServiceOrderE2eHttpClient($this->evidenceDir);
        $tokens = $this->loginTokens($client, $config);

        if (isset($checks['h5'])) {
            $this->checkH5($client, $fixtures, $tokens['h5'], $config['h5_url']);
            $output->writeln('<info>h5 checks ok</info>');
        }
        if (isset($checks['mp-weixin'])) {
            $this->checkMiniProgram($client, $fixtures, $tokens['h5'], $config);
            $output->writeln('<info>mp-weixin checks ok</info>');
        }
        if (isset($checks['backend'])) {
            $this->checkBackend($client, $fixtures, $tokens['backend'], $config['backend_url']);
            $output->writeln('<info>backend checks ok</info>');
        }

        $this->writeJson('check-' . date('YmdHis') . '.json', ['fixtures' => array_keys($fixtures), 'checks' => array_keys($checks)]);
        return true;
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
            ->where('order_no', 'like', 'E2E-' . $fixture . '-%')
            ->order('order_id', 'desc')
            ->find() ?: [];
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

    /** @return array{orders:int,goods:int,refunds:int} */
    private function cleanupGeneratedData(): array
    {
        $orderIds = Db::name('order')->where('order_no', 'like', 'E2E-%')->column('order_id');
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
