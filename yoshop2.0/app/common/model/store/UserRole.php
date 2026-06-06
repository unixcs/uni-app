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

namespace app\common\model\store;

use cores\BaseModel;

/**
 * 商家用户角色模型
 * Class UserRole
 * @package app\common\model\admin
 */
class UserRole extends BaseModel
{
    // 定义表名
    protected $name = 'store_user_role';

    // 定义主键
    protected $pk = 'id';

    protected $updateTime = false;

}