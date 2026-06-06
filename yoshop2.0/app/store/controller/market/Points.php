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

namespace app\store\controller\market;

use think\response\Json;
use app\store\controller\Controller;
use app\store\model\user\PointsLog as PointsLogModel;

/**
 * 积分管理
 * Class Points
 * @package app\store\controller\market
 */
class Points extends Controller
{
    /**
     * 积分明细
     * @return Json
     */
    public function log(): Json
    {
        // 积分明细列表
        $model = new PointsLogModel;
        $list = $model->getList($this->request->param());
        return $this->renderSuccess(compact('list'));
    }
}
