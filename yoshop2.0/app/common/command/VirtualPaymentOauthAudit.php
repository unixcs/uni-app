<?php
// +----------------------------------------------------------------------
// | Virtual payment oauth audit / repair
// +----------------------------------------------------------------------
declare (strict_types=1);

namespace app\common\command;

use app\common\enum\Client as ClientEnum;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\facade\Db;

class VirtualPaymentOauthAudit extends Command
{
    private const DEFAULT_STORE_ID = 10001;
    private const DEFAULT_EVIDENCE_DIR = '/opt/yoshop/yoshop2.0/runtime/codex-evidence/virtual-payment-oauth-audit';

    protected function configure()
    {
        $this->setName('virtual-payment:oauth-audit')
            ->addOption('store-id', null, Option::VALUE_OPTIONAL, 'Store ID', (string)self::DEFAULT_STORE_ID)
            ->addOption('oauth-type', null, Option::VALUE_OPTIONAL, 'Oauth type', ClientEnum::MP_WEIXIN)
            ->addOption('openid', null, Option::VALUE_OPTIONAL, 'Explicit openid to audit', '')
            ->addOption('user-mobile', null, Option::VALUE_OPTIONAL, 'Resolve openid from this mobile when --openid is empty', '')
            ->addOption('keep-user-id', null, Option::VALUE_OPTIONAL, 'When --apply is set, keep bindings for this user', '')
            ->addOption('keep-oauth-row-id', null, Option::VALUE_OPTIONAL, 'When --apply is set, keep this exact active oauth row', '')
            ->addOption('confirm-openid', null, Option::VALUE_OPTIONAL, 'Required with --apply; must equal the resolved openid', '')
            ->addOption('apply', null, Option::VALUE_NONE, 'Soft-delete duplicate active oauth rows except keep-user-id')
            ->addOption('evidence-dir', null, Option::VALUE_OPTIONAL, 'Evidence output directory', self::DEFAULT_EVIDENCE_DIR)
            ->setDescription('Audit and optionally repair duplicate MP-WEIXIN oauth bindings for virtual-payment debugging');
    }

