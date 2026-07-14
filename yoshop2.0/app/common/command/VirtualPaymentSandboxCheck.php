<?php
// +----------------------------------------------------------------------
// | Virtual payment sandbox readiness check
// +----------------------------------------------------------------------
declare (strict_types=1);

namespace app\common\command;

use app\api\model\wxapp\Setting as WxappSettingModel;
use app\common\enum\Client as ClientEnum;
use app\common\enum\order\DeliveryStatus as DeliveryStatusEnum;
use app\common\enum\order\OrderStatus as OrderStatusEnum;
use app\common\enum\order\PayStatus as PayStatusEnum;
use app\common\enum\order\ReceiptStatus as ReceiptStatusEnum;
use app\common\enum\order\refund\RefundStatus as RefundStatusEnum;
use app\common\enum\Setting as SettingEnum;
use app\common\enum\payment\trade\TradeStatus as TradeStatusEnum;
use app\common\library\wechat\VirtualPayment as WechatVirtualPayment;
use app\common\model\Goods as GoodsModel;
use app\common\model\Order as OrderModel;
use app\common\model\PaymentTrade as PaymentTradeModel;
use app\common\model\store\Setting as StoreSettingModel;
use app\common\model\User as UserModel;
use app\common\model\UserOauth as UserOauthModel;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\facade\Db;

class VirtualPaymentSandboxCheck extends Command
{
    private const DEFAULT_STORE_ID = 10001;
    private const DEFAULT_USER_MOBILE = '19900000000';
    private const DEFAULT_EVIDENCE_DIR = '/opt/yoshop/runtime/virtual-payment-sandbox-check';

    protected function configure()
    {
        $this->setName('virtual-payment:sandbox-check')
            ->addOption('store-id', null, Option::VALUE_OPTIONAL, 'Store ID', (string)self::DEFAULT_STORE_ID)
            ->addOption('goods-id', null, Option::VALUE_OPTIONAL, 'Virtual-payment service goods ID', '')
            ->addOption('user-mobile', null, Option::VALUE_OPTIONAL, 'Sandbox test user mobile', self::DEFAULT_USER_MOBILE)
            ->addOption('out-trade-no', null, Option::VALUE_OPTIONAL, 'Audit a real virtual-payment outTradeNo', '')
            ->addOption('expect-paid', null, Option::VALUE_NONE, 'Require the audited trade and order to be paid')
            ->addOption('expect-pending-contact', null, Option::VALUE_NONE, 'Require the audited paid service order to converge to pending-contact')
            ->addOption('expect-duplicate-notify', null, Option::VALUE_NONE, 'Require duplicate notify evidence on the audited trade')
            ->addOption('expect-query-evidence', null, Option::VALUE_NONE, 'Require active query evidence on the audited trade')
            ->addOption('expect-no-duplicate-refund', null, Option::VALUE_NONE, 'Require no duplicate refund records for the audited order')
            ->addOption('expect-ios-refund-query', null, Option::VALUE_NONE, 'Require authenticated Apple refund inquiry evidence on the audited trade')
            ->addOption('expect-refunded', null, Option::VALUE_NONE, 'Require refund notify and four-layer local refund convergence')
            ->addOption('expect-provide-goods-idempotent', null, Option::VALUE_NONE, 'Require provide-goods dispatch evidence to be terminal or retry-safe')
            ->addOption('probe-remote-query', null, Option::VALUE_NONE, 'Call WeChat xpay/query_order for the audited trade and store a sanitized response in evidence')
            ->addOption('probe-notify-endpoint', null, Option::VALUE_NONE, 'Send a signed URL-verification GET to the configured virtual-payment callback')
            ->addOption('evidence-dir', null, Option::VALUE_OPTIONAL, 'Evidence output directory', self::DEFAULT_EVIDENCE_DIR)
            ->addOption('require-safe-mode', null, Option::VALUE_NONE, 'Require message_push_encoding_aes_key')
            ->setDescription('Check WeChat virtual-payment sandbox readiness without mutating business data');
    }

