<?php
declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use app\common\enum\order\iosRefund\RiskSource;
use app\common\enum\order\iosRefund\RiskStatus;
use app\common\enum\Setting as SettingEnum;
use app\common\enum\order\refund\AuditStatus;
use app\common\enum\order\refund\RefundStatus;
use app\common\enum\order\refund\RefundType;
use app\api\model\OrderRefund as ApiOrderRefund;
use app\api\model\User as ApiUser;
use app\api\service\User as ApiUserService;
use app\common\model\Order;
use app\common\model\OrderGoods;
use app\common\model\OrderRefund;
use app\common\model\PaymentIosRefundInquiry;
use app\common\model\PaymentTrade;
use app\common\model\store\Setting as StoreSettingModel;
use app\common\library\helper;
use app\api\service\Notify as NotifyService;
use app\api\model\wxapp\Setting as WxappSettingModel;
use app\common\service\order\IosRefundRisk;
use app\common\service\order\Refund as RefundService;
use app\store\model\Order as StoreOrder;
use app\store\model\OrderRefund as StoreOrderRefund;
use cores\BaseModel;
use think\App;
use think\facade\Db;
use EasyWeChat\Kernel\Encryptor as WechatEncryptor;

(new App())->initialize();

function expectSame($expected, $actual, string $case): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf('%s expected=%s actual=%s', $case, var_export($expected, true), var_export($actual, true)));
    }
    fwrite(STDOUT, "ok {$case}\n");
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
$order = Order::detail((int)$trade['order_id']);
$refund = (new OrderRefund)
    ->where('order_id', '=', (int)$order['order_id'])
    ->where('type', '=', 30)
    ->order(['order_refund_id' => 'desc'])
    ->find();
if (empty($order) || empty($refund) || (int)$order['trade_id'] !== (int)$trade['trade_id']) {
    throw new RuntimeException('no bound iOS order/refund fixture available');
}

