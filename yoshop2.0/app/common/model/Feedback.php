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

namespace app\common\model;

use cores\BaseModel;
use think\model\relation\BelongsTo;
use app\common\library\helper;
use app\common\enum\file\FileType as FileTypeEnum;

/**
 * 用户反馈/投诉模型
 * Class Feedback
 * @package app\common\model
 */
class Feedback extends BaseModel
{
    // 定义表名
    protected $name = 'user_feedback';

    // 定义主键
    protected $pk = 'feedback_id';

    /**
     * 问题类型枚举
     * @return array
     */
    public static function getIssueTypeMap(): array
    {
        return [
            10 => '功能建议',
            20 => '功能异常',
            30 => '体验问题',
            40 => '订单问题',
            50 => '其他问题',
            60 => '投诉反馈',
        ];
    }

    /**
     * 状态枚举
     * @return array
     */
    public static function getStatusMap(): array
    {
        return [
            10 => '待处理',
            20 => '处理中',
            30 => '已回复',
            40 => '已关闭',
        ];
    }

    /**
     * 验证问题类型
     * @param int $issueType
     * @return bool
     */
    public static function isValidIssueType(int $issueType): bool
    {
        return isset(static::getIssueTypeMap()[$issueType]);
    }

    /**
     * 验证状态
     * @param int $status
     * @return bool
     */
    public static function isValidStatus(int $status): bool
    {
        return isset(static::getStatusMap()[$status]);
    }

    /**
     * 关联用户
     * @return BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo('User')
            ->field(['user_id', 'nick_name', 'avatar_id', 'mobile', 'platform']);
    }

    /**
     * 获取器：图片ID集
     * @param string $json
     * @return array
     */
    public function getImageIdsAttr($json): array
    {
        return $json ? helper::jsonDecode($json) : [];
    }

    /**
     * 修改器：图片ID集
     * @param array $data
     * @return string
     */
    public function setImageIdsAttr(array $data): string
    {
        $imageIds = array_values(array_unique(array_map(static function ($item) {
            return (int)$item;
        }, $data)));
        return helper::jsonEncode($imageIds);
    }

    /**
     * 获取器：问题类型文案
     * @param $value
     * @param $data
     * @return string
     */
    public function getIssueTypeTextAttr($value, $data): string
    {
        $issueType = (int)($data['issue_type'] ?? 0);
        return static::getIssueTypeMap()[$issueType] ?? '';
    }

    /**
     * 获取器：处理状态文案
     * @param $value
     * @param $data
     * @return string
     */
    public function getStatusTextAttr($value, $data): string
    {
        $status = (int)($data['status'] ?? 0);
        return static::getStatusMap()[$status] ?? '';
    }

    /**
     * 获取器：图片列表
     * @param $value
     * @param $data
     * @return array
     */
    public function getImagesAttr($value, $data): array
    {
        $imageIds = $data['image_ids'] ?? [];
        if (is_string($imageIds)) {
            $imageIds = helper::jsonDecode($imageIds) ?: [];
        }
        $imageIds = array_values(array_filter(array_map(static function ($item) {
            return (int)$item;
        }, (array)$imageIds)));
        if (empty($imageIds)) {
            return [];
        }
        $imageList = (new UploadFile)
            ->where('file_id', 'in', $imageIds)
            ->where('store_id', '=', self::$storeId)
            ->where('file_type', '=', FileTypeEnum::IMAGE)
            ->where('is_delete', '=', 0)
            ->select()
            ->toArray();
        $imageMap = helper::arrayColumn2Key($imageList, 'file_id');
        $result = [];
        foreach ($imageIds as $imageId) {
            isset($imageMap[$imageId]) && $result[] = $imageMap[$imageId];
        }
        return $result;
    }

    /**
     * 详情
     * @param int $feedbackId
     * @param array $with
     * @return static|array|null
     */
    public static function detail(int $feedbackId, array $with = [])
    {
        return static::get($feedbackId, $with);
    }
}

