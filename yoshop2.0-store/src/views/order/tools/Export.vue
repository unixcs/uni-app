<template>
  <div class="container">
    <a-card class="mb-20" :bordered="false">
      <div class="card-title">{{ $route.meta.title }}</div>
      <a-alert class="mb-20" :showIcon="true" message="功能说明：用于订单数据导出，导出后的文件请用Excel打开查看" banner />
      <a-form class="my-form" :form="myForm" layout="inline" @submit="handleSubmit">
        <a-row :gutter="24">
          <a-col :span="12">
            <a-form-item label="下单时间">
              <a-range-picker
                format="YYYY-MM-DD"
                v-decorator="['betweenTime', { rules: [{ required: true, message: '请选择下单时间' }] }]"
              />
            </a-form-item>
          </a-col>
          <a-col :span="12">
            <a-form-item label="订单类型">
              <a-select
                v-decorator="['dataType', { initialValue: 'all', rules: [{ required: true, message: '请选择订单类型' }] }]"
              >
                <a-select-option
                  v-for="(item, index) in DataTypeEnum.data"
                  :key="index"
                  :value="item.value"
                >{{ item.name }}</a-select-option>
              </a-select>
            </a-form-item>
          </a-col>
          <a-col :span="12">
            <a-form-item label="订单来源">
              <a-select v-decorator="['orderSource', { initialValue: -1 }]">
                <a-select-option :value="-1">全部</a-select-option>
                <a-select-option
                  v-for="(item, index) in OrderSourceEnum.data"
                  :key="index"
                  :value="item.value"
                >{{ item.name }}</a-select-option>
              </a-select>
            </a-form-item>
          </a-col>
          <a-col :span="12">
            <a-form-item label="支付方式">
              <a-select v-decorator="['payMethod', { initialValue: 0 }]">
                <a-select-option :value="0">全部</a-select-option>
                <a-select-option
                  v-for="(item, index) in PaymentMethodEnum.data"
                  :key="index"
                  :value="item.value"
                >{{ item.name }}</a-select-option>
              </a-select>
            </a-form-item>
          </a-col>
          <a-col :span="12">
            <a-form-item label="配送方式">
              <a-select v-decorator="['deliveryType', { initialValue: -1 }]">
                <a-select-option :value="-1">全部</a-select-option>
                <a-select-option
                  v-for="(item, index) in DeliveryTypeEnum.data"
                  :key="index"
                  :value="item.value"
                >{{ item.name }}</a-select-option>
              </a-select>
            </a-form-item>
          </a-col>
          <a-col :span="24">
            <a-form-item class="item-main" label="导出的字段">
              <a-checkbox-group
                v-decorator="['columns', { initialValue: columnValue, rules: [{ required: true, message: '请选择导出的字段' }] }]"
                :options="columnData"
              />
            </a-form-item>
          </a-col>
          <a-col :span="24">
            <a-form-item class="form-btn">
              <a-button
                class="btn-main"
                type="primary"
                icon="download"
                html-type="submit"
                :loading="isBtnLoading"
              >导出</a-button>
              <a-button @click="handleReset">重置</a-button>
            </a-form-item>
          </a-col>
        </a-row>
      </a-form>
    </a-card>
    <a-card :bordered="false">
      <div class="card-title">历史导出记录</div>
      <ExportList ref="ExportList" />
    </a-card>
  </div>
</template>

<script>
import storage from 'store'
import moment from 'moment'
import { assignment, inArray, isEmpty } from '@/utils/util'
import { filterModules } from '@/core/app'
import * as Api from '@/api/order/export'
import { DeliveryTypeEnum, OrderSourceEnum, OrderStatusEnum, DataTypeEnum } from '@/common/enum/order'
import { PaymentMethodEnum } from '@/common/enum/payment'
import { ExportList } from './modules'

// 订单字段记录
const columnData = filterModules([
  { label: '订单ID', value: 'order_id' },
  { label: '订单号', value: 'order_no' },
  { label: '商品信息', value: 'goods_detail' },
  { label: '商品总额', value: 'total_price' },
  { label: '优惠券抵扣金额', value: 'coupon_money', moduleKey: 'market-coupon' },
  { label: '积分抵扣金额', value: 'points_money', moduleKey: 'market-points' },
  { label: '后台改价', value: 'update_price', moduleKey: 'order-updatePrice' },
  { label: '运费金额', value: 'express_price' },
  { label: '订单实付款', value: 'pay_price' },
  { label: '支付方式', value: 'pay_method' },
  { label: '下单时间', value: 'create_time' },
  { label: '买家信息', value: 'user_info' },
  { label: '买家留言', value: 'buyer_remark' },
  { label: '配送方式', value: 'delivery_type' },
  { label: '收货人', value: 'receipt_name' },
  { label: '联系电话', value: 'receipt_phone' },
  { label: '收货地址', value: 'receipt_address' },
  { label: '付款状态', value: 'pay_status' },
  { label: '付款时间', value: 'pay_time' },
  { label: '发货状态', value: 'delivery_status' },
  { label: '发货时间', value: 'delivery_time' },
  { label: '收货状态', value: 'receipt_status' },
  { label: '收货时间', value: 'receipt_time' },
  { label: '订单状态', value: 'order_status' },
  { label: '是否已评价', value: 'is_comment' },
  { label: '订单来源', value: 'order_source' },
  { label: '第三方支付订单号', value: 'out_trade_no' },
  { label: '支付流水号', value: 'trade_no' },
])