    protected function execute(Input $input, Output $output)
    {
        $storeId = (int)$input->getOption('store-id');
        $goodsId = (int)$input->getOption('goods-id');
        $userMobile = trim((string)$input->getOption('user-mobile'));
        $outTradeNo = trim((string)$input->getOption('out-trade-no'));
        $requireSafeMode = (bool)$input->getOption('require-safe-mode');
        $expectations = [
            'paid' => (bool)$input->getOption('expect-paid'),
            'pending_contact' => (bool)$input->getOption('expect-pending-contact'),
            'duplicate_notify' => (bool)$input->getOption('expect-duplicate-notify'),
            'query_evidence' => (bool)$input->getOption('expect-query-evidence'),
            'no_duplicate_refund' => (bool)$input->getOption('expect-no-duplicate-refund'),
            'ios_refund_query' => (bool)$input->getOption('expect-ios-refund-query'),
            'refunded' => (bool)$input->getOption('expect-refunded'),
            'provide_goods_idempotent' => (bool)$input->getOption('expect-provide-goods-idempotent'),
            'probe_remote_query' => (bool)$input->getOption('probe-remote-query'),
            'probe_notify_endpoint' => (bool)$input->getOption('probe-notify-endpoint'),
        ];
        $evidenceDir = rtrim((string)$input->getOption('evidence-dir'), '/');

        $report = [
            'run_at' => date('c'),
            'store_id' => $storeId,
            'goods_id' => $goodsId,
            'user_mobile' => $userMobile,
            'out_trade_no' => $outTradeNo,
            'require_safe_mode' => $requireSafeMode,
            'expectations' => $expectations,
            'checks' => [],
            'summary' => [
                'ready' => true,
                'failures' => 0,
                'warnings' => 0,
            ],
        ];

        $this->checkRuntimeConfig($report, $storeId, $requireSafeMode, $outTradeNo !== '');
        $this->checkWxappConfig($report, $storeId);
        $selectedGoodsId = $this->checkGoodsMapping($report, $storeId, $goodsId);
        $this->checkTestUser($report, $storeId, $userMobile);
        $this->checkNotifyEndpoint($report);
        if (!empty($expectations['probe_notify_endpoint'])) {
            $this->probeNotifyEndpoint($report, $storeId);
        }
        $this->checkRecentVirtualTrades($report, $storeId, $selectedGoodsId);
        if ($outTradeNo !== '') {
            $this->checkTradeEvidence($report, $storeId, $selectedGoodsId, $outTradeNo, $expectations);
        }

        $report['summary']['ready'] = (int)$report['summary']['failures'] === 0;
        $file = $this->writeEvidence($evidenceDir, $report);

        foreach ($report['checks'] as $check) {
            $tag = $check['ok'] ? '<info>ok</info>' : ($check['severity'] === 'warning' ? '<comment>warn</comment>' : '<error>fail</error>');
            $output->writeln(sprintf('%s %s: %s', $tag, $check['name'], $check['message']));
        }
        $output->writeln(sprintf('evidence: %s', $file));
        $output->writeln($report['summary']['ready'] ? '<info>sandbox readiness ok</info>' : '<error>sandbox readiness failed</error>');

        return $report['summary']['ready'] ? 0 : 1;
    }

    private function checkRuntimeConfig(array &$report, int $storeId, bool $requireSafeMode, bool $auditRealTrade): void
    {
        $config = StoreSettingModel::getItem(SettingEnum::VIRTUAL_PAYMENT, $storeId);
        $masked = $config;
        foreach (['sandbox_app_key', 'production_app_key', 'message_push_token', 'message_push_encoding_aes_key'] as $field) {
            if (!empty($masked[$field])) {
                $masked[$field] = '***' . strlen((string)$masked[$field]);
            }
        }
        $report['virtual_payment_config'] = $masked;

        $configuredEnv = (int)($config['env'] ?? -1);
        $expectedAppKey = $configuredEnv === GoodsModel::VP_ENV_SANDBOX ? 'sandbox_app_key' : 'production_app_key';
        $this->addCheck($report, 'virtual_payment.enabled', (int)($config['enabled'] ?? 0) === 1, 'failure', 'virtual payment must be enabled');
        $this->addCheck(
            $report,
            'virtual_payment.env',
            $auditRealTrade
                ? \in_array($configuredEnv, [GoodsModel::VP_ENV_PRODUCTION, GoodsModel::VP_ENV_SANDBOX], true)
                : $configuredEnv === GoodsModel::VP_ENV_SANDBOX,
            'failure',
            $auditRealTrade ? 'real-trade audit accepts configured env=0 or env=1' : 'sandbox acceptance must use env=1'
        );
        $this->addRequiredStringCheck($report, $config, 'offer_id', 'virtual_payment.offer_id');
        $this->addRequiredStringCheck($report, $config, $expectedAppKey, 'virtual_payment.' . $expectedAppKey);
        $this->addRequiredStringCheck($report, $config, 'merchant_id', 'virtual_payment.merchant_id');
        $this->addRequiredStringCheck($report, $config, 'notify_base_url', 'virtual_payment.notify_base_url');
        $this->addRequiredStringCheck($report, $config, 'message_push_token', 'virtual_payment.message_push_token');
        $this->addCheck(
            $report,
            'virtual_payment.message_push_encoding_aes_key',
            !$requireSafeMode || trim((string)($config['message_push_encoding_aes_key'] ?? '')) !== '',
            $requireSafeMode ? 'failure' : 'warning',
            $requireSafeMode ? 'safe mode requires message_push_encoding_aes_key' : 'message_push_encoding_aes_key is only required for safe mode'
        );
    }

