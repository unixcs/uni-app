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

namespace app\store\service\goods;

use app\common\service\Goods as GoodsService;

/**
 * 服务层：商品关联验证
 * Class Apply
 * @package app\store\service\goods
 */
class Apply extends GoodsService
{
    /**
     * 验证商品规格属性是否锁定
     * @param int $goodsId
     * @return bool
     */
    public static function checkSpecLocked(int $goodsId): bool
    {
        return false;
    }

    /**
     * 验证商品是否允许删除
     * @param int $goodsId
     * @return bool
     */
    public static function checkIsAllowDelete(int $goodsId): bool
    {
        return true;
    }
}
