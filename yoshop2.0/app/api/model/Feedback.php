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

namespace app\api\model;

use app\api\service\User as UserService;
use app\common\model\Feedback as FeedbackModel;
use app\common\model\UploadFile;
use cores\exception\BaseException;

/**
 * 用户反馈/投诉模型
 * Class Feedback
 * @package app\api\model
 */
class Feedback extends FeedbackModel
{
    /**
     * 隐藏字段
     * @var array
     */
    protected $hidden = [
        'store_id',
        'user_id',
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
        'images',
    ];

    /**
     * 新建反馈/投诉
     * @param array $data
     * @return bool
     * @throws BaseException
     */
    public function createFeedback(array $data): bool
    {
        $issueType = (int)($data['issueType'] ?? 0);
        if (!static::isValidIssueType($issueType)) {
            throwError('请选择问题类型');
        }

        $content = trim((string)($data['content'] ?? ''));
        if ($content === '') {
            throwError('请填写反馈内容');
        }
        if (mb_strlen($content) > 2000) {
            throwError('反馈内容不能超过2000字');
        }

        $mobile = trim((string)($data['mobile'] ?? ''));
        if ($mobile === '') {
            throwError('请填写联系方式');
        }
        if (mb_strlen($mobile) > 20) {
            throwError('联系方式格式不正确');
        }

        $imageIds = array_values(array_unique(array_map(static function ($item) {
            return (int)$item;
        }, (array)($data['imageIds'] ?? []))));
        if (count($imageIds) > 6) {
            throwError('最多上传6张图片');
        }
        $userId = (int)UserService::getCurrentLoginUserId();
        $validImageIds = UploadFile::filterUserImageIds($imageIds, $userId, self::$storeId);
        if (count($validImageIds) !== count($imageIds)) {
            throwError('上传凭证已失效，请重新上传');
        }

        return $this->save([
            'store_id' => self::$storeId,
            'user_id' => $userId,
            'issue_type' => $issueType,
            'status' => 10,
            'content' => $content,
            'mobile' => $mobile,
            'image_ids' => $validImageIds,
            'reply_content' => '',
            'reply_time' => 0,
            'is_delete' => 0,
        ]) !== false;
    }

    /**
     * 获取当前用户反馈列表
     * @param array $param
     * @return \think\Paginator
     * @throws BaseException
     * @throws \think\db\exception\DbException
     */
    public function getList(array $param = []): \think\Paginator
    {
        $params = $this->setQueryDefaultValue($param, [
            'status' => -1,
        ]);
        $userId = UserService::getCurrentLoginUserId();
        $query = $this->where('user_id', '=', $userId)
            ->where('is_delete', '=', 0);
        $params['status'] > 0 && $query->where('status', '=', (int)$params['status']);
        return $query->order(['create_time' => 'desc'])->paginate(15);
    }

    /**
     * 获取当前用户反馈详情
     * @param int $feedbackId
     * @return static|null
     * @throws BaseException
     */
    public static function getDetail(int $feedbackId): ?self
    {
        $detail = (new static)
            ->where('feedback_id', '=', $feedbackId)
            ->where('user_id', '=', UserService::getCurrentLoginUserId())
            ->where('is_delete', '=', 0)
            ->find();
        empty($detail) && throwError('未找到反馈记录');
        return $detail;
    }
}

