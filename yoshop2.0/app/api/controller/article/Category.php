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

namespace app\api\controller\article;

use think\response\Json;
use app\api\controller\Controller;
use app\api\model\article\Category as CategoryModel;

/**
 * 文章分类
 * Class Category
 * @package app\api\controller\article
 */
class Category extends Controller
{
    /**
     * 文章分类列表
     * @return Json
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function list(): Json
    {
        $model = new CategoryModel;
        $list = $model->getShowList();
        return $this->renderSuccess(compact('list'));
    }
}