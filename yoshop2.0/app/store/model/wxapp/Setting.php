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

namespace app\store\model\wxapp;

use think\facade\Cache;
use app\common\model\wxapp\Setting as SettingModel;
use app\common\library\helper;
use cores\exception\BaseException;

/**
 * 微信小程序设置模型
 * Class Setting
 * @package app\store\model\wxapp
 */
class Setting extends SettingModel
{
    /**
     * 设置项描述
     * @var array
     */
    private array $describe = ['basic' => '基础设置'];

    /**
     * 更新系统设置
     * @param string $key
     * @param array $values
     * @return bool
     * @throws BaseException
     */
    public function edit(string $key, array $values): bool
    {
        $model = self::detail($key) ?: $this;
        // 删除小程序设置缓存
        Cache::delete('wxapp_setting_' . self::$storeId);
        // 保存设置
        return $model->save([
            'key' => $key,
            'describe' => $this->describe[$key],
            'values' => helper::pick($values, ['enabled', 'app_id', 'app_secret', 'enableShipping']),
            'update_time' => time(),
            'store_id' => self::$storeId,
        ]);
    }

}
