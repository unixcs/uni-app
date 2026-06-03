<?php
// +----------------------------------------------------------------------
// | 萤火商城系统 [ 致力于通过产品和服务，帮助商家高效化开拓市场 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2017~2025 https://www.yiovo.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed 这不是一个自由软件，不允许对程序代码以任何形式任何目的的再发行
// +----------------------------------------------------------------------
// | Author: 萤火科技 <admin@yiovo.com>
// +----------------------------------------------------------------------
declare (strict_types=1);

namespace app\store\service\order;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use app\store\model\{Order as OrderModel, order\Export as ExportModel};
use app\common\library\helper;
use app\common\service\BaseService;
use app\common\service\Goods as GoodsService;
use app\common\enum\order\{
    PayStatus as PayStatusEnum,
    OrderSource as OrderSourceEnum,
    OrderStatus as OrderStatusEnum,
    export\ExportStatus as ExportStatusEnum
};
use app\common\enum\payment\Method as PaymentMethodEnum;
use cores\exception\BaseException;

/**
 * 服务层：订单导出Excel
 * Class Export
 * @package app\store\service\order
 */
class Export extends BaseService
{
    // 表格文件标题名称
    private string $title = '订单导出结果';

    /**
     * 执行订单导出excel
     * @param array $param
     * @return bool
     * @throws BaseException
     */
    public function exportOrder(array $param): bool
    {
        // 根据条件查询订单列表
        $orderList = $this->getOrderList($param);
        // 格式化生成表格数据
        $excelList = $this->getExcelList($orderList->toArray(), $param['columns']);
        // 获取导出的记录列名集
        $columns = $this->getColumns($param['columns']);
        // 输出并写入到excel文件
        $filePath = $this->outputExcel($columns, $excelList);
        // 新增订单导出记录
        $this->record($param, $filePath);
        return true;
    }

    /**
     * 根据条件查询订单列表
     * @param array $param
     * @return mixed
     * @throws BaseException
     */
    private function getOrderList(array $param)
    {
        // 根据条件查询订单列表
        $orderList = (new OrderModel)->getListAll($param);
        if (empty($orderList) || $orderList->isEmpty()) {
            throwError('很抱歉，没有查询到订单数据');
        }
        return $orderList;
    }

    /**
     * 输出并写入到excel文件
     * @param array $columns 列名
     * @param array $excelList 表格内容
     * @return string
     * @throws BaseException
     */
    private function outputExcel(array $columns, array $excelList): string
    {
        // 生成工作表
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet()->setTitle($this->title);
        // 列宽
        $sheet->getDefaultColumnDimension()->setWidth(24);
        // 默认行高
        $sheet->getDefaultRowDimension()->setRowHeight(20);
        // 字体加粗(第一行)
        $sheet->getStyle('1')->getFont()->setBold(true);
        // 写入标题数据
        foreach ($columns as $key => $val) {
            $sheet->setCellValueByColumnAndRow($key + 1, 1, $val);
        }
        // 写入内容数据
        foreach ($excelList as $key => $item) {
            $row = $key + 2;
            foreach (array_values($item) as $k => $val) {
                $sheet->setCellValueByColumnAndRow($k + 1, $row, $val);
            }
        }
        // 生成文件路径
        $fileName = 'order-' . time() . '.xlsx';
        // 获取导出的文件夹路径
        $filePath = $this->getExportPath();
        // 保存到文件
        try {
            $writer = new Xlsx($spreadsheet);
            $writer->save(public_path() . $filePath . $fileName);
        } catch (\PhpOffice\PhpSpreadsheet\Writer\Exception $e) {
            throwError($e->getMessage());
        }
        return $filePath . $fileName;
    }

    /**
     * 获取导出的文件夹路径
     * @return string
     */
    private function getExportPath(): string
    {
        $filePath = 'downloads/' . $this->getStoreId() . '/';
        !is_dir($filePath) && mkdir($filePath, 0755, true);
        return $filePath;
    }

    /**
     * 写入订单导出记录
     * @param array $param
     * @param string $filePath
     * @return void
     */
    private function record(array $param, string $filePath): void
    {
        // 生成记录数据
        $data = [
            'file_path' => $filePath,
            'status' => ExportStatusEnum::COMPLETED,
            'store_id' => $this->getStoreId(),
        ];
        // 起止时间
        if (!empty($param['betweenTime'])) {
            $times = between_time($param['betweenTime']);
            $data['start_time'] = $times['start_time'];
            $data['end_time'] = $times['end_time'];
        }
        // 新增记录
        $model = new ExportModel;
        $model->add($data);
    }