Db::startTrans();
try {
    expectSame(true, RefundService::isSuccessfulVirtualRefundNotify(['RetCode' => 0]), 'integer zero is trusted refund success');
    expectSame(true, RefundService::isSuccessfulVirtualRefundNotify(['RetCode' => '0']), 'string zero is trusted refund success');
    foreach (['FAIL', 'SUCCESS', '00', '', null, false] as $invalidRetCode) {
        expectSame(false, RefundService::isSuccessfulVirtualRefundNotify(['RetCode' => $invalidRetCode]), 'malformed RetCode fails closed');
    }

    // Exercise the real API apply path: create refund + NONE -> LOCKED must be atomic.
    BaseModel::$storeId = (int)$order['store_id'];
    $loginUser = ApiUser::detail((int)$order['user_id']);
    if (empty($loginUser)) {
        throw new RuntimeException('no API user fixture available');
    }
    $currentUser = new ReflectionProperty(ApiUserService::class, 'currentLoginUser');
    $currentUser->setAccessible(true);
    $currentUser->setValue(null, $loginUser);
    $goods = (new OrderGoods)
        ->where('order_id', '=', (int)$order['order_id'])
        ->order(['order_goods_id' => 'asc'])
        ->find();
    if (empty($goods)) {
        throw new RuntimeException('no order goods fixture available');
    }

    // Keep the legacy fixture row for rollback/history, but remove it from service-refund uniqueness checks.
    $refund->save(['type' => RefundType::RETURN, 'status' => RefundStatus::REJECTED]);
    $order->save([
        'ios_refund_risk_status' => RiskStatus::NONE,
        'ios_refund_risk_source' => '',
        'ios_refund_risk_time' => 0,
        'order_status' => 30,
        'delivery_status' => 20,
        'receipt_status' => 20,
    ]);
    $completedApplyRejected = false;
    try {
        (new ApiOrderRefund)->apply((int)$goods['order_goods_id'], [
            'content' => 'IOS_REFUND_CONTRACT_COMPLETED_APPLY',
        ]);
    } catch (Throwable $e) {
        $completedApplyRejected = true;
    }
    expectSame(true, $completedApplyRejected, 'completed service rejects local refund apply');
    expectSame(0, (new OrderRefund)
        ->where('order_id', '=', (int)$order['order_id'])
        ->where('type', '=', RefundType::SERVICE)
        ->count(), 'completed apply creates no service refund');

    $order = Order::detail((int)$order['order_id']);
    $order->save([
        'order_status' => 10,
        'delivery_status' => 10,
        'receipt_status' => 10,
    ]);
    $applied = (new ApiOrderRefund)->apply((int)$goods['order_goods_id'], [
        'content' => 'IOS_REFUND_CONTRACT_LOCAL_APPLY',
    ]);
    expectSame(true, (bool)$applied, 'real iOS local apply succeeds');
    $appliedOrder = Order::detail((int)$order['order_id']);
    $refund = (new OrderRefund)
        ->where('order_id', '=', (int)$order['order_id'])
        ->where('type', '=', RefundType::SERVICE)
        ->where('apply_desc', '=', 'IOS_REFUND_CONTRACT_LOCAL_APPLY')
        ->find();
    expectSame(true, !empty($refund), 'real iOS local apply creates one service refund');
    expectSame(RiskStatus::LOCKED, (int)$appliedOrder['ios_refund_risk_status'], 'real iOS local apply locks service atomically');
    expectSame(AuditStatus::REVIEWED, (int)$refund['audit_status'], 'not-started iOS local apply is auto-reviewed');

    $repeatApplyRejected = false;
    try {
        (new ApiOrderRefund)->apply((int)$goods['order_goods_id'], [
            'content' => 'IOS_REFUND_CONTRACT_REPEAT_APPLY',
        ]);
    } catch (Throwable $e) {
        $repeatApplyRejected = true;
    }
    expectSame(true, $repeatApplyRejected, 'LOCKED iOS order rejects repeat local apply');
    expectSame(1, (new OrderRefund)
        ->where('order_id', '=', (int)$order['order_id'])
        ->where('type', '=', RefundType::SERVICE)
        ->count(), 'repeat local apply does not create a second refund');

    $service = new IosRefundRisk;
    $notStartedPayload = [
        'Event' => 'xpay_subscribe_ios_refund_query_notify',
        'pay_order_id' => (string)$trade['out_trade_no'],
        'refund_request_reason' => 'CONTRACT_NOT_STARTED',
    ];
    $notStartedInquiry = $service->handleInquiry((string)$trade['out_trade_no'], $notStartedPayload);
    expectSame(0, (int)$notStartedInquiry['result_code'], 'not-started REVIEWED inquiry recommends refund');

    $trade->save(['trade_state' => 10]);
    $invalidTradeReason = 'CONTRACT_INVALID_TRADE_STATE';
    $invalidTradeInquiry = $service->handleInquiry((string)$trade['out_trade_no'], array_merge($notStartedPayload, [
        'refund_request_reason' => $invalidTradeReason,
    ]));
    expectSame(1, (int)$invalidTradeInquiry['result_code'], 'unpaid final trade inquiry fails closed');
    expectSame(0, (new PaymentIosRefundInquiry)
        ->where('order_id', '=', (int)$order['order_id'])
        ->where('request_reason', '=', $invalidTradeReason)
        ->count(), 'binding failure does not pollute business order timeline');
    expectSame(1, (new PaymentIosRefundInquiry)
        ->where('order_id', '=', 0)
        ->where('request_reason', '=', $invalidTradeReason)
        ->count(), 'binding failure is retained as zero-order security audit');
    $trade = PaymentTrade::detail((int)$trade['trade_id']);
    $trade->save(['trade_state' => 20]);

    // Continue with the in-progress inquiry matrix using the refund created by the real apply path.
    $order = Order::detail((int)$order['order_id']);
    $order->save([
        'order_status' => 10,
        'delivery_status' => 20,
        'receipt_status' => 10,
    ]);
    $refund->save(['status' => RefundStatus::NORMAL, 'audit_status' => AuditStatus::WAIT]);
    $payload = [
        'Event' => 'xpay_subscribe_ios_refund_query_notify',
        'pay_order_id' => (string)$trade['out_trade_no'],
        'refund_request_reason' => 'CONTRACT_TEST',
    ];
    $first = $service->handleInquiry((string)$trade['out_trade_no'], $payload);
    expectSame(1, (int)$first['result_code'], 'WAIT recommends rejection');

    $refund = OrderRefund::detail((int)$refund['order_refund_id']);
    $refund->save(['audit_status' => AuditStatus::REVIEWED]);
    $second = $service->handleInquiry((string)$trade['out_trade_no'], $payload);
    expectSame(0, (int)$second['result_code'], 'same payload recomputes after REVIEWED');
    expectSame(true, trim((string)$second['evidence']) !== '', 'allow response has evidence');
    $third = $service->handleInquiry((string)$trade['out_trade_no'], $payload);
    expectSame(0, (int)$third['result_code'], 'third identical inquiry keeps latest REVIEWED decision');

    $attempts = (new PaymentIosRefundInquiry)
        ->where('order_id', '=', (int)$order['order_id'])
        ->where('request_reason', '=', 'CONTRACT_TEST')
        ->count();
    expectSame(3, $attempts, 'every authenticated retry is retained');
    $refundCount = (new OrderRefund)
        ->where('order_id', '=', (int)$order['order_id'])
        ->where('type', '=', 30)
        ->count();
    expectSame(1, $refundCount, 'retries do not create duplicate refund rows');

    $refund = OrderRefund::detail((int)$refund['order_refund_id']);
    $refund->save(['status' => RefundStatus::REJECTED, 'audit_status' => AuditStatus::REJECTED]);
    $rejectedInquiry = $service->handleInquiry((string)$trade['out_trade_no'], array_merge($payload, [
        'refund_request_reason' => 'CONTRACT_REJECTED',
    ]));
    expectSame(1, (int)$rejectedInquiry['result_code'], 'in-progress REJECTED inquiry recommends rejection');

    $completedOrder = Order::detail((int)$order['order_id']);
    $completedOrder->save(['order_status' => 30, 'delivery_status' => 20, 'receipt_status' => 20]);
    $completedInquiry = $service->handleInquiry((string)$trade['out_trade_no'], array_merge($payload, [
        'refund_request_reason' => 'CONTRACT_COMPLETED',
    ]));
    expectSame(1, (int)$completedInquiry['result_code'], 'completed service inquiry recommends rejection');
    expectSame(30, (int)Order::detail((int)$order['order_id'])['order_status'], 'completed inquiry does not regress service state');

    $order = Order::detail((int)$order['order_id']);
    $order->save(['order_status' => 10, 'delivery_status' => 20, 'receipt_status' => 10]);
    $refund = OrderRefund::detail((int)$refund['order_refund_id']);
    $refund->save(['status' => RefundStatus::NORMAL, 'audit_status' => AuditStatus::REVIEWED]);

    BaseModel::$storeId = (int)$order['store_id'];
    $lockedOrder = Order::detail((int)$order['order_id']);
    $lockedOrder->save(['delivery_status' => 10, 'receipt_status' => 10, 'order_status' => 10]);
    $storeOrder = StoreOrder::detail((int)$order['order_id']);
    expectSame(false, $storeOrder->startService(), 'LOCKED backend rejects startService');
    expectSame(10, (int)Order::detail((int)$order['order_id'])['delivery_status'], 'rejected start leaves state unchanged');

    $lockedOrder = Order::detail((int)$order['order_id']);
    $lockedOrder->save(['delivery_status' => 20, 'receipt_status' => 10, 'order_status' => 10]);
    $storeOrder = StoreOrder::detail((int)$order['order_id']);
    expectSame(false, $storeOrder->completeService(), 'LOCKED backend rejects completeService');
    expectSame(10, (int)Order::detail((int)$order['order_id'])['receipt_status'], 'rejected completion leaves state unchanged');

    $refund = OrderRefund::detail((int)$refund['order_refund_id']);
    $refund->save(['status' => RefundStatus::NORMAL, 'audit_status' => AuditStatus::WAIT]);
    $auditRejected = false;
    try {
        $storeRefund = StoreOrderRefund::detail((int)$refund['order_refund_id']);
        $storeRefund->audit(['audit_status' => AuditStatus::REJECTED, 'refuse_desc' => 'contract test']);
    } catch (Throwable $e) {
        $auditRejected = true;
    }
    expectSame(true, $auditRejected, 'merchant cannot reject after a suggest-refund inquiry');

    $lockedOrder = Order::detail((int)$order['order_id']);
    $lockedOrder->save([
        'order_status' => 10,
        'delivery_status' => 20,
        'receipt_status' => 10,
        'ios_refund_risk_status' => RiskStatus::LOCKED,
    ]);
    $refund = OrderRefund::detail((int)$refund['order_refund_id']);
    $refund->save(['status' => RefundStatus::REJECTED, 'audit_status' => AuditStatus::REJECTED, 'refuse_desc' => 'merchant rejected']);
    $trade->save(['trade_state' => 20]);
    $untrusted = (new RefundService)->finalizeVirtualRefundByTrade((int)$trade['trade_id'], [
        'Event' => 'xpay_refund_notify',
        'RetCode' => 'FAIL',
        'MchOrderId' => (string)$trade['out_trade_no'],
    ]);
    expectSame('untrusted_refund_notify', (string)$untrusted['status'], 'malformed refund notification cannot finalize');
    expectSame(10, (int)Order::detail((int)$order['order_id'])['order_status'], 'malformed refund notification leaves order open');

    $finalized = (new RefundService)->finalizeVirtualRefundByTrade((int)$trade['trade_id'], [
        'Event' => 'xpay_refund_notify',
        'RetCode' => 0,
        'MchOrderId' => (string)$trade['out_trade_no'],
    ]);
    expectSame('completed', (string)$finalized['status'], 'trusted Apple refund success converges');
    $finalOrder = Order::detail((int)$order['order_id']);
    $finalRefund = OrderRefund::detail((int)$refund['order_refund_id']);
    $finalTrade = PaymentTrade::detail((int)$trade['trade_id']);
    expectSame(RiskStatus::REFUNDED, (int)$finalOrder['ios_refund_risk_status'], 'refund success advances risk to REFUNDED');
    expectSame(20, (int)$finalOrder['order_status'], 'refund success closes order');
    expectSame(20, (int)$finalRefund['status'], 'refund success completes tracking row');
    expectSame(AuditStatus::REJECTED, (int)$finalRefund['audit_status'], 'refund success preserves merchant review history');
    expectSame(30, (int)$finalTrade['trade_state'], 'refund success completes final trade');
    expectSame(20, (int)$finalOrder['delivery_status'], 'refund success preserves service-start history');
    $duplicateFinalized = (new RefundService)->finalizeVirtualRefundByTrade((int)$trade['trade_id'], [
        'Event' => 'xpay_refund_notify',
        'RetCode' => 0,
        'MchOrderId' => (string)$trade['out_trade_no'],
    ]);
    expectSame('completed', (string)$duplicateFinalized['status'], 'duplicate trusted refund notification is idempotent');
    expectSame(1, (new OrderRefund)
        ->where('order_id', '=', (int)$order['order_id'])
        ->where('type', '=', RefundType::SERVICE)
        ->count(), 'duplicate refund notification keeps one service refund');

    $missing = $service->handleInquiry('missing-contract-trade', array_merge($payload, ['pay_order_id' => 'missing-contract-trade']));
    expectSame(1, (int)$missing['result_code'], 'missing trade fails closed');

    $projection = IosRefundRisk::buildProjection($order, $trade, $refund, []);
    expectSame(false, (bool)$projection['can_cancel'], 'iOS refund cannot be cancelled');
    expectSame(true, (bool)$projection['ios_apple_refund_required'], 'iOS projection is explicit');

    // Exercise the real Notify outer path with a locally authenticated plaintext payload.
    // This verifies body parsing, source signature verification, event dispatch and Apple response shape;
    // encrypted production callbacks remain covered by the external WeChat credential gate.
    $order = Order::detail((int)$order['order_id']);
    $order->save([
        'order_status' => 10,
        'delivery_status' => 10,
        'receipt_status' => 10,
        'ios_refund_risk_status' => RiskStatus::LOCKED,
    ]);
    $refund = OrderRefund::detail((int)$refund['order_refund_id']);
    $refund->save(['status' => RefundStatus::NORMAL, 'audit_status' => AuditStatus::REVIEWED]);
    $notifyPayload = [
        'Event' => 'xpay_subscribe_ios_refund_query_notify',
        'pay_order_id' => (string)$trade['out_trade_no'],
        'refund_request_reason' => 'CONTRACT_NOTIFY_ENTRY',
    ];
    $pushConfig = StoreSettingModel::getItem(SettingEnum::VIRTUAL_PAYMENT, (int)$order['store_id']);
    $timestamp = (string)time();
    $nonce = 'contract-notify-nonce';
    $signatureParts = [
        trim((string)($pushConfig['message_push_token'] ?? '')),
        $timestamp,
        $nonce,
    ];
    sort($signatureParts, SORT_STRING);
    $request = request();
    $mergeParam = new ReflectionProperty($request, 'mergeParam');
    $mergeParam->setAccessible(true);
    $mergeParam->setValue($request, false);
    $param = new ReflectionProperty($request, 'param');
    $param->setAccessible(true);
    $param->setValue($request, []);
    $request
        ->withHeader(['content-type' => 'application/json'])
        ->withGet([
            'signature' => sha1(implode($signatureParts)),
            'timestamp' => $timestamp,
            'nonce' => $nonce,
        ])
        ->withInput(json_encode($notifyPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $notifyResponse = json_decode((new NotifyService)->virtualPayment(), true);
    expectSame(0, (int)($notifyResponse['result_code'] ?? -1), 'Notify Apple inquiry returns allow decision');
    expectSame(true, isset($notifyResponse['result_info'], $notifyResponse['evidence']), 'Notify Apple inquiry response has official fields');
    expectSame(1, (new PaymentIosRefundInquiry)
        ->where('order_id', '=', (int)$order['order_id'])
        ->where('request_reason', '=', 'CONTRACT_NOTIFY_ENTRY')
        ->count(), 'Notify Apple inquiry persists one authenticated event');

    $mergeParam->setValue($request, false);
    $param->setValue($request, []);
    $request->withGet([
        'signature' => 'invalid-signature',
        'timestamp' => $timestamp,
        'nonce' => $nonce,
    ]);
    $invalidNotifyResponse = json_decode((new NotifyService)->virtualPayment(), true);
    expectSame(1, (int)($invalidNotifyResponse['ErrCode'] ?? -1), 'Notify rejects invalid source signature');

    // Safe-mode response contract: a non-empty iOS decision must be encrypted back with
    // the same EncodingAESKey, while the business payload remains auditable after decrypt.
    $appId = (string)(WxappSettingModel::getConfigBasic()['app_id'] ?? '');
    $aesKey = trim((string)($pushConfig['message_push_encoding_aes_key'] ?? ''));
    $token = trim((string)($pushConfig['message_push_token'] ?? ''));
    if ($appId === '' || $aesKey === '' || $token === '') {
        throw new RuntimeException('safe-mode Notify contract configuration is incomplete');
    }
    $safeTimestamp = time();
    $safeNonce = 'contract-safe-mode-nonce';
    $safePayload = [
        'Event' => 'xpay_subscribe_ios_refund_query_notify',
        'pay_order_id' => 'contract-safe-mode-unknown-trade',
        'refund_request_reason' => 'CONTRACT_NOTIFY_SAFE_MODE',
    ];
    $safeEncryptor = new WechatEncryptor($appId, $token, $aesKey);
    $safeOuterXml = $safeEncryptor->encrypt(
        json_encode($safePayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $safeNonce,
        $safeTimestamp
    );
    $safeOuter = (array)(helper::xmlToArray($safeOuterXml) ?: []);
    $mergeParam->setValue($request, false);
    $param->setValue($request, []);
    $request
        ->withHeader(['content-type' => 'application/xml'])
        ->withGet([
            'msg_signature' => (string)($safeOuter['MsgSignature'] ?? ''),
            'timestamp' => (string)$safeTimestamp,
            'nonce' => $safeNonce,
        ])
        ->withInput($safeOuterXml);
    $safeResponseXml = (new NotifyService)->virtualPayment();
    $safeResponse = (array)(helper::xmlToArray($safeResponseXml) ?: []);
    expectSame(true, trim((string)($safeResponse['Encrypt'] ?? '')) !== '', 'safe-mode Notify response is encrypted');
    expectSame(true, trim((string)($safeResponse['MsgSignature'] ?? '')) !== '', 'safe-mode Notify response has signature');
    $safePlainResponse = $safeEncryptor->decrypt(
        (string)$safeResponse['Encrypt'],
        (string)$safeResponse['MsgSignature'],
        (string)$safeResponse['Nonce'],
        (string)$safeResponse['TimeStamp']
    );
    $safeDecodedResponse = (array)(helper::xmlToArray($safePlainResponse) ?: []);
    expectSame(1, (int)($safeDecodedResponse['result_code'] ?? -1), 'safe-mode response preserves fail-closed decision');
    expectSame(true, trim((string)($safeDecodedResponse['evidence'] ?? '')) !== '', 'safe-mode response preserves evidence');

    // Repeat the same authenticated safe-mode request with JSON framing; the response
    // wrapper must stay JSON while its business payload remains encrypted.
    $mergeParam->setValue($request, false);
    $param->setValue($request, []);
    $request
        ->withHeader(['content-type' => 'application/json'])
        ->withGet([
            'msg_signature' => (string)($safeOuter['MsgSignature'] ?? ''),
            'timestamp' => (string)$safeTimestamp,
            'nonce' => $safeNonce,
        ])
        ->withInput(helper::jsonEncode(['Encrypt' => (string)($safeOuter['Encrypt'] ?? '')]));
    $safeJsonResponse = (array)(json_decode((new NotifyService)->virtualPayment(), true) ?: []);
    expectSame(true, trim((string)($safeJsonResponse['Encrypt'] ?? '')) !== '', 'safe-mode JSON response is encrypted');
    $safeJsonPlainResponse = $safeEncryptor->decrypt(
        (string)$safeJsonResponse['Encrypt'],
        (string)$safeJsonResponse['MsgSignature'],
        (string)$safeJsonResponse['Nonce'],
        (string)$safeJsonResponse['TimeStamp']
    );
    $safeJsonDecodedResponse = (array)(json_decode($safeJsonPlainResponse, true) ?: []);
    expectSame(1, (int)($safeJsonDecodedResponse['result_code'] ?? -1), 'safe-mode JSON preserves decision');

    // Exercise the real Notify outer refund-success dispatch, not just the domain finalizer.
    $order = Order::detail((int)$order['order_id']);
    $order->save([
        'order_status' => 10,
        'pay_status' => 20,
        'delivery_status' => 20,
        'receipt_status' => 10,
        'ios_refund_risk_status' => RiskStatus::LOCKED,
    ]);
    $refund = OrderRefund::detail((int)$refund['order_refund_id']);
    $refund->save(['status' => RefundStatus::NORMAL, 'audit_status' => AuditStatus::REJECTED]);
    $trade = PaymentTrade::detail((int)$trade['trade_id']);
    $trade->save(['trade_state' => 20]);
    $refundNotifyPayload = [
        'Event' => 'xpay_refund_notify',
        'RetCode' => 0,
        'MchOrderId' => (string)$trade['out_trade_no'],
    ];
    $refundTimestamp = (string)time();
    $refundNonce = 'contract-refund-notify-nonce';
    $refundSignatureParts = [$token, $refundTimestamp, $refundNonce];
    sort($refundSignatureParts, SORT_STRING);
    $mergeParam->setValue($request, false);
    $param->setValue($request, []);
    $request
        ->withHeader(['content-type' => 'application/json'])
        ->withGet([
            'signature' => sha1(implode($refundSignatureParts)),
            'timestamp' => $refundTimestamp,
            'nonce' => $refundNonce,
        ])
        ->withInput(json_encode($refundNotifyPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $outerRefundResponse = json_decode((new NotifyService)->virtualPayment(), true);
    expectSame(0, (int)($outerRefundResponse['ErrCode'] ?? -1), 'Notify refund success returns success');
    expectSame(RiskStatus::REFUNDED, (int)Order::detail((int)$order['order_id'])['ios_refund_risk_status'], 'Notify refund success advances risk');
    expectSame(30, (int)PaymentTrade::detail((int)$trade['trade_id'])['trade_state'], 'Notify refund success advances trade');
    expectSame(20, (int)OrderRefund::detail((int)$refund['order_refund_id'])['status'], 'Notify refund success completes refund row');

    $mergeParam->setValue($request, false);
    $param->setValue($request, []);
    $request->withGet([
        'signature' => sha1(implode($refundSignatureParts)),
        'timestamp' => $refundTimestamp,
        'nonce' => $refundNonce,
    ]);
    $duplicateOuterRefundResponse = json_decode((new NotifyService)->virtualPayment(), true);
    expectSame(0, (int)($duplicateOuterRefundResponse['ErrCode'] ?? -1), 'duplicate Notify refund success remains idempotent');
    expectSame(1, (new OrderRefund)
        ->where('order_id', '=', (int)$order['order_id'])
        ->where('type', '=', RefundType::SERVICE)
        ->count(), 'duplicate Notify refund keeps one service refund');
} finally {
    Db::rollback();
}

fwrite(STDOUT, "PASS iOS refund review/lock contracts\n");
