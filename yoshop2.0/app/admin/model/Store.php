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

namespace app\admin\model;

use app\common\model\Store as StoreModel;
use app\admin\model\store\User as StoreUserModel;
use think\model\relation\HasOne;

/**
 * 商家记录表模型
 * Class Store
 * @package app\admin\model
 */
class Store extends StoreModel
{
    /**
     * 新增商城
     * 单商城模式: 仅当当前没有有效商城时允许新建
     * @param array $data
     * @return bool
     */
    public function add(array $data): bool
    {
        $data['user_name'] = strtolower(trim($data['user_name'] ?? ''));
        if (empty($data['store_name'])) {
            $this->error = '商城名称不能为空';
            return false;
        }
        if (empty($data['user_name'])) {
            $this->error = '商家用户名不能为空';
            return false;
        }
        if (empty($data['password']) || empty($data['password_confirm'])) {
            $this->error = '登录密码不能为空';
            return false;
        }
        if ($data['password'] !== $data['password_confirm']) {
            $this->error = '确认密码不正确';
            return false;
        }

        $activeStoreId = self::withoutGlobalScope()
            ->where('is_recycle', '=', 0)
            ->where('is_delete', '=', 0)
            ->value('store_id');
        if (!empty($activeStoreId)) {
            $this->error = '当前版本仅支持单商城，请先删除现有商城';
            return false;
        }
        if (StoreUserModel::checkExist($data['user_name'])) {
            $this->error = '商家用户名已存在';
            return false;
        }

        return (bool)$this->transaction(function () use ($data) {
            $this->save([
                'store_name' => trim($data['store_name']),
                'remark' => trim((string)($data['remark'] ?? '')),
                'sort' => (int)($data['sort'] ?? 100),
                'describe' => '',
                'logo_image_id' => 0,
                'custom_domain' => '',
                'is_recycle' => 0,
                'is_delete' => 0,
            ]);
            (new StoreUserModel)->add((int)$this['store_id'], $data);
            return true;
        });
    }

    /**
     * 关联商家用户记录 (超级管理员)
     * @return HasOne
     */
    public function superUser(): HasOne
    {
        $module = self::getCalledModule();
        return $this->hasOne("app\\{$module}\\model\\store\\User")
            ->where('is_super', '=', 1);
    }

    /**
     * 获取列表数据
     * @param bool $isRecycle
     * @return \think\Paginator
     * @throws \think\db\exception\DbException
     */
    public function getList(bool $isRecycle = false): \think\Paginator
    {
        return $this->with(['superUser'])
            ->where('is_recycle', '=', (int)$isRecycle)
            ->where('is_delete', '=', 0)
            ->order(['sort' => 'asc', 'create_time' => 'desc'])
            ->paginate(15);
    }

    /**
     * 更新记录
     * @param array $data
     * @return bool|mixed
     */
    public function edit(array $data)
    {
        if (!empty($data['password']) && ($data['password'] !== $data['password_confirm'])) {
            $this->error = '确认密码不正确';
            return false;
        }
        if ($this['superUser']['user_name'] !== $data['user_name']
            && StoreUserModel::checkExist($data['user_name'])) {
            $this->error = '商家用户名已存在';
            return false;
        }
        return $this->transaction(function () use ($data) {
            // 更新商户记录
            $this->save(['remark' => $data['remark'], 'sort' => $data['sort']]);
            // 更新商家用户信息
            (new StoreUserModel)->edit((int)$this['store_id'], $data);
            return true;
        });
    }

    /**
     * 移入移出回收站
     * @param bool $isRecycle
     * @return bool|false
     */
    public function recycle(bool $isRecycle = true): bool
    {
        if (!$isRecycle) {
            $activeStoreId = self::withoutGlobalScope()
                ->where('is_recycle', '=', 0)
                ->where('is_delete', '=', 0)
                ->where('store_id', '<>', (int)$this['store_id'])
                ->value('store_id');
            if (!empty($activeStoreId)) {
                $this->error = '当前版本仅支持单商城，请先删除现有商城';
                return false;
            }
        }
        return (bool)$this->transaction(function () use ($isRecycle) {
            $this->save(['is_recycle' => (int)$isRecycle]);
            StoreUserModel::setDelete((int)$this['store_id'], $isRecycle);
            return true;
        });
    }
}
