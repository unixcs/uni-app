<?php
// +----------------------------------------------------------------------
// | 商城系统 [ 致力于通过产品和服务，帮助商家高效化开拓市场 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2017~2025 https://www.example.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed 这不是一个自由软件，不允许对程序代码以任何形式任何目的的再发行
// +----------------------------------------------------------------------
// | Author: 项目团队 <admin@example.com>
// +----------------------------------------------------------------------
declare (strict_types=1);

namespace app\common\model;

use cores\BaseModel;
use cores\exception\BaseException;
use app\common\enum\payment\trade\TradeStatus as TradeStatusEnum;
use app\common\enum\payment\trade\ChannelClass as ChannelClassEnum;
use app\common\enum\order\refund\RefundStatus as RefundStatusEnum;
use app\common\library\helper;

/**
 * 模型类：第三方支付交易记录
 * Class PaymentTrade
 * @package app\common\model
 */
class PaymentTrade extends BaseModel
{
    private const PROVIDE_GOODS_RETRY_TTL = 300;

    // 定义表名
    protected $name = 'payment_trade';

    // 定义主键
    protected $pk = 'trade_id';

    /**
     * 交易记录详情
     * @param $where
     * @return static|array|null
     */
    public static function detail($where)
    {
        return static::get($where);
    }

    /**
     * 查询第三方支付交易记录详情
     * @param string $outTradeNo 交易订单号
     * @return static|array|null
     * @throws BaseException
     */
    public static function detailByOutTradeNo(string $outTradeNo)
    {
        $detail = static::detail(['out_trade_no' => $outTradeNo]);
        if (empty($detail)) {
            throwError("第三方支付交易记录不存在 {$outTradeNo}");
        }
        return $detail;
    }


    /**
     * 获取订单关联的虚拟支付交易列表（按最新优先）
     * @param int $orderId
     * @return \think\Collection
     */
    public static function getVirtualTradesByOrderId(int $orderId)
    {
        return (new static)
            ->where('order_id', '=', $orderId)
            ->where('platform', '=', 'wechat_virtual')
            ->order(['trade_id' => 'desc'])
            ->select();
    }

    /**
     * 获取订单最近一笔虚拟支付交易
     * @param int $orderId
     * @return static|array|null
     */
    public static function getLatestVirtualTradeByOrderId(int $orderId)
    {
        return (new static)
            ->where('order_id', '=', $orderId)
            ->where('platform', '=', 'wechat_virtual')
            ->order(['trade_id' => 'desc'])
            ->find();
    }

    /**
     * 为订单/退款上下文解析最相关的一笔虚拟支付交易。
     * 优先级：当前退款绑定的交易 > 订单当前 trade_id > 最近成功/已退款交易 > 最近一笔虚拟交易。
     * @param int $orderId
     * @param int $currentTradeId
     * @param int $orderRefundId
     * @return static|array|null
     */
    public static function resolveVirtualTradeForRefundContext(int $orderId, int $currentTradeId = 0, int $orderRefundId = 0)
    {
        if ($orderId <= 0) {
            return null;
        }
        $trades = static::getVirtualTradesByOrderId($orderId);
        if ($trades->isEmpty()) {
            return null;
        }
        if ($orderRefundId > 0) {
            foreach ($trades as $trade) {
                $snapshot = static::decodePayloadSnapshot((string)($trade['payload_snapshot'] ?? ''));
                if ((int)($snapshot['virtual_refund']['order_refund_id'] ?? 0) === $orderRefundId) {
                    return $trade;
                }
            }
        }
        if ($currentTradeId > 0) {
            foreach ($trades as $trade) {
                if ((int)$trade['trade_id'] === $currentTradeId) {
                    return $trade;
                }
            }
        }
        foreach ($trades as $trade) {
            if (in_array((int)($trade['trade_state'] ?? 0), [TradeStatusEnum::SUCCESS, TradeStatusEnum::REFUND], true)) {
                return $trade;
            }
        }
        return $trades[0] ?? null;
    }

