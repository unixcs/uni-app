<?php
// +----------------------------------------------------------------------
// | Local virtual payment reliability checks
// +----------------------------------------------------------------------
declare (strict_types=1);

namespace app\common\command;

use app\api\model\PaymentTrade as ApiPaymentTradeModel;
use app\api\service\order\PaySuccess as OrderPaySuccessService;
use app\api\service\passport\Login as LoginService;
use app\common\enum\Client as ClientEnum;
use app\common\enum\OrderType as OrderTypeEnum;
use app\common\enum\goods\DeductStockType as DeductStockTypeEnum;
use app\common\enum\order\DeliveryStatus as DeliveryStatusEnum;
use app\common\enum\order\OrderStatus as OrderStatusEnum;
use app\common\enum\order\PayStatus as PayStatusEnum;
use app\common\enum\order\ReceiptStatus as ReceiptStatusEnum;
use app\common\enum\payment\Method as PaymentMethodEnum;
use app\common\enum\payment\trade\TradeStatus as TradeStatusEnum;
use app\common\library\helper;
use app\common\model\Goods as GoodsModel;
use app\common\model\Order as OrderModel;
use app\common\model\PaymentTrade as PaymentTradeModel;
use app\common\model\User as UserModel;
use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;
use think\facade\Db;
use RuntimeException;

class VirtualPaymentLocalE2e extends Command
{
    private const STORE_ID = 10001;
    private const DEBUG_MOBILE = '19900000000';
    private const ORDER_PREFIX = 'VPL';
    private const DEFAULT_EVIDENCE_DIR = '/opt/yoshop/runtime/virtual-payment-local-e2e';

    private string $evidenceDir = self::DEFAULT_EVIDENCE_DIR;

    protected function configure()
    {
        $this->setName('virtual-payment:local-e2e')
            ->addArgument('action', Argument::OPTIONAL, 'check/cleanup', 'check')
            ->addOption('goods-id', null, Option::VALUE_OPTIONAL, 'Virtual-payment service goods ID', '')
            ->addOption('evidence-dir', null, Option::VALUE_OPTIONAL, 'Evidence output directory', self::DEFAULT_EVIDENCE_DIR)
            ->setDescription('Run local virtual-payment notification/query idempotency checks without calling WeChat');
    }

    protected function execute(Input $input, Output $output)
    {
        $this->evidenceDir = rtrim((string)$input->getOption('evidence-dir'), '/');
        $action = (string)$input->getArgument('action');

        if ($action === 'cleanup') {
            $counts = $this->cleanupGeneratedData();
            $this->writeJson('cleanup-' . date('YmdHis') . '.json', $counts);
            $output->writeln(sprintf('<info>cleanup ok:</info> orders=%d trades=%d goods=%d refunds=%d', $counts['orders'], $counts['trades'], $counts['goods'], $counts['refunds']));
            return 0;
        }

        if ($action !== 'check') {
            $output->writeln(sprintf('<error>unsupported action: %s</error>', $action));
            return 1;
        }

        $report = $this->runChecks((int)$input->getOption('goods-id'));
        $this->writeJson('check-' . date('YmdHis') . '.json', $report);
        foreach ($report['checks'] as $check) {
            $output->writeln(sprintf('<info>ok</info> %s', $check));
        }
        $output->writeln(sprintf('<info>local virtual-payment checks ok:</info> %s', $report['run_id']));
        return 0;
    }

