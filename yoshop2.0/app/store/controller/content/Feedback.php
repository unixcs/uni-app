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

namespace app\store\controller\content;

use think\response\Json;
use app\store\controller\Controller;
use app\store\model\Feedback as FeedbackModel;

/**
 * 反馈/投诉管理
 * Class Feedback
 * @package app\store\controller\content
 */
class Feedback extends Controller
{
    /**
     * 获取列表记录
     * @return Json
     * @throws \think\db\exception\DbException
     */
    public function list(): Json
    {
        $model = new FeedbackModel;
        $list = $model->getList($this->request->param());
        return $this->renderSuccess(compact('list'));
    }

    /**
     * 获取详情
     * @param int $feedbackId
     * @return Json
     */
    public function detail(int $feedbackId): Json
    {
        $model = new FeedbackModel;
        $detail = $model->getDetail($feedbackId);
        return $this->renderSuccess(compact('detail'));
    }

    /**
     * 编辑处理记录
     * @param int $feedbackId
     * @return Json
     */
    public function edit(int $feedbackId): Json
    {
        $model = (new FeedbackModel)->getDetail($feedbackId);
        if ($model->edit($this->postForm() ?: [])) {
            return $this->renderSuccess('更新成功');
        }
        return $this->renderError($model->getError() ?: '更新失败');
    }
}