    /**
     * 构建虚拟支付退款模式投影，供 API / 商家后台消费。
     * @param mixed $trade
     * @param array $refund
     * @return array
     */
    public static function buildVirtualRefundProjection($trade, array $refund = []): array
    {
        $projection = [
            'enabled' => false,
            'platform' => '',
            'ios_apple_refund_required' => false,
            'refund_entry_mode' => 'developer_refund',
            'refund_guidance' => '',
            'refund_display_state' => '',
            'refund_display_state_text' => '',
        ];
        if (empty($trade)) {
            return $projection;
        }
        $tradeData = is_array($trade) ? $trade : $trade->toArray();
        if ((string)($tradeData['platform'] ?? '') !== 'wechat_virtual') {
            return $projection;
        }
        $snapshot = static::decodePayloadSnapshot((string)($tradeData['payload_snapshot'] ?? ''));
        $projection['enabled'] = true;
        $projection['platform'] = 'wechat_virtual';
        if (!static::isIosAppleVirtualTrade($tradeData, $snapshot)) {
            return $projection;
        }
        $projection['ios_apple_refund_required'] = true;
        $projection['refund_entry_mode'] = 'app_store_guided';
        $refundStatus = (int)($refund['status'] ?? -1);
        $hasActiveLocalRefund = (int)($refund['order_refund_id'] ?? 0) > 0 && $refundStatus === RefundStatusEnum::NORMAL;
        $virtualRefund = (array)($snapshot['virtual_refund'] ?? []);
        if ($refundStatus === RefundStatusEnum::COMPLETED || (string)($virtualRefund['status'] ?? '') === 'completed') {
            $projection['refund_guidance'] = '退款已进入完成状态，最终到账时间以 Apple / 银行处理结果为准。';
            $projection['refund_display_state'] = 'refunded';
            $projection['refund_display_state_text'] = '已退款';
            return $projection;
        }
        if (!empty($snapshot['ios_refund_query_notify'])) {
            $projection['refund_guidance'] = 'Apple 已受理退款问询，退款结果以 Apple 审核为准。';
            $projection['refund_display_state'] = 'waiting_app_store_refund';
            $projection['refund_display_state_text'] = '等待 App Store 退款处理';
            return $projection;
        }
        if ((string)($virtualRefund['status'] ?? '') === 'waiting_ios_apple_refund' || $hasActiveLocalRefund) {
            $projection['refund_guidance'] = '本地退款申请已提交，请继续前往 Apple 官方渠道申请退款。';
            $projection['refund_display_state'] = 'local_refund_submitted';
            $projection['refund_display_state_text'] = '退款申请已提交，请前往 App Store 申请退款';
            return $projection;
        }
        if ($refundStatus === RefundStatusEnum::CANCELLED) {
            $projection['refund_guidance'] = '本地退款跟踪已取消。如仍需退款，请访问 reportaproblem.apple.com，登录购买时使用的 Apple 账户后重新申请。';
            $projection['refund_display_state'] = 'cancelled';
            $projection['refund_display_state_text'] = '已取消';
            return $projection;
        }
        if ($refundStatus === RefundStatusEnum::REJECTED) {
            $projection['refund_guidance'] = '本地退款跟踪已拒绝。如仍需退款，请核对服务履约情况后访问 reportaproblem.apple.com，登录购买时使用的 Apple 账户申请。';
            $projection['refund_display_state'] = 'rejected';
            $projection['refund_display_state_text'] = '退款已拒绝';
            return $projection;
        }
        $projection['refund_guidance'] = 'iOS 订单由 Apple 处理退款，商家无法直接原路退款。请访问 reportaproblem.apple.com，登录购买时使用的 Apple 账户，选择“请求退款”并提交对应项目。';
        $projection['refund_display_state'] = 'app_store_guided';
        $projection['refund_display_state_text'] = '请前往 App Store 申请退款';
        return $projection;
    }

    /**
     * 是否为 iOS Apple 虚拟支付订单。
     * query_order 中 order_type=7 表示 Apple 支付订单；若已收到 Apple 退款问询，也视为 Apple 订单。
     * @param mixed $trade
     * @param array $snapshot
     * @return bool
     */
    public static function isIosAppleVirtualTrade($trade, array $snapshot = []): bool
    {
        $tradeData = is_array($trade) ? $trade : $trade->toArray();
        if ((string)($tradeData['platform'] ?? '') !== 'wechat_virtual') {
            return false;
        }
        if ((int)($tradeData['channel_class'] ?? ChannelClassEnum::UNKNOWN) === ChannelClassEnum::IOS_APPLE) {
            return true;
        }
        if (empty($snapshot)) {
            $snapshot = static::decodePayloadSnapshot((string)($tradeData['payload_snapshot'] ?? ''));
        }
        if (!empty($snapshot['ios_refund_query_notify']) || !empty($snapshot['virtual_refund']['ios_refund_required'])) {
            return true;
        }
        foreach (static::getVirtualOrderTypeCandidates($snapshot) as $orderType) {
            if ($orderType === 7) {
                return true;
            }
        }
        return false;
    }

