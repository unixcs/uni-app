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
use app\api\model\Cart as CartModel;
use app\api\service\Cart as CartService;
use cores\exception\BaseException;

/**
 * 购物车管理
 * Class Cart
 * @package app\api\controller
 */
class Cart extends Controller
{
    private function disabled(): Json
    {
        return $this->renderError('服务订单不支持购物车');
    }

    /**
     * 购物车商品列表
     * @return Json
     * @throws BaseException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \cores\exception\BaseException
     */
    public function list(): Json
    {
        return $this->disabled();
    }

    /**
     * 购物车商品总数量
     * @return Json
     * @throws BaseException
     */
    public function total(): Json
    {
        return $this->disabled();
    }

    /**
     * 加入购物车
     * @param int $goodsId 商品ID
     * @param string $goodsSkuId 商品sku索引
     * @param int $goodsNum 商品数量
     * @return Json
     * @throws BaseException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function add(int $goodsId, string $goodsSkuId, int $goodsNum): Json
    {
        return $this->disabled();
    }

    /**
     * 更新购物车商品数量
     * @param int $goodsId 商品ID
     * @param string $goodsSkuId 商品sku索引
     * @param int $goodsNum 商品数量
     * @return Json
     * @throws BaseException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function update(int $goodsId, string $goodsSkuId, int $goodsNum): Json
    {
        return $this->disabled();
    }

    /**
     * 删除购物车中指定记录
     * @param array $cartIds 购物车ID集, 如果为空删除所有
     * @return Json
     * @throws BaseException
     */
    public function clear(array $cartIds = []): Json
    {
        return $this->disabled();
    }
}
