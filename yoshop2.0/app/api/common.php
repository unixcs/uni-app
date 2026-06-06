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

// 应用公共函数库文件

/**
 * 获取当前访问的渠道(微信小程序、H5、APP等)
 * @return string|null
 */
function getPlatform(): ?string
{
    static $value = null;
    // 从header中获取 platform
    empty($value) && $value = request()->header('platform');
    // 调试模式下可通过param中获取
    if (is_debug() && empty($value)) {
        $value = request()->param('platform');
    }
    return $value;
}
