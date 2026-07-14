<?php
// +----------------------------------------------------------------------
// | 支付渠道分类历史回填
// +----------------------------------------------------------------------
declare (strict_types=1);

namespace app\common\command;

use app\common\enum\payment\trade\ChannelClass as ChannelClassEnum;
use app\common\model\PaymentTrade as PaymentTradeModel;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

/**
 * 只根据本地既有证据推进 channel_class，不主动请求上游。
 * 默认 dry-run；真实写入必须显式传 --apply。
 */
class VirtualPaymentChannelBackfill extends Command
{
    protected function configure()
    {
        $this->setName('virtual-payment:channel-backfill')
            ->addOption('apply', null, Option::VALUE_NONE, 'Persist classifications (default is dry-run)')
            ->addOption('from-trade-id', null, Option::VALUE_OPTIONAL, 'Start after this trade_id', '0')
            ->addOption('limit', null, Option::VALUE_OPTIONAL, 'Maximum rows to inspect; 0 means unlimited', '0')
            ->addOption('batch-size', null, Option::VALUE_OPTIONAL, 'Rows per batch', '200')
            ->setDescription('Backfill payment_trade.channel_class from persisted evidence');
    }

    protected function execute(Input $input, Output $output)
    {
        $apply = (bool)$input->getOption('apply');
        $cursor = max(0, (int)$input->getOption('from-trade-id'));
        $limit = max(0, (int)$input->getOption('limit'));
        $batchSize = min(1000, max(1, (int)$input->getOption('batch-size')));
        $summary = [
            'mode' => $apply ? 'apply' : 'dry-run',
            'from_trade_id' => $cursor,
            'inspected' => 0,
            'changed' => 0,
            'unchanged' => 0,
            'unknown' => 0,
            'non_ios' => 0,
            'ios_apple' => 0,
            'errors' => 0,
            'last_trade_id' => $cursor,
        ];

        while ($limit === 0 || $summary['inspected'] < $limit) {
            $take = $limit === 0 ? $batchSize : min($batchSize, $limit - $summary['inspected']);
            $trades = (new PaymentTradeModel())
                ->where('trade_id', '>', $cursor)
                ->order('trade_id', 'asc')
                ->limit($take)
                ->select();
            if ($trades->isEmpty()) {
                break;
            }
            foreach ($trades as $trade) {
                $tradeId = (int)$trade['trade_id'];
                $cursor = $tradeId;
                $summary['last_trade_id'] = $tradeId;
                $summary['inspected']++;
                try {
                    $current = (int)($trade['channel_class'] ?? ChannelClassEnum::UNKNOWN);
                    $snapshot = PaymentTradeModel::decodePayloadSnapshot((string)($trade['payload_snapshot'] ?? ''));
                    $next = PaymentTradeModel::classifyChannelClass((string)($trade['platform'] ?? ''), $snapshot, $current);
                    if ($next === ChannelClassEnum::IOS_APPLE) {
                        $summary['ios_apple']++;
                    } elseif ($next === ChannelClassEnum::NON_IOS) {
                        $summary['non_ios']++;
                    } else {
                        $summary['unknown']++;
                    }
                    if ($next === $current) {
                        $summary['unchanged']++;
                        continue;
                    }
                    if ($apply && !PaymentTradeModel::refreshChannelClass($tradeId)) {
                        throw new \RuntimeException('channel_class update returned false');
                    }
                    $summary['changed']++;
                } catch (\Throwable $e) {
                    $summary['errors']++;
                    $output->warning(sprintf('trade_id=%d error=%s', $tradeId, $e->getMessage()));
                }
            }
        }

        $output->writeln(json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $summary['errors'] === 0 ? 0 : 1;
    }
}