    private function runChecks(int $goodsId): array
    {
        $this->cleanupGeneratedData();
        $userId = $this->getOrCreateDebugUserId();
        $goods = $this->resolveVirtualPaymentGoods($goodsId);
        $runId = 'vplocal-' . date('YmdHis') . '-' . random_int(100, 999);

        $notify = $this->createLocalVirtualOrder($userId, $goods, $runId, 'DUP_NOTIFY');
        $this->simulatePayNotify($notify, 'first');
        $this->simulatePayNotify($notify, 'duplicate');
        $this->assertDuplicateNotifyConverged($notify);

        $query = $this->createLocalVirtualOrder($userId, $goods, $runId, 'QUERY_FALLBACK');
        $this->simulateQueryFallback($query);
        $this->assertQueryFallbackConverged($query);

        $replay = $this->createLocalVirtualOrder($userId, $goods, $runId, 'REPLAY_OUT_TRADE');
        $this->simulateRepeatedPaymentAttempt($replay, $goods);
        $this->assertRepeatedAttemptKeepsOldTradeConverged($replay);

        $this->assertProvideGoodsClaimIdempotent((int)$notify['trade_id']);

        return [
            'run_id' => $runId,
            'goods_id' => (int)$goods['goods_id'],
            'orders' => [
                'duplicate_notify' => (int)$notify['order_id'],
                'query_fallback' => (int)$query['order_id'],
                'replay_out_trade' => (int)$replay['order_id'],
            ],
            'trades' => [
                'duplicate_notify' => (int)$notify['trade_id'],
                'query_fallback' => (int)$query['trade_id'],
                'replay_out_trade' => (int)$replay['trade_id'],
            ],
            'checks' => [
                'duplicate payment notifications increment notify_times but keep one paid order',
                'duplicate payment notifications do not create refund rows',
                'query_order snapshot with paid status converges order to paid',
                'repeated pay attempts keep historical virtual out_trade_no available for late query/notify convergence',
                'late duplicate-payment callbacks do not reissue virtual refunds while refund is already processing',
                'provide_goods dispatch claim is idempotent after success',
            ],
        ];
    }

