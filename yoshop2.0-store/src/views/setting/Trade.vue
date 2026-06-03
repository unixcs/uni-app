<template>
  <a-card :bordered="false">
    <div class="card-title">{{ $route.meta.title }}</div>
    <a-spin :spinning="isLoading">
      <a-form :form="form" @submit="handleSubmit">
        <a-form-item label="未支付订单" :labelCol="labelCol" :wrapperCol="wrapperCol">
          <div class="clearfix">
            <a-input-number
              class="fl-l"
              :min="0"
              :precision="0"
              v-decorator="['order.closeHours', { rules: [{ required: true, message: '不能为空' }] }]"
            />
            <span class="input-text_right">小时后自动关闭</span>
          </div>
          <div class="form-item-help">
            <p class="extra">如果在期间订单未付款，系统自动关闭，设置0小时不自动关闭</p>
          </div>
        </a-form-item>
        <a-form-item label="服务订单退款" :labelCol="labelCol" :wrapperCol="wrapperCol">
          <div class="clearfix">
            <a-input-number
              class="fl-l"
              :min="0"
              :precision="0"
              v-decorator="['order.refund_days', { rules: [{ required: true, message: '不能为空' }] }]"
            />
            <span class="input-text_right">天内允许申请退款</span>
          </div>
          <div class="form-item-help">
            <p class="extra">服务订单完成后，用户在指定期限内可申请退款，设置0天不允许申请</p>
          </div>
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
import { isEmpty } from '@/utils/util'

export default {
  data () {
    return {
      // 当前设置项的key
      key: 'trade',
      // 标签布局属性
      labelCol: { span: 4 },
      // 输入框布局属性
      wrapperCol: { span: 10 },
      // loading状态
      isLoading: false,
      // 当前表单元素
      form: this.$form.createForm(this),
      // 当前记录详情
      record: {}
    }
  },
  // 初始化数据
  created () {
    // 获取当前详情记录
    this.getDetail()
  },
  methods: {

    // 获取当前详情记录
    getDetail () {
      this.isLoading = true
      Api.detail(this.key)
        .then(result => {
          // 当前记录
          this.record = result.data.values
          // 设置默认值
          this.setFieldsValue()
        })
        .finally(() => this.isLoading = false)
    },

    // 设置默认值
    setFieldsValue () {
      const { record, $nextTick, form } = this
      !isEmpty(form.getFieldsValue()) && $nextTick(() => {
        form.setFieldsValue({
          order: pick(record.order, ['closeHours', 'refund_days'])
        })
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
.ant-divider {
  margin-top: 60px !important;
}
.ant-input-number {
  width: 160px;
}
.input-text_right {
  margin-left: 10px;
}
</style>