    private function checkWxappConfig(array &$report, int $storeId): void
    {
        $wxapp = WxappSettingModel::getConfigBasic($storeId);
        $report['wxapp_config'] = [
            'app_id' => (string)($wxapp['app_id'] ?? ''),
            'app_secret' => empty($wxapp['app_secret'] ?? '') ? '' : '***' . strlen((string)$wxapp['app_secret']),
        ];
        $this->addRequiredStringCheck($report, $wxapp, 'app_id', 'wxapp.app_id');
        $this->addCheck(
            $report,
            'wxapp.app_secret',
            trim((string)($wxapp['app_secret'] ?? '')) !== '',
            'warning',
            'wxapp.app_secret is only required for server-side access_token APIs such as xpay query/refund/provide_goods; requestVirtualPayment launch signing itself does not use it'
        );
    }

    private function checkGoodsMapping(array &$report, int $storeId, int $goodsId): int
    {
        $goods = $goodsId > 0
            ? Db::name('goods')->where('store_id', '=', $storeId)->where('goods_id', '=', $goodsId)->find()
            : Db::name('goods')->alias('g')
                ->join('goods_service_rel r', 'r.goods_id = g.goods_id')
                ->where('g.store_id', '=', $storeId)
                ->where('g.is_delete', '=', 0)
                ->where('g.status', '=', 10)
                ->where('g.vp_enabled', '=', 1)
                ->field('g.*')
                ->group('g.goods_id')
                ->order('g.goods_id', 'asc')
                ->find();

        if (empty($goods)) {
            $this->addCheck($report, 'goods.mapping', false, 'failure', 'no virtual-payment service goods found');
            return 0;
        }

        $goodsId = (int)$goods['goods_id'];
        $serviceRelCount = Db::name('goods_service_rel')->where('goods_id', '=', $goodsId)->count();
        $sku = Db::name('goods_sku')->where('goods_id', '=', $goodsId)->order('goods_sku_id', 'asc')->find();
        $priceFen = GoodsModel::getGoodsPriceFen($goods);

        $report['goods'] = [
            'goods_id' => $goodsId,
            'goods_name' => (string)$goods['goods_name'],
            'goods_price_min' => (string)$goods['goods_price_min'],
            'goods_price_max' => (string)$goods['goods_price_max'],
            'status' => (int)$goods['status'],
            'is_delete' => (int)$goods['is_delete'],
            'vp_enabled' => (int)$goods['vp_enabled'],
            'vp_product_id' => (string)$goods['vp_product_id'],
            'vp_price_snapshot' => (int)$goods['vp_price_snapshot'],
            'service_rel_count' => (int)$serviceRelCount,
            'first_sku_id' => (string)($sku['goods_sku_id'] ?? ''),
            'first_sku_stock' => (int)($sku['stock_num'] ?? 0),
        ];

        $this->addCheck($report, 'goods.status', (int)$goods['status'] === 10 && (int)$goods['is_delete'] === 0, 'failure', 'goods must be on sale and not deleted');
        $this->addCheck($report, 'goods.service_rel', (int)$serviceRelCount > 0, 'failure', 'goods must be a service package');
        $this->addCheck($report, 'goods.vp_enabled', (int)$goods['vp_enabled'] === 1, 'failure', 'goods must enable virtual payment');
        $this->addCheck($report, 'goods.vp_product_id', trim((string)$goods['vp_product_id']) !== '', 'failure', 'goods must bind vp_product_id');
        $this->addCheck($report, 'goods.vp_price_snapshot', (int)$goods['vp_price_snapshot'] > 0, 'failure', 'goods must set vp_price_snapshot');
        $this->addCheck($report, 'goods.fixed_price', (string)$goods['goods_price_min'] === (string)$goods['goods_price_max'], 'failure', 'virtual-payment goods must be fixed price');
        $this->addCheck($report, 'goods.price_snapshot_match', $priceFen === (int)$goods['vp_price_snapshot'], 'failure', 'goods price must match vp_price_snapshot');
        $this->addCheck($report, 'goods.sku_stock', !empty($sku) && (int)$sku['stock_num'] > 0, 'failure', 'goods must have stock for sandbox order creation');

        return $goodsId;
    }

