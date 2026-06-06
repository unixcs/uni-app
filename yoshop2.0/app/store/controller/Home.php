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

namespace app\store\controller;

use think\response\Json;
use app\store\service\Home as HomeService;

/**
 * 后台首页
 * Class Home
 * @package app\store\controller
 */
class Home extends Controller
{
    /**
     * 后台首页
     * @return Json
     */
    public function data(): Json
    {
        // 获取首页数据
        $model = new HomeService;
        $data = $model->getData();
        return $this->renderSuccess(compact('data'));
    }
}
