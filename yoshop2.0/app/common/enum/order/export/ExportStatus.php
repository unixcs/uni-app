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

namespace app\common\enum\order\export;

use app\common\enum\EnumBasics;

/**
 * 枚举类：导出状态
 * Class ExportStatus
 * @package app\common\enum\order\export
 */
class ExportStatus extends EnumBasics
{
    // 进行中
    const NORMAL = 10;

    // 已完成
    const COMPLETED = 20;

    // 失败
    const FAIL = 30;

    /**
     * 获取枚举数据
     * @return array
     */
    public static function data(): array
    {
        return [
            self::NORMAL => [
                'name' => '进行中',
                'value' => self::NORMAL,
            ],
            self::COMPLETED => [
                'name' => '已完成',
                'value' => self::COMPLETED,
            ],
            self::FAIL => [
                'name' => '失败',
                'value' => self::FAIL,
            ]
        ];
    }
}