    private function checkTestUser(array &$report, int $storeId, string $mobile): void
    {
        $user = UserModel::detail(['store_id' => $storeId, 'mobile' => $mobile]);
        if (empty($user)) {
            $this->addCheck($report, 'test_user.exists', false, 'failure', 'sandbox test user does not exist');
            return;
        }
        $oauth = UserOauthModel::getOauth((int)$user['user_id'], ClientEnum::MP_WEIXIN);
        $report['test_user'] = [
            'user_id' => (int)$user['user_id'],
            'mobile' => (string)$user['mobile'],
            'oauth_id_present' => !empty($oauth['oauth_id'] ?? ''),
            'session_key_present' => !empty($oauth['session_key'] ?? ''),
        ];
        $this->addCheck($report, 'test_user.oauth', !empty($oauth) && !empty($oauth['oauth_id']), 'failure', 'test user must have MP-WEIXIN openid');
        $this->addCheck($report, 'test_user.session_key', !empty($oauth['session_key'] ?? ''), 'failure', 'test user must have MP-WEIXIN session_key');
        if (!empty($oauth['oauth_id'] ?? '')) {
            $duplicateBindings = Db::name('user_oauth')
                ->where('oauth_type', '=', ClientEnum::MP_WEIXIN)
                ->where('oauth_id', '=', (string)$oauth['oauth_id'])
                ->where('is_delete', '=', 0)
                ->field(['user_id', 'update_time'])
                ->order('user_id', 'asc')
                ->select()
                ->toArray();
            $report['test_user']['oauth_duplicate_bindings'] = array_map(static function (array $item) {
                return [
                    'user_id' => (int)$item['user_id'],
                    'update_time' => (int)$item['update_time'],
                ];
            }, $duplicateBindings);
            $this->addCheck(
                $report,
                'test_user.oauth_unique',
                count($duplicateBindings) === 1,
                'failure',
                count($duplicateBindings) === 1
                    ? 'test user openid maps to a single mall user'
                    : 'test user openid is bound to multiple mall users: ' . implode(',', array_column($duplicateBindings, 'user_id'))
            );
        }
    }

    private function checkNotifyEndpoint(array &$report): void
    {
        $noticeFile = public_path() . 'notice/virtualPayment.php';
        $legacyNoticeFile = public_path() . 'notice/wechatVirtual.php';
        $routeFile = root_path() . 'route/app.php';
        $routeText = is_file($routeFile) ? (string)file_get_contents($routeFile) : '';
        $autoloadExpression = "dirname(__DIR__, 2) . '/vendor/autoload.php'";
        $noticeText = is_file($noticeFile) ? (string)file_get_contents($noticeFile) : '';
        $legacyNoticeText = is_file($legacyNoticeFile) ? (string)file_get_contents($legacyNoticeFile) : '';
        $this->addCheck($report, 'notify.entry_file', is_file($noticeFile), 'failure', 'public notice entry must exist');
        $this->addCheck($report, 'notify.entry_autoload', str_contains($noticeText, $autoloadExpression), 'failure', 'public notice entry must load project-root vendor/autoload.php');
        $this->addCheck($report, 'notify.legacy_entry_autoload', str_contains($legacyNoticeText, $autoloadExpression), 'failure', 'legacy public notice entry must load project-root vendor/autoload.php');
        $this->addCheck($report, 'notify.route', str_contains($routeText, 'notify/virtualPayment'), 'failure', 'notify route must be registered');
    }