    /**
     * 获取导出的记录列名集
     * @param array $onlyFields
     * @return array
     */
    private function getColumns(array $onlyFields): array
    {
        return array_values(helper::pick($this->dictionary(), $onlyFields));
    }

    /**
     * 订单记录字典
     * @return string[]
     */
    private function dictionary(): array
    {
        return [
            'order_id' => '订单ID',
            'order_no' => '订单号',
            'goods_detail' => '服务信息',
            'total_price' => '服务总额',
            'update_price' => '后台改价',
            'pay_price' => '订单实付款',
            'pay_method' => '支付方式',
            'create_time' => '下单时间',
            'user_info' => '买家信息',
            'buyer_remark' => '买家备注',
            'contact_name' => '联系人',
            'contact_mobile' => '联系电话',
            'time_preference' => '服务时间偏好',
            'pay_status' => '付款状态',
            'pay_time' => '付款时间',
            'order_status' => '订单状态',
            'order_source' => '订单来源',
            'out_trade_no' => '第三方支付订单号',
            'trade_no' => '支付流水号',
        ];
    }

    /**
     * 格式化生成表格数据
     * @param array $orderList
     * @param array $onlyFields
     * @return array
     */
    private function getExcelList(array $orderList, array $onlyFields): array
    {
        // 获取订单表格数据
        $excelList = $this->getOrderDataForExcel($orderList);
        // 仅输出用户设置的字段
        $data = [];
        foreach ($excelList as $item) {
            $data[] = helper::pick($item, $onlyFields);
        }
        return $data;
    }

    /**
     * 获取订单列表数据(用于生成Excel)
     * @param $orderList
     * @return array
     */
    private function getOrderDataForExcel($orderList): array
    {
        // 表格内容
        $dataArray = [];
        foreach ($orderList as $order) {
            $dataArray[] = [
                'order_id' => $this->filterValue($order['order_id']),
                'order_no' => $this->filterValue($order['order_no']),
                'goods_detail' => $this->filterGoodsInfo($order),
                'total_price' => $this->filterValue($order['total_price']) . '元',
                'update_price' => "{$order['update_price']['symbol']}{$order['update_price']['value']}元",
                'pay_price' => $this->filterValue($order['pay_price']) . '元',
                'pay_method' => $this->filterPayMethod($order['pay_method']),
                'create_time' => $this->filterValue($order['create_time']),
                'user_info' => $this->filterValue($order['user']['nick_name']),
                'buyer_remark' => $this->filterValue($order['buyer_remark']),
                'contact_name' => $this->filterValue($order['contact_name']),
                'contact_mobile' => $this->filterValue($order['contact_mobile']),
                'time_preference' => $this->filterValue($order['time_preference']),
                'pay_status' => PayStatusEnum::data()[$order['pay_status']]['name'],
                'pay_time' => $order['pay_time'],
                'order_status' => $order['state_text'] ?: OrderStatusEnum::data()[$order['order_status']]['name'],
                'order_source' => OrderSourceEnum::data()[$order['order_source']]['name'],
                'out_trade_no' => !empty($order['trade']) ? $this->filterValue($order['trade']['out_trade_no']) : '',
                'trade_no' => !empty($order['trade']) ? $this->filterValue($order['trade']['trade_no']) : '',
            ];
        }
        return $dataArray;
    }

    /**
     * 格式化支付方式
     * @param string $payMethod
     * @return string
     */
    private function filterPayMethod(string $payMethod): string
    {
        $enum = PaymentMethodEnum::data();
        return isset($enum[$payMethod]) ? $enum[$payMethod]['name'] : '';
    }

    /**
     * 格式化商品信息
     * @param $order
     * @return string
     */
    private function filterGoodsInfo($order): string
    {
        $content = '';
        foreach ($order['goods'] as $key => $goods) {
            $content .= ($key + 1) . ".服务名称：{$goods['goods_name']}\n";
            if (!empty($goods['goods_props'])) {
                $goodsAttr = GoodsService::goodsPropsToAttr($goods['goods_props']);
                $content .= "　服务选项：{$goodsAttr}\n";
            }
            $content .= "　购买数量：{$goods['total_num']}\n";
            $content .= "　服务总价：{$goods['total_price']}元\n\n";
        }
        return $content;
    }

    /**
     * 表格值过滤
     * @param $value
     * @return string
     */
    private function filterValue($value): string
    {
        return "\t{$value}\t";
    }
}