    /**
     * 提取虚拟支付查单/通知快照中的 order_type 候选值。
     * @param array $snapshot
     * @return array<int, int>
     */
    private static function getVirtualOrderTypeCandidates(array $snapshot): array
    {
        $candidates = [];
        $paths = [
            ['query_order', 'result', 'order', 'order_type'],
            ['query_order', 'order', 'order_type'],
            ['timer_query_order', 'result', 'order', 'order_type'],
            ['timer_query_order', 'order', 'order_type'],
            ['virtual_refund', 'query_result', 'order_type'],
            ['virtual_refund', 'final_query', 'order_type'],
            ['pay_notify', 'payload', 'order_type'],
            ['pay_notify', 'payload', 'OrderType'],
        ];
        foreach ($paths as $path) {
            $value = static::getNestedArrayValue($snapshot, $path);
            if ($value !== null && $value !== '') {
                $candidates[] = (int)$value;
            }
        }
        return $candidates;
    }

    /**
     * 读取多级数组值。
     * @param array $source
     * @param array $path
     * @return mixed|null
     */
    private static function getNestedArrayValue(array $source, array $path)
    {
        $cursor = $source;
        foreach ($path as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return null;
            }
            $cursor = $cursor[$segment];
        }
        return $cursor;
    }

    /**
     * 根据交易实现平台和已持久化快照计算渠道分类。
     *
     * 分类只能单向增强：IOS_APPLE 永不降级；NON_IOS 遇到 Apple 强证据可升级。
     * 空 platform 的历史记录缺少实现渠道证据，保持 UNKNOWN。
     *
     * @param string $platform
     * @param array $snapshot
     * @param int $currentClass
     * @return int
     */
    public static function classifyChannelClass(string $platform, array $snapshot, int $currentClass = ChannelClassEnum::UNKNOWN): int
    {
        if ($currentClass === ChannelClassEnum::IOS_APPLE) {
            return ChannelClassEnum::IOS_APPLE;
        }
        if ($platform !== 'wechat_virtual') {
            if ($currentClass === ChannelClassEnum::NON_IOS) {
                return ChannelClassEnum::NON_IOS;
            }
            return $platform !== '' ? ChannelClassEnum::NON_IOS : ChannelClassEnum::UNKNOWN;
        }
        if (!empty($snapshot['ios_refund_query_notify']) || !empty($snapshot['virtual_refund']['ios_refund_required'])) {
            return ChannelClassEnum::IOS_APPLE;
        }
        // order_type=0 是微信虚拟支付返回的有效非 iOS 类型，不能按“空值”过滤。
        $orderTypes = static::getVirtualOrderTypeCandidates($snapshot);
        if (in_array(7, $orderTypes, true)) {
            return ChannelClassEnum::IOS_APPLE;
        }
        if (!empty($orderTypes)) {
            return ChannelClassEnum::NON_IOS;
        }
        return $currentClass === ChannelClassEnum::NON_IOS
            ? ChannelClassEnum::NON_IOS
            : ChannelClassEnum::UNKNOWN;
    }

    /**
     * 在交易行锁内按当前完整快照重新推进渠道分类。
     * @param int $tradeId
     * @return bool
     */
    public static function refreshChannelClass(int $tradeId): bool
    {
        return static::withLockedTrade($tradeId, function ($tradeInfo, array $snapshot) use ($tradeId) {
            $currentClass = (int)($tradeInfo['channel_class'] ?? ChannelClassEnum::UNKNOWN);
            $nextClass = static::classifyChannelClass((string)($tradeInfo['platform'] ?? ''), $snapshot, $currentClass);
            if ($nextClass === $currentClass) {
                return true;
            }
            return static::updateBase(['channel_class' => $nextClass], $tradeId);
        });
    }

    /**
     * 将第三方交易记录更新为已支付状态
     * @param int $tradeId 交易记录ID
     * @param string $tradeNo 第三方交易流水号
     * @return bool
     */
    public static function updateToPaySuccess(int $tradeId, string $tradeNo): bool
    {
        return static::updateBase([
            'trade_no' => $tradeNo,
            'trade_state' => TradeStatusEnum::SUCCESS
        ], $tradeId);
    }

    /**
     * 将第三方交易记录更新为已退款状态
     * @param int $tradeId 交易记录ID
     * @return bool
     */
    public static function updateToRefund(int $tradeId): bool
    {
        return static::updateBase(['trade_state' => TradeStatusEnum::REFUND], $tradeId);
    }

    /**
     * 解析快照报文
     * @param string|null $payloadSnapshot
     * @return array
     */
    public static function decodePayloadSnapshot(?string $payloadSnapshot): array
    {
        $payloadSnapshot = (string)$payloadSnapshot;
        if ($payloadSnapshot === '') {
            return [];
        }
        $decoded = helper::jsonDecode($payloadSnapshot);
        if (is_array($decoded)) {
            return $decoded;
        }
        return ['raw' => $payloadSnapshot];
    }

    /**
     * 合并交易快照
     * @param int $tradeId
     * @param array $snapshot
     * @param array $extra
     * @return bool
     */
    public static function mergePayloadSnapshot(int $tradeId, array $snapshot, array $extra = []): bool
    {
        return static::withLockedTrade($tradeId, function ($tradeInfo, array $currentSnapshot) use ($tradeId, $snapshot, $extra) {
            $mergedSnapshot = array_replace_recursive($currentSnapshot, $snapshot);
            $currentClass = (int)($tradeInfo['channel_class'] ?? ChannelClassEnum::UNKNOWN);
            $nextClass = static::classifyChannelClass((string)($tradeInfo['platform'] ?? ''), $mergedSnapshot, $currentClass);
            return static::updateBase(array_merge([
                'payload_snapshot' => helper::jsonEncode($mergedSnapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'channel_class' => $nextClass,
            ], $extra), $tradeId);
        });
    }

    /**
     * 原子记录通知快照及次数
     * @param int $tradeId
     * @param string $snapshotKey
     * @param string $event
     * @param array $payload
     * @return bool
     */
    public static function recordNotify(int $tradeId, string $snapshotKey, string $event, array $payload): bool
    {
        return static::withLockedTrade($tradeId, function ($tradeInfo, array $currentSnapshot) use ($tradeId, $snapshotKey, $event, $payload) {
            $currentSnapshot[$snapshotKey] = [
                'event' => $event,
                'received_at' => time(),
                'payload' => $payload,
            ];
            $currentClass = (int)($tradeInfo['channel_class'] ?? ChannelClassEnum::UNKNOWN);
            $nextClass = static::classifyChannelClass((string)($tradeInfo['platform'] ?? ''), $currentSnapshot, $currentClass);
            return static::updateBase([
                'notify_times' => (int)$tradeInfo['notify_times'] + 1,
                'last_notify_time' => time(),
                'payload_snapshot' => helper::jsonEncode($currentSnapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'channel_class' => $nextClass,
            ], $tradeId);
        });
    }

    /**
     * 抢占虚拟支付履约通知发送权，避免重复发送
     * @param int $tradeId
     * @param string $source
     * @return array{claimed:bool,state:string,snapshot:array}
     */
    public static function claimProvideGoodsDispatch(int $tradeId, string $source): array
    {
        return static::withLockedTrade($tradeId, function ($tradeInfo, array $currentSnapshot) use ($tradeId, $source) {
            $provideGoods = (array)($currentSnapshot['provide_goods'] ?? []);
            $status = (string)($provideGoods['status'] ?? '');
            $startedAt = (int)($provideGoods['dispatch_started_at'] ?? 0);
            $canRecoverSending = $status === 'sending' && $startedAt > 0 && $startedAt <= (time() - self::PROVIDE_GOODS_RETRY_TTL);
            if (\in_array($status, ['success'], true) || ($status === 'sending' && !$canRecoverSending)) {
                return [
                    'claimed' => false,
                    'state' => $status,
                    'snapshot' => $currentSnapshot,
                ];
            }
            $currentSnapshot['provide_goods'] = array_merge($provideGoods, [
                'status' => 'sending',
                'dispatch_source' => $source,
                'dispatch_started_at' => time(),
            ]);
            static::updateBase([
                'payload_snapshot' => helper::jsonEncode($currentSnapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ], $tradeId);
            return [
                'claimed' => true,
                'state' => $status,
                'snapshot' => $currentSnapshot,
            ];
        });
    }

    /**
     * 标记虚拟支付履约通知发送结果
     * @param int $tradeId
     * @param string $status
     * @param array $data
     * @return bool
     */
    public static function finishProvideGoodsDispatch(int $tradeId, string $status, array $data = []): bool
    {
        return static::withLockedTrade($tradeId, function ($tradeInfo, array $currentSnapshot) use ($tradeId, $status, $data) {
            $currentSnapshot['provide_goods'] = array_merge(
                (array)($currentSnapshot['provide_goods'] ?? []),
                $data,
                [
                    'status' => $status,
                    'at' => time(),
                ]
            );
            return static::updateBase([
                'payload_snapshot' => helper::jsonEncode($currentSnapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ], $tradeId);
        });
    }

    /**
     * 在加锁事务中操作交易记录
     * @param int $tradeId
     * @param callable $handler
     * @return mixed
     */
    private static function withLockedTrade(int $tradeId, callable $handler)
    {
        return (new static)->transaction(function () use ($tradeId, $handler) {
            $tradeInfo = (new static)->where('trade_id', '=', $tradeId)->lock(true)->find();
            if (empty($tradeInfo)) {
                return false;
            }
            return $handler($tradeInfo, static::decodePayloadSnapshot((string)$tradeInfo['payload_snapshot']));
        });
    }
}
