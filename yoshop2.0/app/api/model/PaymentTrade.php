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

namespace app\api\model;

use app\common\model\PaymentTrade as PaymentTradeModel;
use app\common\enum\payment\trade\TradeStatus as TradeStatusEnum;
use app\common\enum\payment\trade\ChannelClass as ChannelClassEnum;
use cores\exception\BaseException;
use app\common\library\helper;
use think\facade\Db;

/**
 * 模型类：第三方支付交易记录
 * Class PaymentTrade
 * @package app\api\model
 */
class PaymentTrade extends PaymentTradeModel
{
    /**
     * 隐藏字段
     * @var array
     */
    protected $hidden = [
        'store_id',
        'create_time',
        'update_time'
    ];

    /**
     * 新增第三方交易信息
     * @param mixed $orderInfo 订单信息
     * @param string $method 支付方式
     * @param string $client 指定的客户端
     * @param int $orderType 订单类型
     * @param array $payment 第三方支付数据
     * @return bool
     * @throws \cores\exception\BaseException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public static function record($orderInfo, string $method, string $client, int $orderType, array $payment): bool
    {
        return Db::transaction(function () use ($orderInfo, $method, $client, $orderType, $payment) {
            $model = new static;
            // 与通知/查单更新共享 payment_trade 行锁，防止旧读覆盖 Apple 强证据。
            $record = $model->where([
                'order_id' => (int)$orderInfo['order_id'],
                'pay_method' => $method,
                'client' => $client,
                'order_type' => $orderType,
                'store_id' => self::$storeId,
            ])->order(['trade_id' => 'desc'])->lock(true)->find();
            $outTradeNo = (string)($payment['out_trade_no'] ?? '');
            if ($record && in_array((int)$record['trade_state'], [TradeStatusEnum::SUCCESS, TradeStatusEnum::REFUND], true)) {
                throwError('该支付订单已完成交易');
            }
            if ($record && $model->shouldCreateNewAttempt($record, $outTradeNo, $payment)) {
                $existingPlatform = (string)($record['platform'] ?? '');
                $incomingPlatform = (string)($payment['platform'] ?? '');
                if (($existingPlatform === 'wechat_virtual' || $incomingPlatform === 'wechat_virtual')
                    && (int)$record['trade_state'] === TradeStatusEnum::UNPAID) {
                    throwError('支付结果确认中，请勿重复支付');
                }
                // CLOSED 代表旧尝试已明确终态未支付，允许保留一条新的虚拟支付尝试。
                if ((int)$record['trade_state'] !== TradeStatusEnum::CLOSED
                    && ($existingPlatform === 'wechat_virtual' || $incomingPlatform === 'wechat_virtual')) {
                    throwError('上一笔支付尚未结束，请勿重复支付');
                }
                $record = null;
            }
            $platform = (string)($payment['platform'] ?? '');
            $incomingSnapshot = PaymentTradeModel::decodePayloadSnapshot((string)($payment['payload_snapshot'] ?? ''));
            $currentSnapshot = $record
                ? PaymentTradeModel::decodePayloadSnapshot((string)($record['payload_snapshot'] ?? ''))
                : [];
            $snapshot = array_replace_recursive($currentSnapshot, $incomingSnapshot);
            $currentClass = $record ? (int)($record['channel_class'] ?? ChannelClassEnum::UNKNOWN) : ChannelClassEnum::UNKNOWN;
            $channelClass = $platform === 'wechat_virtual'
                ? PaymentTradeModel::classifyChannelClass($platform, $snapshot, $currentClass)
                : ChannelClassEnum::NON_IOS;

            return ($record ?: $model)->save([
                'client' => $client,
                'pay_method' => $method,
                'order_type' => $orderType,
                'order_id' => $orderInfo['order_id'],
                'order_no' => $orderInfo['order_no'],
                'user_id' => $orderInfo['user_id'],
                'out_trade_no' => $outTradeNo,
                'prepay_id' => $payment['prepay_id'] ?? '',
                'trade_state' => TradeStatusEnum::UNPAID,
                'platform' => $platform,
                'channel_class' => $channelClass,
                'env' => isset($payment['env']) ? (int)$payment['env'] : 0,
                'product_id' => $payment['product_id'] ?? '',
                'goods_price' => isset($payment['goods_price']) ? (int)$payment['goods_price'] : 0,
                'attach' => $payment['attach'] ?? '',
                'notify_times' => isset($payment['notify_times']) ? (int)$payment['notify_times'] : 0,
                'last_notify_time' => isset($payment['last_notify_time']) ? (int)$payment['last_notify_time'] : 0,
                'payload_snapshot' => helper::jsonEncode($snapshot),
                'store_id' => self::$storeId,
            ]);
        });
    }

    /**
     * 根据订单ID查询记录
     * @param int $orderId 订单ID
     * @param int $orderType 订单类型
     * @return static|array|\think\Model|null
     * @throws BaseException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    private function detailByOrderId(int $orderId, string $method, string $client, int $orderType)
    {
        $detail = $this->where('order_id', '=', $orderId)
            ->where('order_type', '=', $orderType)
            ->where('client', '=', $client)
            ->where('pay_method', '=', $method)
            ->order('trade_id', 'desc')
            ->find();
        if (empty($detail)) {
            return null;
        }
        if (\in_array($detail['trade_state'], [TradeStatusEnum::SUCCESS, TradeStatusEnum::REFUND])) {
            throwError('该支付订单已完成交易');
        }
        return $detail;
    }

    /**
     * 判断是否需要为同一订单保留新的支付尝试。
     * 虚拟支付的旧 out_trade_no 仍可能继续收到通知或查单结果，不能被覆盖。
     * @param mixed $record
     * @param string $outTradeNo
     * @param array $payment
     * @return bool
     */
    private function shouldCreateNewAttempt($record, string $outTradeNo, array $payment): bool
    {
        if ($outTradeNo === '') {
            return false;
        }
        if ((string)$record['out_trade_no'] === $outTradeNo) {
            return false;
        }
        $existingPlatform = (string)($record['platform'] ?? '');
        $incomingPlatform = (string)($payment['platform'] ?? '');
        return $existingPlatform === 'wechat_virtual' || $incomingPlatform === 'wechat_virtual';
    }
}
