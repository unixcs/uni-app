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
            return static::updateBase(array_merge([
                'payload_snapshot' => helper::jsonEncode($mergedSnapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
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
            return static::updateBase([
                'notify_times' => (int)$tradeInfo['notify_times'] + 1,
                'last_notify_time' => time(),
                'payload_snapshot' => helper::jsonEncode($currentSnapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
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
