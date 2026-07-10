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

namespace app\store\model;

use app\common\model\Feedback as FeedbackModel;

/**
 * 用户反馈/投诉模型
 * Class Feedback
 * @package app\store\model
 */
class Feedback extends FeedbackModel
{
    /**
     * 隐藏字段
     * @var array
     */
    protected $hidden = [
        'store_id',
        'image_ids',
        'is_delete',
    ];

    /**
     * 追加字段
     * @var array
     */
    protected $append = [
        'issue_type_text',
        'status_text',
    ];

    /**
     * 获取列表
     * @param array $param
     * @return \think\Paginator
     * @throws \think\db\exception\DbException
     */
    public function getList(array $param = []): \think\Paginator
    {
        $query = $this->setQueryFilter($param);
        return $query->with(['user.avatar'])
            ->alias('feedback')
            ->field(['feedback.*'])
            ->join('user', 'user.user_id = feedback.user_id')
            ->where('feedback.store_id', '=', (int)static::$storeId)
            ->where('feedback.is_delete', '=', 0)
            ->order(['feedback.create_time' => 'desc'])
            ->paginate(15);
    }

    /**
     * 查询条件
     * @param array $param
     * @return \think\db\BaseQuery
     */
    private function setQueryFilter(array $param): \think\db\BaseQuery
    {
        $query = $this->getNewQuery();
        $params = $this->setQueryDefaultValue($param, [
            'issueType' => -1,
            'status' => -1,
            'search' => '',
            'mobile' => '',
        ]);
        $params['issueType'] > 0 && $query->where('feedback.issue_type', '=', (int)$params['issueType']);
        $params['status'] > 0 && $query->where('feedback.status', '=', (int)$params['status']);
        !empty($params['mobile']) && $query->where('feedback.mobile|user.mobile', 'like', "%{$params['mobile']}%");
        !empty($params['search']) && $query->where('feedback.feedback_id|feedback.content|feedback.mobile|user.nick_name|user.mobile', 'like', "%{$params['search']}%");
        return $query;
    }

    /**
     * 获取详情
     * @param int $feedbackId
     * @return static|array|null
     */
    public function getDetail(int $feedbackId)
    {
        $detail = static::detail($feedbackId, ['user.avatar']);
        empty($detail) && throwError('未找到反馈记录');
        !empty($detail) && $detail->append(['images']);
        return $detail;
    }

    /**
     * 编辑处理记录
     * @param array $data
     * @return bool
     */
    public function edit(array $data): bool
    {
        $status = (int)($data['status'] ?? 0);
        if (!static::isValidStatus($status)) {
            $this->error = '请选择处理状态';
            return false;
        }

        $replyContent = trim((string)($data['reply_content'] ?? ''));
        if ($status === 30 && $replyContent === '') {
            $this->error = '已回复状态必须填写官方回复';
            return false;
        }
        if (mb_strlen($replyContent) > 2000) {
            $this->error = '官方回复不能超过2000字';
            return false;
        }

        $currentReplyContent = trim((string)$this['reply_content']);
        $currentReplyTime = (int)$this['reply_time'];
        $hasReplyHistory = $currentReplyContent !== '' || $currentReplyTime > 0 || (int)$this['status'] === 30;
        if (in_array($status, [10, 20], true) && $hasReplyHistory) {
            $this->error = '已回复记录不可回退到待处理或处理中';
            return false;
        }

        $nextReplyContent = $currentReplyContent;
        $nextReplyTime = $currentReplyTime;

        if ($status === 30) {
            $nextReplyContent = $replyContent;
            $nextReplyTime = $currentReplyContent === '' ? time() : $currentReplyTime;
        } elseif ($currentReplyContent === '') {
            $nextReplyContent = '';
            $nextReplyTime = 0;
        }

        return $this->save([
            'status' => $status,
            'reply_content' => $nextReplyContent,
            'reply_time' => $nextReplyTime,
        ]) !== false;
    }
}

