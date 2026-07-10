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

namespace app\api\controller;

use think\response\Json;
use app\api\model\Feedback as FeedbackModel;

/**
 * 反馈/投诉控制器
 * Class Feedback
 * @package app\api\controller
 */
class Feedback extends Controller
{
    /**
     * 提交反馈/投诉
     * @return Json
     * @throws \cores\exception\BaseException
     */
    public function create(): Json
    {
        $model = new FeedbackModel;
        if ($model->createFeedback($this->postForm() ?: [])) {
            return $this->renderSuccess([], '提交成功');
        }
        return $this->renderError($model->getError() ?: '提交失败');
    }

    /**
     * 反馈/投诉列表
     * @return Json
     * @throws \cores\exception\BaseException
     * @throws \think\db\exception\DbException
     */
    public function list(): Json
    {
        $model = new FeedbackModel;
        $list = $model->getList($this->request->param());
        return $this->renderSuccess(compact('list'));
    }

    /**
     * 反馈/投诉详情
     * @param int $feedbackId
     * @return Json
     * @throws \cores\exception\BaseException
     */
    public function detail(int $feedbackId): Json
    {
        $detail = FeedbackModel::getDetail($feedbackId);
        return $this->renderSuccess(compact('detail'));
    }
}

