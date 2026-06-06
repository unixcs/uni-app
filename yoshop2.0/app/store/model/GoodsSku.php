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

use app\common\model\GoodsSku as GoodsSkuModel;
use app\common\enum\goods\SpecType as SpecTypeEnum;

/**
 * 商品规格模型
 * Class GoodsSku
 * @package app\store\model
 */
class GoodsSku extends GoodsSkuModel
{
    /**
     * 更新商品sku记录
     * @param int $goodsId
     * @param int $specType
     * @param array $skuList
     * @return array|bool|false
     */
    public static function edit(int $goodsId, int $specType = SpecTypeEnum::SINGLE, array $skuList = [])
    {
        // 删除所有的sku记录
        static::deleteAll(['goods_id' => $goodsId]);
        // 新增商品sku记录
        return static::add($goodsId, $specType, $skuList);
    }
}