// 默认选中的字段
const columnValue = [
  'order_no'
  , 'goods_detail'
  , 'total_price'
  , 'coupon_money'
  , 'points_money'
  , 'update_price'
  , 'express_price'
  , 'pay_price'
  , 'pay_method'
  , 'create_time'
  , 'user_info'
  , 'buyer_remark'
  , 'delivery_type'
  , 'receipt_name'
  , 'receipt_phone'
  , 'receipt_address'
  , 'pay_status'
  , 'pay_time'
  , 'delivery_status'
  , 'delivery_time'
  , 'receipt_status'
  , 'receipt_time'
  , 'order_status'
  , 'order_source'
]

const LOCAL_DATA_KEY = 'order_export_form'

export default {
  name: 'Export',
  components: {
    ExportList
  },
  data () {
    return {
      // 当前表单元素
      myForm: this.$form.createForm(this),
      // 正在加载
      isLoading: false,
      isBtnLoading: false,
      // 标签布局属性
      labelCol: { span: 8 },
      // 输入框布局属性
      wrapperCol: { span: 16 },
      // 订单字段数据
      columnData,
      columnValue
    }
  },
  created () {
    // 设置默认值
    this.setFieldsValue()
  },
  beforeCreate () {
    // 批量给当前实例赋值
    assignment(this, {
      inArray,
      DeliveryTypeEnum,
      OrderSourceEnum,
      OrderStatusEnum,
      PaymentMethodEnum,
      DataTypeEnum
    })
  },

  methods: {

    // 设置默认值
    setFieldsValue () {
      // 从本地获取缓存数据
      const data = storage.get(LOCAL_DATA_KEY)
      if (!isEmpty(data)) {
        data.betweenTime = [moment(data.betweenTime[0]), moment(data.betweenTime[1])]
        // 设置默认表单内容
        const { myForm: { setFieldsValue } } = this
        this.$nextTick(() => setFieldsValue(data))
      }
    },

    // 表单提交
    handleSubmit (e) {
      e.preventDefault()
      this.myForm.validateFields((error, values) => {
        if (!error) {
          // 将表单数据保存到本地
          this.onSave2Local(values)
          // 提交到后端api
          this.onFormSubmit(values)
        }
      })
    },

    // 将表单数据保存到本地 (15天)
    onSave2Local (data) {
      storage.set(LOCAL_DATA_KEY, data, 15 * 24 * 60 * 60 * 1000)
    },

    // 提交到后端api
    onFormSubmit (data) {
      const app = this
      app.isLoading = true
      app.isBtnLoading = true
      Api.exportOrder(data)
        .then(result => {
          // 显示提示信息
          app.$message.success(result.message, 1.5)
          // 刷新历史导出记录
          app.$refs.ExportList.handleRefresh()
        })
        .finally(result => {
          app.isLoading = false
          app.isBtnLoading = false
        })
    },

    // 重置搜索表单
    handleReset () {
      this.myForm.resetFields()
      storage.remove(LOCAL_DATA_KEY)
    },

  }

}
</script>

<style lang="less" scoped>
// 表单样式
.my-form {
  width: 980px;
  padding-left: 15px;

  /deep/.ant-form-item {
    margin-bottom: 0;
    min-height: 57px;

    .ant-form-item-label {
      width: 85px;
      margin-right: 15px;
      text-align: right;
    }
    .ant-form-item-control-wrapper {
      min-width: 220px;
    }

    &.item-main {
      .ant-form-item-control-wrapper {
        width: 805px;
        padding-top: 12px;
      }
    }

    &.form-btn {
      margin-top: 12px;
      width: auto;
      .btn-main {
        margin-right: 10px;
      }
    }
  }

  // 多选框元素
  /deep/.ant-checkbox-group-item {
    width: 160px;
    margin-bottom: 12px;
  }
}
</style>
