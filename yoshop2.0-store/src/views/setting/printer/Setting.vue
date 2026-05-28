<template>
  <a-card :bordered="false">
    <div class="card-title">{{ $route.meta.title }}</div>
    <a-spin :spinning="isLoading">
      <a-form :form="form" @submit="handleSubmit">
        <a-form-item label="是否开启小票打印" :labelCol="labelCol" :wrapperCol="wrapperCol">
          <a-radio-group v-decorator="['is_open', { initialValue: 1, rules: [{ required: true }] }]">
            <a-radio :value="1">开启</a-radio>
            <a-radio :value="0">关闭</a-radio>
          </a-radio-group>
        </a-form-item>
        <a-form-item label="订单打印机" :labelCol="labelCol" :wrapperCol="wrapperCol">
          <a-select
            style="width: 300px;"
            placeholder="请选择订单打印机"
            v-decorator="['printer_id', { rules: [{ required: true, message: '订单打印机至少选择一个' }] }]"
          >
            <a-select-option
              v-for="(item, index) in printerList"
              :key="index"
              :value="item.printer_id"
            >{{ item.printer_name }}</a-select-option>
          </a-select>
        </a-form-item>
        <a-form-item label="订单打印方式" :labelCol="labelCol" :wrapperCol="wrapperCol">
          <a-checkbox-group
            v-decorator="['order_status', { rules: [{ required: true, message: '订单打印方式至少选择一个' }] }]"
          >
            <a-checkbox :value="20">订单付款时</a-checkbox>
          </a-checkbox-group>
        </a-form-item>
        <a-form-item :wrapperCol="{ span: wrapperCol.span, offset: labelCol.span }">
          <a-button type="primary" html-type="submit">提交</a-button>
        </a-form-item>
      </a-form>
    </a-spin>
  </a-card>
</template>

<script>
import pick from 'lodash.pick'
import * as Api from '@/api/setting/store'
import * as PrinterApi from '@/api/setting/printer'
import StorageEnum from '@/common/enum/file/Storage'
import { isEmpty } from '@/utils/util'

export default {
  data () {
    return {
      // 当前设置项的key
      key: 'printer',
      // 标签布局属性
      labelCol: { span: 4 },
      // 输入框布局属性
      wrapperCol: { span: 10 },
      // loading状态
      isLoading: false,
      // 当前表单元素
      form: this.$form.createForm(this),
      // 当前记录详情
      record: {},
      // 打印机列表
      printerList: [],
      // 枚举类
      StorageEnum
    }
  },
  // 初始化数据
  async created () {
    // 获取当前详情记录
    await this.getDetail()
    // 获取订单打印机列表
    await this.getPrinterList()
    // 设置默认值
    this.setFieldsValue()
  },
  methods: {

    // 获取当前详情记录
    async getDetail () {
      this.isLoading = true
      return Api.detail(this.key)
        .then(result => {
          // 当前记录
          this.record = result.data.values
        })
        .finally(() => this.isLoading = false)
    },

    // 获取订单打印机列表
    async getPrinterList () {
      this.isLoading = true
      return PrinterApi.all()
        .then(result => {
          this.printerList = result.data.list
        })
        .finally(() => this.isLoading = false)
    },

    // 设置默认值
    setFieldsValue () {
      const { record, $nextTick, form } = this
      !isEmpty(form.getFieldsValue()) && $nextTick(() => {
        const fields = pick(record, ['is_open', 'printer_id', 'order_status'])
        fields.printer_id = parseInt(fields.printer_id)
        !fields.printer_id && (delete fields.printer_id)
        form.setFieldsValue(fields)
      })
    },

    // 确认按钮
    handleSubmit (e) {
      e.preventDefault()
      // 表单验证
      const { form: { validateFields } } = this
      validateFields((errors, values) => {
        // 提交到后端api
        !errors && this.onFormSubmit(values)
      })
    },

    // 提交到后端api
    onFormSubmit (values) {
      this.isLoading = true
      Api.update(this.key, { form: values })
        .then(result => this.$message.success(result.message, 1.5))
        .finally(() => this.isLoading = false)
    }

  }
}
</script>
<style lang="less" scoped>
/deep/.ant-form-item-control {
  padding-left: 10px;

  .ant-form-item-control {
    padding-left: 0;
  }
}
</style>
