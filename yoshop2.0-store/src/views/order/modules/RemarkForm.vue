<template>
  <a-modal
    :title="title"
    :width="560"
    :visible="visible"
    :isLoading="isLoading"
    :confirmLoading="isLoading"
    :maskClosable="false"
    @ok="handleSubmit"
    @cancel="handleCancel"
  >
    <a-spin :spinning="isLoading">
      <a-form :form="form">
        <a-form-item
          label="备注内容"
          :labelCol="labelCol"
          :wrapperCol="wrapperCol"
          extra="商家备注内容仅后台可见，用户端不可见"
        >
          <a-textarea
            v-decorator="['content', { rules: [{ required: true, message: '请输入备注内容' }] }]"
            :auto-size="{ minRows: 4, maxRows: 10 }"
          />
        </a-form-item>
      </a-form>
    </a-spin>
  </a-modal>
</template>

<script>
import _ from 'lodash'
import * as Api from '@/api/order/event'

export default {
  data () {
    return {
      // 对话框标题
      title: '商家备注',
      // 标签布局属性
      labelCol: { span: 6 },
      // 输入框布局属性
      wrapperCol: { span: 14 },
      // modal(对话框)是否可见
      visible: false,
      // modal(对话框)确定按钮 loading
      isLoading: false,
      // 当前表单元素
      form: this.$form.createForm(this),
      // 当前记录
      record: {}
    }
  },
  created () {
  },
  methods: {

    // 显示对话框
    show (record) {
      // 显示窗口
      this.visible = true
      // 当前记录
      this.record = record
      // 设置默认值
      this.setFieldsValue()
    },

    // 设置默认值
    setFieldsValue () {
      const { record, $nextTick, form: { setFieldsValue } } = this
      $nextTick(() => {
        setFieldsValue({ content: record.merchant_remark })
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

    // 关闭对话框事件
    handleCancel () {
      this.visible = false
      this.form.resetFields()
    },

    // 提交到后端api
    onFormSubmit (values) {
      this.isLoading = true
      Api.updateRemark({ orderId: this.record.order_id, form: values })
        .then(result => {
          // 显示成功
          this.$message.success(result.message, 1.5)
          // 关闭对话框事件
          this.handleCancel()
          // 通知父端组件提交完成了
          this.$emit('handleSubmit', values)
        })
        .finally(() => this.isLoading = false)
    }

  }
}
</script>