    private function createLocalVirtualOrder(int $userId, array $goods, string $runId, string $scenario): array
    {
        $source = $this->findSourceServiceOrder();
        $orderNo = $this->generateOrderNo();
        $sourceData = $this->decodeJson((string)($source['order_source_data'] ?? ''));
        $sourceData['service_contact'] = [
            'contact_name' => 'Virtual Local E2E',
            'contact_mobile' => self::DEBUG_MOBILE,
            'time_preference' => 'anytime',
        ];
        $sourceData['virtual_payment_local_e2e'] = [
            'run_id' => $runId,
            'scenario' => $scenario,
            'source_order_id' => (int)$source['order_id'],
        ];

        $orderRow = $source;
        unset($orderRow['order_id']);
        $orderRow['order_no'] = $orderNo;
        $orderRow['user_id'] = $userId;
        $orderRow['store_id'] = self::STORE_ID;
        $orderRow['pay_method'] = PaymentMethodEnum::WECHAT;
        $orderRow['pay_status'] = PayStatusEnum::PENDING;
        $orderRow['pay_time'] = 0;
        $orderRow['trade_id'] = 0;
        $orderRow['order_status'] = OrderStatusEnum::NORMAL;
        $orderRow['delivery_status'] = DeliveryStatusEnum::NOT_DELIVERED;
        $orderRow['receipt_status'] = ReceiptStatusEnum::NOT_RECEIVED;
        $orderRow['delivery_time'] = 0;
        $orderRow['receipt_time'] = 0;
        $orderRow['buyer_remark'] = self::ORDER_PREFIX . ':' . $runId . ':' . $scenario;
        $orderRow['order_source_data'] = helper::jsonEncode($sourceData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $orderRow['create_time'] = time();
        $orderRow['update_time'] = time();
        $orderRow['is_delete'] = 0;

        $orderId = (int)Db::name('order')->insertGetId($orderRow);
        $this->cloneOrderGoods((int)$source['order_id'], $orderId, $userId);

        $outTradeNo = 'VPLOCAL' . date('YmdHis') . random_int(1000, 9999);
        $snapshot = [
            'offerId' => 'local-offer',
            'buyQuantity' => 1,
            'env' => GoodsModel::VP_ENV_SANDBOX,
            'currencyType' => 'CNY',
            'productId' => (string)$goods['vp_product_id'],
            'goodsPrice' => (int)$goods['vp_price_snapshot'],
            'outTradeNo' => $outTradeNo,
            'attach' => $orderNo,
            'local_e2e' => [
                'run_id' => $runId,
                'scenario' => $scenario,
            ],
        ];
        $tradeId = (int)Db::name('payment_trade')->insertGetId([
            'out_trade_no' => $outTradeNo,
            'client' => ClientEnum::MP_WEIXIN,
            'pay_method' => PaymentMethodEnum::WECHAT,
            'platform' => 'wechat_virtual',
            'order_type' => OrderTypeEnum::ORDER,
            'order_id' => $orderId,
            'order_no' => $orderNo,
            'user_id' => $userId,
            'trade_no' => '',
            'prepay_id' => '',
            'env' => GoodsModel::VP_ENV_SANDBOX,
            'product_id' => (string)$goods['vp_product_id'],
            'goods_price' => (int)$goods['vp_price_snapshot'],
            'attach' => $orderNo,
            'notify_times' => 0,
            'last_notify_time' => 0,
            'payload_snapshot' => helper::jsonEncode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'trade_state' => TradeStatusEnum::UNPAID,
            'store_id' => self::STORE_ID,
            'create_time' => time(),
            'update_time' => time(),
        ]);

        return [
            'order_id' => $orderId,
            'order_no' => $orderNo,
            'trade_id' => $tradeId,
            'out_trade_no' => $outTradeNo,
        ];
    }

    private function cloneOrderGoods(int $sourceOrderId, int $orderId, int $userId): void
    {
        $goods = Db::name('order_goods')->where('order_id', '=', $sourceOrderId)->select()->toArray();
        if (empty($goods)) {
            throw new RuntimeException('source service order has no goods rows');
        }
        foreach ($goods as $item) {
            unset($item['order_goods_id']);
            $item['order_id'] = $orderId;
            $item['user_id'] = $userId;
            $item['store_id'] = self::STORE_ID;
            $item['create_time'] = time();
            Db::name('order_goods')->insert($item);
        }
    }

    private function simulatePayNotify(array $fixture, string $tag): void
    {
        $payload = [
            'ToUserName' => 'gh_local',
            'FromUserName' => 'wxpay',
            'CreateTime' => time(),
            'MsgType' => 'event',
            'Event' => 'xpay_goods_deliver_notify',
            'OpenId' => 'local-openid',
            'OutTradeNo' => (string)$fixture['out_trade_no'],
            'Env' => GoodsModel::VP_ENV_SANDBOX,
            'WeChatPayInfo' => [
                'MchOrderNo' => (string)$fixture['out_trade_no'],
                'TransactionId' => 'LOCALWX' . (string)$fixture['trade_id'],
                'PaidTime' => time(),
            ],
            'LocalDuplicateTag' => $tag,
        ];
        PaymentTradeModel::recordNotify((int)$fixture['trade_id'], 'pay_notify', 'xpay_goods_deliver_notify', $payload);
        $this->markOrderPaid($fixture, [
            'tradeNo' => 'LOCALWX' . (string)$fixture['trade_id'],
            'outTradeNo' => (string)$fixture['out_trade_no'],
            'orderStatus' => 2,
            'raw' => $payload,
        ]);
    }

    private function simulateQueryFallback(array $fixture): void
    {
        $result = [
            'errcode' => 0,
            'errmsg' => 'ok',
            'order' => [
                'status' => 2,
                'wxpay_order_id' => 'LOCALQUERY' . (string)$fixture['trade_id'],
            ],
        ];
        PaymentTradeModel::mergePayloadSnapshot((int)$fixture['trade_id'], [
            'query_order' => [
                'queried_at' => time(),
                'payload' => [
                    'openid' => 'local-openid',
                    'env' => GoodsModel::VP_ENV_SANDBOX,
                    'order_id' => (string)$fixture['out_trade_no'],
                ],
                'result' => $result,
                'status' => 2,
            ],
        ]);
        $this->markOrderPaid($fixture, [
            'tradeNo' => 'LOCALQUERY' . (string)$fixture['trade_id'],
            'outTradeNo' => (string)$fixture['out_trade_no'],
            'orderStatus' => 2,
            'raw' => $result,
        ]);
    }

    private function simulateRepeatedPaymentAttempt(array $fixture, array $goods): void
    {
        $orderInfo = [
            'order_id' => (int)$fixture['order_id'],
            'order_no' => (string)$fixture['order_no'],
            'user_id' => $this->getOrCreateDebugUserId(),
        ];
        $newOutTradeNo = 'VPLOCAL' . date('YmdHis') . random_int(1000, 9999);
        ApiPaymentTradeModel::$storeId = self::STORE_ID;
        ApiPaymentTradeModel::record($orderInfo, PaymentMethodEnum::WECHAT, ClientEnum::MP_WEIXIN, OrderTypeEnum::ORDER, [
            'out_trade_no' => $newOutTradeNo,
            'platform' => 'wechat_virtual',
            'env' => GoodsModel::VP_ENV_SANDBOX,
            'product_id' => (string)$goods['vp_product_id'],
            'goods_price' => (int)$goods['vp_price_snapshot'],
            'attach' => (string)$fixture['order_no'],
            'payload_snapshot' => helper::jsonEncode([
                'local_e2e' => [
                    'replayed_attempt' => true,
                    'original_out_trade_no' => (string)$fixture['out_trade_no'],
                    'outTradeNo' => $newOutTradeNo,
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    private function markOrderPaid(array $fixture, array $paymentData): void
    {
        $service = new OrderPaySuccessService();
        $service->setOrderNo((string)$fixture['order_no'])
            ->setMethod(PaymentMethodEnum::WECHAT)
            ->setTradeId((int)$fixture['trade_id'])
            ->setPaymentData($paymentData)
            ->handle();
    }

    private function assertDuplicateNotifyConverged(array $fixture): void
    {
        $order = Db::name('order')->where('order_id', '=', (int)$fixture['order_id'])->find();
        $trade = Db::name('payment_trade')->where('trade_id', '=', (int)$fixture['trade_id'])->find();
        $refunds = Db::name('order_refund')->where('order_id', '=', (int)$fixture['order_id'])->count();
        $this->assertTrue(!empty($order) && (int)$order['pay_status'] === PayStatusEnum::SUCCESS, 'duplicate notify order paid');
        $this->assertTrue(!empty($order) && (int)$order['trade_id'] === (int)$fixture['trade_id'], 'duplicate notify order links trade');
        $this->assertTrue(!empty($trade) && (int)$trade['trade_state'] === TradeStatusEnum::SUCCESS, 'duplicate notify trade success');
        $this->assertTrue(!empty($trade) && (int)$trade['notify_times'] === 2, 'duplicate notify count');
        $this->assertTrue((int)$refunds === 0, 'duplicate notify no refund created');
    }

    private function assertQueryFallbackConverged(array $fixture): void
    {
        $order = Db::name('order')->where('order_id', '=', (int)$fixture['order_id'])->find();
        $trade = Db::name('payment_trade')->where('trade_id', '=', (int)$fixture['trade_id'])->find();
        $snapshot = PaymentTradeModel::decodePayloadSnapshot((string)($trade['payload_snapshot'] ?? ''));
        $this->assertTrue(!empty($order) && (int)$order['pay_status'] === PayStatusEnum::SUCCESS, 'query fallback order paid');
        $this->assertTrue(!empty($trade) && (int)$trade['trade_state'] === TradeStatusEnum::SUCCESS, 'query fallback trade success');
        $this->assertTrue((int)($snapshot['query_order']['status'] ?? -1) === 2, 'query fallback snapshot paid');
    }

    private function assertRepeatedAttemptKeepsOldTradeConverged(array $fixture): void
    {
        $trades = Db::name('payment_trade')
            ->where('order_id', '=', (int)$fixture['order_id'])
            ->order('trade_id', 'asc')
            ->select()
            ->toArray();
        $this->assertTrue(count($trades) === 2, 'repeated attempt creates second trade row');
        $firstTrade = (array)$trades[0];
        $secondTrade = (array)$trades[1];
        $this->assertTrue((string)$firstTrade['out_trade_no'] !== (string)$secondTrade['out_trade_no'], 'repeated attempt keeps distinct out_trade_no');
        $resolved = PaymentTradeModel::detailByOutTradeNo((string)$fixture['out_trade_no']);
        $this->assertTrue((int)$resolved['trade_id'] === (int)$firstTrade['trade_id'], 'old out_trade_no still resolves after replay');

        $result = [
            'errcode' => 0,
            'errmsg' => 'ok',
            'order' => [
                'status' => 2,
                'wxpay_order_id' => 'LOCALREPLAY' . (string)$firstTrade['trade_id'],
            ],
        ];
        PaymentTradeModel::mergePayloadSnapshot((int)$firstTrade['trade_id'], [
            'query_order' => [
                'queried_at' => time(),
                'payload' => [
                    'openid' => 'local-openid',
                    'env' => GoodsModel::VP_ENV_SANDBOX,
                    'order_id' => (string)$fixture['out_trade_no'],
                ],
                'result' => $result,
                'status' => 2,
            ],
        ]);
        $this->markOrderPaid([
            'order_no' => (string)$fixture['order_no'],
            'trade_id' => (int)$firstTrade['trade_id'],
            'out_trade_no' => (string)$fixture['out_trade_no'],
        ], [
            'tradeNo' => 'LOCALREPLAY' . (string)$firstTrade['trade_id'],
            'outTradeNo' => (string)$fixture['out_trade_no'],
            'orderStatus' => 2,
            'raw' => $result,
        ]);

        $order = Db::name('order')->where('order_id', '=', (int)$fixture['order_id'])->find();
        $paidTrade = Db::name('payment_trade')->where('trade_id', '=', (int)$firstTrade['trade_id'])->find();
        $pendingTrade = Db::name('payment_trade')->where('trade_id', '=', (int)$secondTrade['trade_id'])->find();
        $this->assertTrue(!empty($order) && (int)$order['trade_id'] === (int)$firstTrade['trade_id'], 'late first trade still converges paid order');
        $this->assertTrue(!empty($paidTrade) && (int)$paidTrade['trade_state'] === TradeStatusEnum::SUCCESS, 'late first trade marked success');
        $this->assertTrue(!empty($pendingTrade) && (int)$pendingTrade['trade_state'] === TradeStatusEnum::UNPAID, 'newer replay attempt stays pending until its own result arrives');

        // Simulate the loser trade receiving repeated late callbacks after a duplicate-payment refund
        // has already been launched; the order must not trigger another refund attempt.
        PaymentTradeModel::mergePayloadSnapshot((int)$secondTrade['trade_id'], [
            'virtual_refund' => [
                'status' => 'processing',
                'duplicate_payment' => true,
                'refund_order_id' => 'vrf-local-duplicate',
                'requested_at' => time(),
            ],
        ]);
        $duplicatePayload = [
            'ToUserName' => 'gh_local',
            'FromUserName' => 'wxpay',
            'CreateTime' => time(),
            'MsgType' => 'event',
            'Event' => 'xpay_goods_deliver_notify',
            'OpenId' => 'local-openid',
            'OutTradeNo' => (string)$secondTrade['out_trade_no'],
            'Env' => GoodsModel::VP_ENV_SANDBOX,
            'WeChatPayInfo' => [
                'MchOrderNo' => (string)$secondTrade['out_trade_no'],
                'TransactionId' => 'LOCALWXDUP' . (string)$secondTrade['trade_id'],
                'PaidTime' => time(),
            ],
        ];
        PaymentTradeModel::recordNotify((int)$secondTrade['trade_id'], 'pay_notify', 'xpay_goods_deliver_notify', $duplicatePayload + ['LocalDuplicateTag' => 'late_duplicate_first']);
        $this->markOrderPaid([
            'order_no' => (string)$fixture['order_no'],
            'trade_id' => (int)$secondTrade['trade_id'],
            'out_trade_no' => (string)$secondTrade['out_trade_no'],
        ], [
            'tradeNo' => 'LOCALWXDUP' . (string)$secondTrade['trade_id'],
            'outTradeNo' => (string)$secondTrade['out_trade_no'],
            'orderStatus' => 2,
            'raw' => $duplicatePayload,
        ]);
        PaymentTradeModel::recordNotify((int)$secondTrade['trade_id'], 'pay_notify', 'xpay_goods_deliver_notify', $duplicatePayload + ['LocalDuplicateTag' => 'late_duplicate_second']);
        $this->markOrderPaid([
            'order_no' => (string)$fixture['order_no'],
            'trade_id' => (int)$secondTrade['trade_id'],
            'out_trade_no' => (string)$secondTrade['out_trade_no'],
        ], [
            'tradeNo' => 'LOCALWXDUP' . (string)$secondTrade['trade_id'],
            'outTradeNo' => (string)$secondTrade['out_trade_no'],
            'orderStatus' => 2,
            'raw' => $duplicatePayload,
        ]);

        $secondTradeAfterDuplicate = Db::name('payment_trade')->where('trade_id', '=', (int)$secondTrade['trade_id'])->find();
        $secondSnapshot = PaymentTradeModel::decodePayloadSnapshot((string)($secondTradeAfterDuplicate['payload_snapshot'] ?? ''));
        $refunds = Db::name('order_refund')->where('order_id', '=', (int)$fixture['order_id'])->count();
        $this->assertTrue(!empty($secondTradeAfterDuplicate) && (int)$secondTradeAfterDuplicate['notify_times'] === 2, 'late duplicate loser trade increments notify count');
        $this->assertTrue((string)($secondSnapshot['virtual_refund']['status'] ?? '') === 'processing', 'late duplicate loser trade keeps existing virtual refund state');
        $this->assertTrue((int)$refunds === 0, 'late duplicate loser trade does not create local refund rows');
    }

    private function assertProvideGoodsClaimIdempotent(int $tradeId): void
    {
        $first = PaymentTradeModel::claimProvideGoodsDispatch($tradeId, 'local_e2e');
        $this->assertTrue(!empty($first['claimed']), 'provide goods first claim');
        PaymentTradeModel::finishProvideGoodsDispatch($tradeId, 'success', [
            'request_payload' => ['order_id' => 'local'],
            'result' => ['errcode' => 0, 'errmsg' => 'ok'],
        ]);
        $second = PaymentTradeModel::claimProvideGoodsDispatch($tradeId, 'local_e2e_duplicate');
        $this->assertTrue(empty($second['claimed']) && (string)($second['state'] ?? '') === 'success', 'provide goods duplicate claim skipped');
    }

    private function findSourceServiceOrder(): array
    {
        $rows = Db::name('order')
            ->where('store_id', '=', self::STORE_ID)
            ->where('is_delete', '=', 0)
            ->where('order_no', 'not like', self::ORDER_PREFIX . '%')
            ->order('order_id', 'desc')
            ->select()
            ->toArray();
        foreach ($rows as $row) {
            if (OrderModel::isServiceOrderData($row)) {
                return $row;
            }
        }
        throw new RuntimeException('no source service order found for local virtual-payment e2e');
    }

    private function resolveVirtualPaymentGoods(int $goodsId): array
    {
        $query = Db::name('goods')
            ->where('store_id', '=', self::STORE_ID)
            ->where('is_delete', '=', 0)
            ->where('status', '=', 10)
            ->where('vp_enabled', '=', 1);
        if ($goodsId > 0) {
            $query->where('goods_id', '=', $goodsId);
        }
        $goods = $query->order('goods_id', 'asc')->find();
        if (empty($goods)) {
            throw new RuntimeException('no enabled virtual-payment goods found');
        }
        if (trim((string)$goods['vp_product_id']) === '' || (int)$goods['vp_price_snapshot'] <= 0) {
            throw new RuntimeException('virtual-payment goods mapping is incomplete');
        }
        return $goods;
    }

    private function cleanupGeneratedData(): array
    {
        $candidateOrders = Db::name('order')
            ->where('store_id', '=', self::STORE_ID)
            ->where(function ($query) {
                $query->where('order_no', 'like', self::ORDER_PREFIX . '%')
                    ->whereOr('buyer_remark', 'like', self::ORDER_PREFIX . ':%');
            })
            ->field('order_id,order_source_data,buyer_remark')
            ->select()
            ->toArray();
        $orderIds = [];
        foreach ($candidateOrders as $row) {
            $sourceData = $this->decodeJson((string)($row['order_source_data'] ?? ''));
            $isLocalE2e = !empty($sourceData['virtual_payment_local_e2e'])
                || str_starts_with((string)($row['buyer_remark'] ?? ''), self::ORDER_PREFIX . ':');
            if ($isLocalE2e) {
                $orderIds[] = (int)$row['order_id'];
            }
        }
        $tradeQuery = Db::name('payment_trade')
            ->where('payload_snapshot', 'like', '%local_e2e%');
        if (!empty($orderIds)) {
            $tradeQuery->whereOr('order_id', 'in', $orderIds);
        }
        $trades = $tradeQuery->field('trade_id')->select()->toArray();
        $tradeIds = array_map(static fn($row) => (int)$row['trade_id'], $trades);

        $goodsCount = empty($orderIds) ? 0 : (int)Db::name('order_goods')->where('order_id', 'in', $orderIds)->count();
        $refundCount = empty($orderIds) ? 0 : (int)Db::name('order_refund')->where('order_id', 'in', $orderIds)->count();
        if (!empty($orderIds)) {
            $this->restoreStockSales($orderIds);
        }
        Db::transaction(function () use ($orderIds, $tradeIds) {
            if (!empty($orderIds)) {
                Db::name('order_refund')->where('order_id', 'in', $orderIds)->delete();
                Db::name('order_goods')->where('order_id', 'in', $orderIds)->delete();
                Db::name('order')->where('order_id', 'in', $orderIds)->delete();
            }
            if (!empty($tradeIds)) {
                Db::name('payment_trade')->where('trade_id', 'in', $tradeIds)->delete();
            }
        });
        return [
            'orders' => count($orderIds),
            'trades' => count($tradeIds),
            'goods' => $goodsCount,
            'refunds' => $refundCount,
        ];
    }

    private function restoreStockSales(array $orderIds): void
    {
        $rows = Db::name('order_goods')
            ->alias('og')
            ->join('order o', 'o.order_id = og.order_id')
            ->where('og.order_id', 'in', $orderIds)
            ->field('og.goods_id,og.goods_sku_id,og.total_num,og.deduct_stock_type,o.pay_status')
            ->select()
            ->toArray();
        foreach ($rows as $row) {
            $num = (int)($row['total_num'] ?? 0);
            if ($num <= 0 || (int)($row['pay_status'] ?? 0) !== PayStatusEnum::SUCCESS) {
                continue;
            }
            $goodsUpdater = Db::name('goods')->where('goods_id', '=', (int)$row['goods_id'])->dec('sales_actual', $num);
            if ((int)($row['deduct_stock_type'] ?? 0) === DeductStockTypeEnum::PAYMENT) {
                $goodsUpdater->inc('stock_total', $num);
                Db::name('goods_sku')
                    ->where('goods_id', '=', (int)$row['goods_id'])
                    ->where('goods_sku_id', '=', (string)$row['goods_sku_id'])
                    ->inc('stock_num', $num)
                    ->update();
            }
            $goodsUpdater->update();
        }
    }

    private function getOrCreateDebugUserId(): int
    {
        $user = UserModel::detail(['mobile' => self::DEBUG_MOBILE]);
        if (!empty($user)) {
            return (int)$user['user_id'];
        }
        $service = new LoginService();
        if (!$service->loginDebug()) {
            throw new RuntimeException($service->getError() ?: 'create debug user failed');
        }
        $user = UserModel::detail(['mobile' => self::DEBUG_MOBILE]);
        if (empty($user)) {
            throw new RuntimeException('debug user still missing after creation');
        }
        return (int)$user['user_id'];
    }

    private function generateOrderNo(): string
    {
        return self::ORDER_PREFIX . date('YmdHis') . random_int(100, 999);
    }

    private function decodeJson(string $json): array
    {
        $data = helper::jsonDecode($json);
        return is_array($data) ? $data : [];
    }

    private function assertTrue(bool $condition, string $message): void
    {
        if (!$condition) {
            throw new RuntimeException('assert failed: ' . $message);
        }
    }

    private function writeJson(string $name, array $data): void
    {
        if (!is_dir($this->evidenceDir) && !mkdir($this->evidenceDir, 0775, true) && !is_dir($this->evidenceDir)) {
            throw new RuntimeException(sprintf('failed to create evidence dir: %s', $this->evidenceDir));
        }
        $json = helper::jsonEncode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if (!is_string($json) || $json === '') {
            throw new RuntimeException(sprintf('failed to encode evidence json: %s', $name));
        }
        $file = $this->evidenceDir . '/' . $name;
        if (file_put_contents($file, $json) === false) {
            throw new RuntimeException(sprintf('failed to write evidence file: %s', $file));
        }
    }
}