    protected function execute(Input $input, Output $output)
    {
        $storeId = (int)$input->getOption('store-id');
        $oauthType = trim((string)$input->getOption('oauth-type'));
        $openid = trim((string)$input->getOption('openid'));
        $userMobile = trim((string)$input->getOption('user-mobile'));
        $keepUserId = (int)$input->getOption('keep-user-id');
        $keepOauthRowId = (int)$input->getOption('keep-oauth-row-id');
        $confirmOpenid = trim((string)$input->getOption('confirm-openid'));
        $apply = (bool)$input->getOption('apply');
        $evidenceDir = rtrim((string)$input->getOption('evidence-dir'), '/');

        $report = [
            'run_at' => date('c'),
            'store_id' => $storeId,
            'oauth_type' => $oauthType,
            'input' => [
                'openid' => $openid,
                'user_mobile' => $userMobile,
                'keep_user_id' => $keepUserId,
                'keep_oauth_row_id' => $keepOauthRowId,
                'confirm_openid' => $confirmOpenid,
                'apply' => $apply,
            ],
            'resolved' => [],
            'before' => [],
            'after' => [],
            'actions' => [],
            'summary' => [
                'ok' => false,
                'message' => '',
            ],
        ];

        try {
            $resolved = $this->resolveAuditTarget($storeId, $oauthType, $openid, $userMobile);
            $report['resolved'] = $resolved;
            $before = $this->buildBindingSnapshot($storeId, $oauthType, (string)$resolved['openid']);
            $report['before'] = $before;

            $activeUserIds = array_values(array_unique(array_map('intval', array_column($before['bindings_active'], 'user_id'))));
            if (!$apply) {
                $report['summary'] = [
                    'ok' => true,
                    'message' => count($activeUserIds) > 1
                        ? sprintf('detected %d active bindings for openid %s', count($activeUserIds), $resolved['openid'])
                        : sprintf('openid %s is uniquely bound', $resolved['openid']),
                ];
                $file = $this->writeEvidence($evidenceDir, $report);
                $this->renderReport($output, $report, $file);
                return count($activeUserIds) > 1 ? 1 : 0;
            }

            if ($confirmOpenid === '' || $confirmOpenid !== (string)$resolved['openid']) {
                throw new \RuntimeException('--apply requires --confirm-openid and it must match the resolved openid');
            }
            if ($keepUserId <= 0 && $keepOauthRowId <= 0) {
                throw new \RuntimeException('--apply requires --keep-user-id or --keep-oauth-row-id');
            }

            $activeBindings = (array)$before['bindings_active'];
            $keepBinding = $this->resolveKeepBinding($activeBindings, $keepUserId, $keepOauthRowId);
            $keepUserId = (int)$keepBinding['user_id'];
            $keepOauthRowId = (int)$keepBinding['id'];

            if ((int)$keepBinding['user_is_delete'] !== 0) {
                throw new \RuntimeException(sprintf('refusing to keep deleted user binding: user_id=%d row_id=%d', $keepUserId, $keepOauthRowId));
            }
            if (!$keepBinding['session_key_present']) {
                throw new \RuntimeException(sprintf('refusing to keep binding without session_key: user_id=%d row_id=%d', $keepUserId, $keepOauthRowId));
            }

            $toDisableIds = [];
            foreach ($activeBindings as $binding) {
                if ((int)$binding['id'] !== $keepOauthRowId) {
                    $toDisableIds[] = (int)$binding['id'];
                }
            }

            if (empty($toDisableIds)) {
                $report['actions'][] = [
                    'type' => 'noop',
                    'message' => 'no duplicate active bindings to disable',
                ];
            } else {
                $now = time();
                Db::transaction(function () use ($toDisableIds, $now) {
                    Db::name('user_oauth')
                        ->whereIn('id', $toDisableIds)
                        ->where('is_delete', '=', 0)
                        ->update([
                            'is_delete' => 1,
                            'session_key' => '',
                            'update_time' => $now,
                        ]);
                });
                $report['actions'][] = [
                    'type' => 'soft_delete_duplicates',
                    'keep_user_id' => $keepUserId,
                    'keep_oauth_row_id' => $keepOauthRowId,
                    'disabled_oauth_row_ids' => $toDisableIds,
                ];
            }

            $after = $this->buildBindingSnapshot($storeId, $oauthType, (string)$resolved['openid']);
            $report['after'] = $after;
            $afterActiveBindings = (array)$after['bindings_active'];
            $afterActiveUserIds = array_values(array_unique(array_map('intval', array_column($afterActiveBindings, 'user_id'))));
            $afterActiveRowIds = array_values(array_map('intval', array_column($afterActiveBindings, 'id')));
            $report['summary'] = [
                'ok' => count($afterActiveBindings) === 1
                    && (int)($afterActiveBindings[0]['user_id'] ?? 0) === $keepUserId
                    && (int)($afterActiveBindings[0]['id'] ?? 0) === $keepOauthRowId
                    && !empty($afterActiveBindings[0]['session_key_present'])
                    && (int)($afterActiveBindings[0]['user_is_delete'] ?? 0) === 0,
                'message' => sprintf(
                    'after apply: active users=%s active_rows=%s',
                    empty($afterActiveUserIds) ? 'none' : implode(',', $afterActiveUserIds),
                    empty($afterActiveRowIds) ? 'none' : implode(',', $afterActiveRowIds)
                ),
            ];
            $file = $this->writeEvidence($evidenceDir, $report);
            $this->renderReport($output, $report, $file);
            return $report['summary']['ok'] ? 0 : 1;
        } catch (\Throwable $e) {
            $report['summary'] = [
                'ok' => false,
                'message' => $e->getMessage(),
            ];
            $file = $this->writeEvidence($evidenceDir, $report);
            $this->renderReport($output, $report, $file);
            return 1;
        }
    }

