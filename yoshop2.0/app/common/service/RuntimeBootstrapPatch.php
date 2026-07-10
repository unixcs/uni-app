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

namespace app\common\service;

use Throwable;
use think\facade\Db;

/**
 * 运行时补丁引导
 *
 * 在无法直接登录数据库执行变更脚本时，允许线上请求在首次进入时
 * 自动补齐当前版本强依赖的表、字段、菜单和权限关系。
 */
class RuntimeBootstrapPatch
{
    private const VERSION = '20260710_feedback_menu_popup_patch_v1';

    private static bool $bootstrapped = false;

    /**
     * 执行一次运行时补丁
     * @return void
     */
    public static function ensure(): void
    {
        if (self::$bootstrapped) {
            return;
        }

        $sentinel = runtime_path('patches' . DIRECTORY_SEPARATOR . self::VERSION . '.done');
        if (is_file($sentinel)) {
            self::$bootstrapped = true;
            return;
        }

        $patchDir = dirname($sentinel);
        if (!is_dir($patchDir)) {
            @mkdir($patchDir, 0775, true);
        }

        $lockPath = runtime_path('patches' . DIRECTORY_SEPARATOR . self::VERSION . '.lock');
        $lockHandle = @fopen($lockPath, 'c+');
        if ($lockHandle === false) {
            self::runPatchSafely($sentinel);
            return;
        }

        try {
            if (!@flock($lockHandle, LOCK_EX)) {
                self::runPatchSafely($sentinel);
                return;
            }
            if (is_file($sentinel)) {
                self::$bootstrapped = true;
                return;
            }
            self::runPatchSafely($sentinel);
        } finally {
            @flock($lockHandle, LOCK_UN);
            @fclose($lockHandle);
        }
    }

    /**
     * 执行补丁并记录结果
     * @param string $sentinel
     * @return void
     */
    private static function runPatchSafely(string $sentinel): void
    {
        try {
            self::applyFeedbackAndPopupPatch();
            @file_put_contents($sentinel, (string)time());
            self::$bootstrapped = true;
            log_record([
                'name' => 'runtime-bootstrap-patch',
                'version' => self::VERSION,
                'status' => 'applied',
            ]);
        } catch (Throwable $e) {
            log_record([
                'name' => 'runtime-bootstrap-patch',
                'version' => self::VERSION,
                'status' => 'failed',
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ], 'error');
        }
    }

    /**
     * 补齐反馈投诉与首页首登弹窗依赖的数据结构
     * @return void
     */
    private static function applyFeedbackAndPopupPatch(): void
    {
        $prefix = (string)config('database.connections.mysql.prefix', '');

        self::ensureUserFirstLoginPopupColumn($prefix . 'user');
        self::ensureFeedbackTable($prefix . 'user_feedback');
        self::ensureStoreMenus();
        self::ensureStoreApis();
        self::ensureStoreMenuApis();
    }

    /**
     * 确保用户首登弹窗标记字段存在
     * @param string $tableName
     * @return void
     */
    private static function ensureUserFirstLoginPopupColumn(string $tableName): void
    {
        if (self::columnExists($tableName, 'first_login_popup_seen_time')) {
            return;
        }
        Db::execute(sprintf(
            "ALTER TABLE `%s` ADD COLUMN `first_login_popup_seen_time` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '首页首登业务弹窗已展示时间' AFTER `nick_name`",
            $tableName
        ));
    }

    /**
     * 确保反馈投诉表存在
     * @param string $tableName
     * @return void
     */
    private static function ensureFeedbackTable(string $tableName): void
    {
        if (self::tableExists($tableName)) {
            return;
        }
        Db::execute(sprintf(
            "CREATE TABLE `%s` (\n"
            . "  `feedback_id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT '反馈ID',\n"
            . "  `user_id` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '用户ID',\n"
            . "  `store_id` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '商城ID',\n"
            . "  `issue_type` tinyint(3) unsigned NOT NULL DEFAULT '10' COMMENT '问题类型(10建议 20异常 30体验 40订单 50其他 60投诉)',\n"
            . "  `status` tinyint(3) unsigned NOT NULL DEFAULT '10' COMMENT '处理状态(10待处理 20处理中 30已回复 40已关闭)',\n"
            . "  `content` text NOT NULL COMMENT '反馈内容',\n"
            . "  `mobile` varchar(20) NOT NULL DEFAULT '' COMMENT '联系方式',\n"
            . "  `image_ids` text NULL COMMENT '图片file_id JSON数组',\n"
            . "  `reply_content` text NULL COMMENT '官方回复',\n"
            . "  `reply_time` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '回复时间',\n"
            . "  `is_delete` tinyint(3) unsigned NOT NULL DEFAULT '0' COMMENT '是否软删除',\n"
            . "  `create_time` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '创建时间',\n"
            . "  `update_time` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '更新时间',\n"
            . "  PRIMARY KEY (`feedback_id`),\n"
            . "  KEY `idx_user_id` (`user_id`),\n"
            . "  KEY `idx_store_status_issue` (`store_id`,`status`,`issue_type`),\n"
            . "  KEY `idx_create_time` (`create_time`)\n"
            . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户反馈投诉表'",
            $tableName
        ));
    }

