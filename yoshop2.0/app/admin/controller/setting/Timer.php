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

namespace app\admin\controller\setting;

use think\response\Json;
use app\admin\controller\Controller;
use app\common\service\system\Process as SystemProcessService;

/**
 * 定时任务管理
 * Class Timer
 * @package app\admin\controller
 */
class Timer extends Controller
{
    /**
     * 测试定时任务是否开启
     * @return Json
     */
    public function test(): Json
    {
        // 等待3秒钟（提高监测的准确性）
        sleep(3);
        // 判断定时任务的最后执行时间是否在10秒内
        if (\time() - SystemProcessService::getLastWorkingTime('timer') <= 10) {
            return $this->renderSuccess('恭喜您，定时任务已开启');
        }
        return $this->renderError('很抱歉，定时任务未开启');
    }
}