    private function resolveAuditTarget(int $storeId, string $oauthType, string $openid, string $userMobile): array
    {
        if ($openid !== '') {
            return [
                'openid' => $openid,
                'source' => 'option.openid',
            ];
        }

        if ($userMobile === '') {
            throw new \RuntimeException('either --openid or --user-mobile is required');
        }

        $user = Db::name('user')
            ->where('store_id', '=', $storeId)
            ->where('is_delete', '=', 0)
            ->where('mobile', '=', $userMobile)
            ->find();
        if (empty($user)) {
            throw new \RuntimeException(sprintf('no user found for mobile %s', $userMobile));
        }

        $oauth = Db::name('user_oauth')
            ->where('user_id', '=', (int)$user['user_id'])
            ->where('oauth_type', '=', $oauthType)
            ->order(['is_delete' => 'asc', 'id' => 'desc'])
            ->find();
        $resolvedOpenid = trim((string)($oauth['oauth_id'] ?? ''));
        if ($resolvedOpenid === '') {
            throw new \RuntimeException(sprintf('user %d has no %s oauth binding', (int)$user['user_id'], $oauthType));
        }

        return [
            'openid' => $resolvedOpenid,
            'source' => 'user_mobile',
            'user_id' => (int)$user['user_id'],
            'mobile' => (string)$user['mobile'],
            'nick_name' => (string)$user['nick_name'],
            'oauth_row_id' => (int)($oauth['id'] ?? 0),
        ];
    }

    private function buildBindingSnapshot(int $storeId, string $oauthType, string $openid): array
    {
        $rows = Db::name('user_oauth')
            ->where('oauth_type', '=', $oauthType)
            ->where('oauth_id', '=', $openid)
            ->order(['is_delete' => 'asc', 'user_id' => 'asc', 'id' => 'asc'])
            ->select()
            ->toArray();

        $userIds = array_values(array_unique(array_map('intval', array_column($rows, 'user_id'))));
        $users = empty($userIds)
            ? []
            : Db::name('user')
                ->whereIn('user_id', $userIds)
                ->column('user_id,nick_name,mobile,is_delete,last_login_time,create_time,update_time', 'user_id');
        $orderStats = $this->fetchCountStats('order', $userIds);
        $tradeStats = $this->fetchCountStats('payment_trade', $userIds);

        $bindings = [];
        foreach ($rows as $row) {
            $userId = (int)$row['user_id'];
            $user = (array)($users[$userId] ?? []);
            $bindings[] = [
                'id' => (int)$row['id'],
                'user_id' => $userId,
                'store_id' => (int)$row['store_id'],
                'is_delete' => (int)$row['is_delete'],
                'session_key_present' => trim((string)$row['session_key']) !== '',
                'nick_name' => (string)($user['nick_name'] ?? ''),
                'mobile' => $this->maskMobile((string)($user['mobile'] ?? '')),
                'user_is_delete' => (int)($user['is_delete'] ?? 0),
                'user_last_login_time' => (int)($user['last_login_time'] ?? 0),
                'oauth_create_time' => (int)$row['create_time'],
                'oauth_update_time' => (int)$row['update_time'],
                'order_count' => (int)($orderStats[$userId]['total'] ?? 0),
                'paid_order_count' => (int)($orderStats[$userId]['paid'] ?? 0),
                'trade_count' => (int)($tradeStats[$userId]['total'] ?? 0),
                'paid_trade_count' => (int)($tradeStats[$userId]['paid'] ?? 0),
            ];
        }

        return [
            'openid' => $openid,
            'binding_count_total' => count($bindings),
            'binding_count_active' => count(array_filter($bindings, static fn(array $binding): bool => (int)$binding['is_delete'] === 0)),
            'bindings' => $bindings,
            'bindings_active' => array_values(array_filter($bindings, static fn(array $binding): bool => (int)$binding['is_delete'] === 0)),
        ];
    }