    /**
     * 确保商家后台菜单存在
     * @return void
     */
    private static function ensureStoreMenus(): void
    {
        $rows = [
            [
                'menu_id' => 10320,
                'type' => 10,
                'name' => '反馈/投诉',
                'path' => '/content/feedback/index',
                'is_page' => 1,
                'module' => 'content-feedback',
                'action_mark' => '',
                'parent_id' => 10072,
                'sort' => 115,
                'create_time' => 1783569600,
                'update_time' => 1783569600,
            ],
            [
                'menu_id' => 10321,
                'type' => 20,
                'name' => '处理',
                'path' => '',
                'is_page' => 1,
                'module' => '',
                'action_mark' => 'edit',
                'parent_id' => 10320,
                'sort' => 100,
                'create_time' => 1783569600,
                'update_time' => 1783569600,
            ],
        ];
        foreach ($rows as $row) {
            self::upsertByPrimaryKey('store_menu', 'menu_id', $row);
        }
    }

    /**
     * 确保商家后台 API 权限存在
     * @return void
     */
    private static function ensureStoreApis(): void
    {
        $rows = [
            [
                'api_id' => 11334,
                'name' => '反馈投诉',
                'url' => '-',
                'parent_id' => 11105,
                'sort' => 115,
                'create_time' => 1783569600,
                'update_time' => 1783569600,
            ],
            [
                'api_id' => 11335,
                'name' => '反馈列表',
                'url' => '/content.feedback/list',
                'parent_id' => 11334,
                'sort' => 100,
                'create_time' => 1783569600,
                'update_time' => 1783569600,
            ],
            [
                'api_id' => 11336,
                'name' => '反馈详情',
                'url' => '/content.feedback/detail',
                'parent_id' => 11334,
                'sort' => 105,
                'create_time' => 1783569600,
                'update_time' => 1783569600,
            ],
            [
                'api_id' => 11337,
                'name' => '处理反馈',
                'url' => '/content.feedback/edit',
                'parent_id' => 11334,
                'sort' => 110,
                'create_time' => 1783569600,
                'update_time' => 1783569600,
            ],
        ];
        foreach ($rows as $row) {
            self::upsertByPrimaryKey('store_api', 'api_id', $row);
        }
    }

    /**
     * 确保菜单与 API 的绑定关系存在
     * @return void
     */
    private static function ensureStoreMenuApis(): void
    {
        $rows = [
            ['id' => 11938, 'menu_id' => 10320, 'api_id' => 11335, 'create_time' => 1783569600],
            ['id' => 11939, 'menu_id' => 10320, 'api_id' => 11334, 'create_time' => 1783569600],
            ['id' => 11940, 'menu_id' => 10320, 'api_id' => 11105, 'create_time' => 1783569600],
            ['id' => 11941, 'menu_id' => 10321, 'api_id' => 11336, 'create_time' => 1783569600],
            ['id' => 11942, 'menu_id' => 10321, 'api_id' => 11337, 'create_time' => 1783569600],
            ['id' => 11943, 'menu_id' => 10321, 'api_id' => 11334, 'create_time' => 1783569600],
            ['id' => 11944, 'menu_id' => 10321, 'api_id' => 11105, 'create_time' => 1783569600],
        ];
        foreach ($rows as $row) {
            self::upsertByPrimaryKey('store_menu_api', 'id', $row);
        }
    }

    /**
     * 按主键幂等写入
     * @param string $table
     * @param string $primaryKey
     * @param array $row
     * @return void
     */
    private static function upsertByPrimaryKey(string $table, string $primaryKey, array $row): void
    {
        $pkValue = $row[$primaryKey] ?? null;
        if ($pkValue === null) {
            return;
        }
        if (Db::name($table)->where($primaryKey, '=', $pkValue)->find()) {
            Db::name($table)->where($primaryKey, '=', $pkValue)->update($row);
            return;
        }
        Db::name($table)->insert($row);
    }

    /**
     * 表是否存在
     * @param string $tableName
     * @return bool
     */
    private static function tableExists(string $tableName): bool
    {
        return !empty(Db::query(sprintf("SHOW TABLES LIKE '%s'", addslashes($tableName))));
    }

    /**
     * 字段是否存在
     * @param string $tableName
     * @param string $columnName
     * @return bool
     */
    private static function columnExists(string $tableName, string $columnName): bool
    {
        return !empty(Db::query(sprintf(
            "SHOW COLUMNS FROM `%s` LIKE '%s'",
            $tableName,
            addslashes($columnName)
        )));
    }
}
