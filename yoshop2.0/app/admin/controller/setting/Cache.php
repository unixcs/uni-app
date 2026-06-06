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

namespace app\admin\controller\setting;

use think\response\Json;
use app\admin\controller\Controller;
use app\admin\service\Cache as CacheService;

/**
 * 清理缓存
 * Class Index
 * @package app\admin\controller
 */
class Cache extends Controller
{
    /**
     * 清理缓存
     * @return Json
     */
    public function clear(): Json
    {
        // 清理缓存
        $CacheService = new CacheService;
        if (!$CacheService->rmCache($this->postForm())) {
            return $this->renderError($CacheService->getError() ?: '操作失败');
        }
        return $this->renderSuccess('操作成功');
    }
}