    /**
     * 使用消息推送 token 对公网回调入口执行一次签名 GET 验证，不发送业务通知。
     */
    private function probeNotifyEndpoint(array &$report, int $storeId): void
    {
        $config = StoreSettingModel::getItem(SettingEnum::VIRTUAL_PAYMENT, $storeId);
        $baseUrl = rtrim(trim((string)($config['notify_base_url'] ?? '')), '/');
        $token = trim((string)($config['message_push_token'] ?? ''));
        $endpoint = $baseUrl . '/notice/virtualPayment.php';
        $report['notify_endpoint_probe'] = [
            'endpoint' => $endpoint,
            'probed_at' => date('c'),
        ];
        if ($baseUrl === '' || $token === '') {
            $this->addCheck($report, 'notify.endpoint_probe', false, 'failure', 'notify_base_url and message_push_token are required for endpoint probe');
            return;
        }

        $timestamp = (string)time();
        $nonce = bin2hex(random_bytes(8));
        $echo = 'virtual-payment-probe-' . bin2hex(random_bytes(8));
        $parts = [$token, $timestamp, $nonce];
        sort($parts, SORT_STRING);
        $query = http_build_query([
            'signature' => sha1(implode($parts)),
            'timestamp' => $timestamp,
            'nonce' => $nonce,
            'echostr' => $echo,
        ]);
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 10,
                'ignore_errors' => true,
                'header' => "User-Agent: YoShop-VirtualPayment-Readiness/1.0\r\n",
            ],
        ]);
        $startedAt = microtime(true);
        $body = @file_get_contents($endpoint . '?' . $query, false, $context);
        $elapsedMs = round((microtime(true) - $startedAt) * 1000, 2);
        $headers = $http_response_header ?? [];
        $httpStatus = 0;
        if (isset($headers[0]) && preg_match('/\s(\d{3})\s/', $headers[0], $matches)) {
            $httpStatus = (int)$matches[1];
        }
        $responseMatchesEcho = is_string($body) && hash_equals($echo, trim($body));
        $report['notify_endpoint_probe']['http_status'] = $httpStatus;
        $report['notify_endpoint_probe']['elapsed_ms'] = $elapsedMs;
        $report['notify_endpoint_probe']['response_matches_echo'] = $responseMatchesEcho;
        $this->addCheck(
            $report,
            'notify.endpoint_probe',
            $httpStatus === 200 && $responseMatchesEcho,
            'failure',
            'configured callback must return HTTP 200 with the signed URL-verification echo'
        );
    }

    private function checkRecentVirtualTrades(array &$report, int $storeId, int $goodsId): void
    {
        $query = (new PaymentTradeModel)->where('store_id', '=', $storeId)->where('platform', '=', 'wechat_virtual');
        if ($goodsId > 0) {
            $goods = Db::name('goods')->where('goods_id', '=', $goodsId)->find();
            if (!empty($goods['vp_product_id'])) {
                $query->where('product_id', '=', (string)$goods['vp_product_id']);
            }
        }
        $recent = $query->order('trade_id', 'desc')->limit(3)->select()->toArray();
        $report['recent_virtual_trades'] = array_map(static function ($trade) {
            return [
                'trade_id' => (int)$trade['trade_id'],
                'order_id' => (int)$trade['order_id'],
                'order_no' => (string)$trade['order_no'],
                'out_trade_no' => (string)$trade['out_trade_no'],
                'trade_state' => (int)$trade['trade_state'],
                'notify_times' => (int)$trade['notify_times'],
                'last_notify_time' => (int)$trade['last_notify_time'],
                'product_id' => (string)$trade['product_id'],
            ];
        }, $recent);
        $this->addCheck($report, 'recent_virtual_trades', count($recent) > 0, 'warning', sprintf('recent matching wechat_virtual trades: %d', count($recent)));
    }

    private function checkTradeEvidence(array &$report, int $storeId, int $goodsId, string $outTradeNo, array $expectations): void
    {
        try {
            $trade = PaymentTradeModel::detailByOutTradeNo($outTradeNo);
        } catch (\Throwable $e) {
            $this->addCheck($report, 'trade.exists', false, 'failure', $e->getMessage());
            return;
        }

        $tradeData = $trade->toArray();
        $snapshot = PaymentTradeModel::decodePayloadSnapshot((string)($tradeData['payload_snapshot'] ?? ''));
        $order = Db::name('order')->where('store_id', '=', $storeId)->where('order_id', '=', (int)$tradeData['order_id'])->find();
        $refundQuery = Db::name('order_refund')
            ->where('store_id', '=', $storeId)
            ->where('order_id', '=', (int)$tradeData['order_id']);
        $refundCount = (clone $refundQuery)->count();
        $refund = $refundQuery->order(['order_refund_id' => 'desc'])
            ->find();

        $report['audited_trade'] = [
            'trade_id' => (int)$tradeData['trade_id'],
            'order_id' => (int)$tradeData['order_id'],
            'order_no' => (string)$tradeData['order_no'],
            'out_trade_no' => (string)$tradeData['out_trade_no'],
            'platform' => (string)$tradeData['platform'],
            'env' => (int)$tradeData['env'],
            'product_id' => (string)$tradeData['product_id'],
            'goods_price' => (int)$tradeData['goods_price'],
            'trade_state' => (int)$tradeData['trade_state'],
            'notify_times' => (int)$tradeData['notify_times'],
            'last_notify_time' => (int)$tradeData['last_notify_time'],
            'snapshot_keys' => array_keys($snapshot),
            'has_pay_notify' => isset($snapshot['pay_notify']),
            'has_query_order' => isset($snapshot['query_order']),
            'provide_goods_status' => (string)($snapshot['provide_goods']['status'] ?? ''),
            'provide_goods_started_at' => (int)($snapshot['provide_goods']['dispatch_started_at'] ?? 0),
            'virtual_refund_status' => (string)($snapshot['virtual_refund']['status'] ?? ''),
        ];
        $isServiceOrder = !empty($order) && OrderModel::isServiceOrderData($order);
        $report['audited_order'] = empty($order) ? null : [
            'order_id' => (int)$order['order_id'],
            'order_no' => (string)$order['order_no'],
            'is_service_order' => $isServiceOrder,
            'pay_status' => (int)$order['pay_status'],
            'order_status' => (int)$order['order_status'],
            'delivery_status' => (int)$order['delivery_status'],
            'receipt_status' => (int)$order['receipt_status'],
            'trade_id' => (int)$order['trade_id'],
        ];
        $report['audited_refund_count'] = (int)$refundCount;
        $report['audited_refund'] = empty($refund) ? null : [
            'order_refund_id' => (int)$refund['order_refund_id'],
            'audit_status' => (int)$refund['audit_status'],
            'status' => (int)$refund['status'],
            'refund_money' => (string)$refund['refund_money'],
        ];

        $this->addCheck($report, 'trade.store', (int)$tradeData['store_id'] === $storeId, 'failure', 'trade must belong to selected store');
        $this->addCheck($report, 'trade.platform', (string)$tradeData['platform'] === 'wechat_virtual', 'failure', 'trade must be wechat_virtual');
        $this->addCheck(
            $report,
            'trade.env',
            (int)$tradeData['env'] === (int)($report['virtual_payment_config']['env'] ?? -1),
            'failure',
            'audited trade env must match the currently configured virtual-payment env'
        );
        if ($goodsId > 0) {
            $goods = Db::name('goods')->where('goods_id', '=', $goodsId)->find();
            if (!empty($goods['vp_product_id'])) {
                $this->addCheck($report, 'trade.product_id', (string)$tradeData['product_id'] === (string)$goods['vp_product_id'], 'failure', 'trade product_id must match selected goods');
            }
        }
        if (!empty($expectations['paid'])) {
            $this->addCheck($report, 'trade.paid', (int)$tradeData['trade_state'] === TradeStatusEnum::SUCCESS, 'failure', 'trade_state must be SUCCESS');
            $this->addCheck($report, 'order.paid', !empty($order) && (int)$order['pay_status'] === PayStatusEnum::SUCCESS, 'failure', 'order pay_status must be SUCCESS');
            $this->addCheck($report, 'order.trade_link', !empty($order) && (int)$order['trade_id'] === (int)$tradeData['trade_id'], 'failure', 'order must link to audited trade');
        }
        if (!empty($expectations['pending_contact'])) {
            $this->addCheck($report, 'order.service_order', $isServiceOrder, 'failure', 'audited order must be a service order');
            $this->addCheck($report, 'order.pending_contact.pay_status', !empty($order) && (int)$order['pay_status'] === PayStatusEnum::SUCCESS, 'failure', 'pending-contact requires paid order');
            $this->addCheck($report, 'order.pending_contact.order_status', !empty($order) && (int)$order['order_status'] === OrderStatusEnum::NORMAL, 'failure', 'pending-contact order_status must be NORMAL');
            $this->addCheck($report, 'order.pending_contact.delivery_status', !empty($order) && (int)$order['delivery_status'] === DeliveryStatusEnum::NOT_DELIVERED, 'failure', 'pending-contact delivery_status must be NOT_DELIVERED');
            $this->addCheck($report, 'order.pending_contact.receipt_status', !empty($order) && (int)$order['receipt_status'] === ReceiptStatusEnum::NOT_RECEIVED, 'failure', 'pending-contact receipt_status must be NOT_RECEIVED');
        }
        if (!empty($expectations['duplicate_notify'])) {
            $this->addCheck($report, 'notify.duplicate', (int)$tradeData['notify_times'] >= 2, 'failure', 'duplicate notify evidence requires notify_times >= 2');
            $this->addCheck($report, 'notify.pay_snapshot', isset($snapshot['pay_notify']), 'failure', 'pay_notify snapshot must exist');
        }
        if (!empty($expectations['query_evidence'])) {
            $queryStatus = (int)($snapshot['query_order']['status']
                ?? $snapshot['query_order']['result']['order']['status']
                ?? -1);
            $this->addCheck($report, 'query.snapshot', isset($snapshot['query_order']), 'failure', 'query_order snapshot must exist');
            $this->addCheck($report, 'query.paid_status', in_array($queryStatus, [2, 3], true), 'failure', 'query_order status must indicate paid or paid-pending-delivery');
        }
        if (!empty($expectations['probe_remote_query'])) {
            $this->probeRemoteQueryOrder($report, $storeId, $tradeData);
        }
        if (!empty($expectations['no_duplicate_refund'])) {
            $this->addCheck($report, 'refund.not_duplicated', (int)$refundCount <= 1, 'failure', 'audited order must not have duplicate refund records');
        }
        if (!empty($expectations['ios_refund_query'])) {
            $queryNotify = (array)($snapshot['ios_refund_query_notify'] ?? []);
            $queryPayload = (array)($queryNotify['payload'] ?? []);
            $queryDecision = (string)($snapshot['virtual_refund']['ios_refund_query_decision'] ?? '');
            $this->addCheck(
                $report,
                'refund.ios_query_notify',
                (string)($queryNotify['event'] ?? '') === 'xpay_subscribe_ios_refund_query_notify'
                    && (int)($queryNotify['received_at'] ?? 0) > 0
                    && !empty($queryPayload),
                'failure',
                'authenticated Apple refund inquiry snapshot must exist'
            );
            $this->addCheck(
                $report,
                'refund.ios_query_decision',
                $queryDecision === 'suggest_refund',
                'failure',
                'Apple refund inquiry decision must be persisted as suggest_refund'
            );
        }
        if (!empty($expectations['refunded'])) {
            $refundNotify = (array)($snapshot['refund_notify'] ?? []);
            $virtualRefundStatus = (string)($snapshot['virtual_refund']['status'] ?? '');
            $this->addCheck(
                $report,
                'refund.success_notify',
                (string)($refundNotify['event'] ?? '') === 'xpay_refund_notify'
                    && (int)($refundNotify['received_at'] ?? 0) > 0
                    && (int)($refundNotify['payload']['RetCode'] ?? -1) === 0,
                'failure',
                'successful xpay_refund_notify snapshot must exist'
            );
            $this->addCheck($report, 'refund.single_row', (int)$refundCount === 1, 'failure', 'refunded order must have exactly one refund row');
            $this->addCheck($report, 'refund.row_completed', !empty($refund) && (int)$refund['status'] === RefundStatusEnum::COMPLETED, 'failure', 'order_refund status must be COMPLETED');
            $this->addCheck($report, 'refund.order_cancelled', !empty($order) && (int)$order['order_status'] === OrderStatusEnum::CANCELLED, 'failure', 'order status must be CANCELLED');
            $this->addCheck($report, 'refund.trade_refunded', (int)$tradeData['trade_state'] === TradeStatusEnum::REFUND, 'failure', 'payment_trade state must be REFUND');
            $this->addCheck($report, 'refund.snapshot_completed', $virtualRefundStatus === 'completed', 'failure', 'virtual_refund snapshot status must be completed');
        }
        if (!empty($expectations['provide_goods_idempotent'])) {
            $provideGoodsStatus = (string)($snapshot['provide_goods']['status'] ?? '');
            $startedAt = (int)($snapshot['provide_goods']['dispatch_started_at'] ?? 0);
            $isRetrySafeSending = $provideGoodsStatus === 'sending' && $startedAt > 0 && $startedAt <= (time() - 300);
            $isNotDispatchedYet = $provideGoodsStatus === ''
                && !empty($order)
                && (int)$order['order_status'] !== OrderStatusEnum::COMPLETED;
            $this->addCheck(
                $report,
                'provide_goods.idempotent_state',
                $isNotDispatchedYet || \in_array($provideGoodsStatus, ['pending', 'success', 'failed', 'skipped'], true) || $isRetrySafeSending,
                'failure',
                'provide_goods status must be absent before completion, terminal, pending, skipped, failed, or retry-safe sending'
            );
        }
    }

    private function addRequiredStringCheck(array &$report, array $source, string $field, string $name): void
    {
        $this->addCheck($report, $name, trim((string)($source[$field] ?? '')) !== '', 'failure', "{$name} must be configured");
    }

    private function probeRemoteQueryOrder(array &$report, int $storeId, array $tradeData): void
    {
        $userId = (int)($tradeData['user_id'] ?? 0);
        $env = (int)($tradeData['env'] ?? 0);
        $outTradeNo = (string)($tradeData['out_trade_no'] ?? '');
        $oauth = $userId > 0 ? UserOauthModel::getOauth($userId, ClientEnum::MP_WEIXIN) : null;
        $openid = (string)($oauth['oauth_id'] ?? '');
        $report['remote_query_probe'] = [
            'out_trade_no' => $outTradeNo,
            'user_id' => $userId,
            'openid_present' => $openid !== '',
            'env' => $env,
        ];
        if ($openid === '') {
            $this->addCheck($report, 'remote_query.openid', false, 'warning', 'remote query probe requires MP-WEIXIN openid');
            return;
        }

        $config = StoreSettingModel::getItem(SettingEnum::VIRTUAL_PAYMENT, $storeId);
        $wxapp = WxappSettingModel::getConfigBasic($storeId);
        $appId = (string)($wxapp['app_id'] ?? '');
        $appSecret = (string)($wxapp['app_secret'] ?? '');
        $appKey = $env === GoodsModel::VP_ENV_SANDBOX
            ? (string)($config['sandbox_app_key'] ?? '')
            : (string)($config['production_app_key'] ?? '');
        if ($appId === '' || $appSecret === '' || $appKey === '') {
            $this->addCheck($report, 'remote_query.config', false, 'warning', 'remote query probe requires app_id/app_secret/app_key');
            return;
        }

        $payload = [
            'openid' => $openid,
            'env' => $env,
            'order_id' => $outTradeNo,
        ];
        $report['remote_query_probe']['payload'] = [
            'openid_masked' => substr($openid, 0, 6) . '***' . substr($openid, -4),
            'env' => $env,
            'order_id' => $outTradeNo,
        ];

        try {
            $payment = new WechatVirtualPayment($appId, $appSecret, $appKey);
            $result = $payment->queryOrder($payload);
            $report['remote_query_probe']['result'] = $this->sanitizeEvidencePayload($result);
            $errCode = (int)($result['errcode'] ?? -1);
            $status = (int)($result['order']['status'] ?? -1);
            $report['remote_query_probe']['interpretation'] = $this->interpretRemoteQueryProbe($result);
            $this->addCheck($report, 'remote_query.transport', true, 'warning', 'remote query probe completed');
            $this->addCheck($report, 'remote_query.errcode', $errCode === 0, 'warning', 'remote query errcode must be 0');
            if ($errCode === 0) {
                $hasOrder = isset($result['order']) && \is_array($result['order']);
                $this->addCheck($report, 'remote_query.order', $hasOrder, 'warning', 'remote query must return order payload');
                if ($hasOrder) {
                    $this->addCheck($report, 'remote_query.status', $status >= 0, 'warning', sprintf('remote query returned status=%d', $status));
                }
            }
        } catch (\Throwable $e) {
            $report['remote_query_probe']['exception'] = [
                'class' => get_class($e),
                'message' => $e->getMessage(),
            ];
            $this->addCheck($report, 'remote_query.transport', false, 'warning', $e->getMessage());
        }
    }

    private function interpretRemoteQueryProbe(array $result): array
    {
        $errCode = (int)($result['errcode'] ?? -1);
        $errMsg = (string)($result['errmsg'] ?? '');
        $status = (int)($result['order']['status'] ?? -1);
        if ($errCode === 0) {
            if (in_array($status, [2, 3], true)) {
                return [
                    'phase' => 'remote_paid',
                    'message' => $status === 3
                        ? 'WeChat remote order exists and is paid; status=3 means paid and waiting for downstream delivery/consumption'
                        : 'WeChat remote order exists and is paid',
                ];
            }
            if ($status === 6) {
                return [
                    'phase' => 'remote_order_closed',
                    'message' => 'WeChat remote order exists but status=6 means the order was closed before payment success; prioritize DevTools automator residue, preview-vs-experience runtime differences, and sandbox test-account/product whitelist checks',
                ];
            }
            if ($status >= 0) {
                return [
                    'phase' => 'remote_order_exists_not_paid',
                    'message' => sprintf('WeChat remote order exists but status=%d is not the paid state', $status),
                ];
            }
            return [
                'phase' => 'remote_order_payload_missing_status',
                'message' => 'WeChat remote query returned errcode=0 but no recognized order status',
            ];
        }
        if ($errCode === 268490002 && str_contains($errMsg, '数据不存在')) {
            return [
                'phase' => 'remote_order_not_created_yet',
                'message' => 'WeChat remote query says the order does not exist yet; this is consistent with a backend-generated trade that has not been launched/accepted by wx.requestVirtualPayment on the client',
            ];
        }
        return [
            'phase' => 'remote_query_error',
            'message' => sprintf('WeChat remote query returned errcode=%d errmsg=%s', $errCode, $errMsg),
        ];
    }

    /**
     * Recursively redact credentials and signing material before writing evidence files.
     * Upstream query responses may contain an order token even when the request payload is already masked.
     * @param mixed $value
     * @param string $key
     * @return mixed
     */
    private function sanitizeEvidencePayload($value, string $key = '')
    {
        if ($key !== '' && preg_match('/(?:token|secret|signature|app_key|session_key|aes_key)/i', $key)) {
            return $value === null || $value === '' ? $value : '***' . strlen((string)$value);
        }
        if (!is_array($value)) {
            return $value;
        }
        $sanitized = [];
        foreach ($value as $childKey => $childValue) {
            $sanitized[$childKey] = $this->sanitizeEvidencePayload($childValue, (string)$childKey);
        }
        return $sanitized;
    }

    private function addCheck(array &$report, string $name, bool $ok, string $severity, string $message): void
    {
        if (!$ok && $severity === 'failure') {
            $report['summary']['failures']++;
        }
        if (!$ok && $severity === 'warning') {
            $report['summary']['warnings']++;
        }
        $report['checks'][] = [
            'name' => $name,
            'ok' => $ok,
            'severity' => $severity,
            'message' => $message,
        ];
    }

    private function writeEvidence(string $evidenceDir, array $report): string
    {
        if (!is_dir($evidenceDir) && !mkdir($evidenceDir, 0775, true) && !is_dir($evidenceDir)) {
            throw new \RuntimeException(sprintf('failed to create evidence dir: %s', $evidenceDir));
        }
        $file = $evidenceDir . '/sandbox-check-' . date('YmdHis') . '-' . substr(str_replace('.', '', (string)microtime(true)), -6) . '.json';
        $json = json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json === false) {
            throw new \RuntimeException('failed to encode sandbox-check evidence json');
        }
        if (file_put_contents($file, $json) === false) {
            throw new \RuntimeException(sprintf('failed to write evidence file: %s', $file));
        }
        return $file;
    }
}