    private function fetchCountStats(string $table, array $userIds): array
    {
        if (empty($userIds)) {
            return [];
        }

        $paidField = $table === 'payment_trade' ? 'trade_state' : 'pay_status';
        $rows = Db::name($table)
            ->fieldRaw("user_id, COUNT(*) AS total, SUM(CASE WHEN {$paidField} = 20 THEN 1 ELSE 0 END) AS paid")
            ->whereIn('user_id', $userIds)
            ->group('user_id')
            ->select()
            ->toArray();

        $indexed = [];
        foreach ($rows as $row) {
            $indexed[(int)$row['user_id']] = [
                'total' => (int)$row['total'],
                'paid' => (int)$row['paid'],
            ];
        }
        return $indexed;
    }

    private function resolveKeepBinding(array $activeBindings, int $keepUserId, int $keepOauthRowId): array
    {
        if (empty($activeBindings)) {
            throw new \RuntimeException('no active bindings found for resolved openid');
        }

        if ($keepOauthRowId > 0) {
            foreach ($activeBindings as $binding) {
                if ((int)$binding['id'] === $keepOauthRowId) {
                    return $binding;
                }
            }
            throw new \RuntimeException(sprintf('keep-oauth-row-id %d is not among active bindings', $keepOauthRowId));
        }

        $candidates = array_values(array_filter($activeBindings, static fn(array $binding): bool => (int)$binding['user_id'] === $keepUserId));
        if (empty($candidates)) {
            throw new \RuntimeException(sprintf('keep-user-id %d is not among active bindings', $keepUserId));
        }
        if (count($candidates) > 1) {
            $rowIds = array_map(static fn(array $binding): int => (int)$binding['id'], $candidates);
            throw new \RuntimeException(sprintf(
                'user_id %d has multiple active oauth rows (%s); please rerun with --keep-oauth-row-id',
                $keepUserId,
                implode(',', $rowIds)
            ));
        }
        return $candidates[0];
    }

    private function writeEvidence(string $dir, array $report): string
    {
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('failed to create evidence dir: %s', $dir));
        }
        $file = $dir . '/audit-' . date('YmdHis') . '.json';
        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('failed to encode oauth audit evidence json');
        }
        if (file_put_contents($file, $json) === false) {
            throw new \RuntimeException(sprintf('failed to write evidence file: %s', $file));
        }
        return $file;
    }

    private function renderReport(Output $output, array $report, string $file): void
    {
        $snapshot = !empty($report['after']) ? $report['after'] : $report['before'];
        $bindings = (array)($snapshot['bindings'] ?? []);
        foreach ($bindings as $binding) {
            $output->writeln(sprintf(
                '[%s] row=%d user=%d nick=%s mobile=%s active=%s session=%s orders=%d/%d trades=%d/%d',
                (string)($report['resolved']['openid'] ?? ''),
                (int)$binding['id'],
                (int)$binding['user_id'],
                (string)$binding['nick_name'],
                (string)$binding['mobile'],
                (int)$binding['is_delete'] === 0 ? 'Y' : 'N',
                $binding['session_key_present'] ? 'Y' : 'N',
                (int)$binding['paid_order_count'],
                (int)$binding['order_count'],
                (int)$binding['paid_trade_count'],
                (int)$binding['trade_count']
            ));
        }
        foreach ((array)($report['actions'] ?? []) as $action) {
            $output->writeln('action: ' . json_encode($action, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        $output->writeln('evidence: ' . $file);
        $output->writeln(($report['summary']['ok'] ? '<info>' : '<error>') . $report['summary']['message'] . ($report['summary']['ok'] ? '</info>' : '</error>'));
    }

    private function maskMobile(string $mobile): string
    {
        if ($mobile === '' || strlen($mobile) < 7) {
            return $mobile;
        }
        return substr($mobile, 0, 3) . '****' . substr($mobile, -4);
    }
}
