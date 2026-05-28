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
        <a-form-item label="收货人" :labelCol="labelCol" :wrapperCol="wrapperCol">
          <a-input
            placeholder="请输入收货人姓名"
            v-decorator="['name', { rules: [{ required: true, min: 2, message: '请输入至少2个字符' }] }]"
          />
        </a-form-item>
        <a-form-item label="联系电话" :labelCol="labelCol" :wrapperCol="wrapperCol">
          <a-input placeholder="请输入联系电话" v-decorator="['phone', { rules: [{ required: true }] }]" />
        </a-form-item>

        <a-form-item label="省市区" :labelCol="labelCol" :wrapperCol="wrapperCol">
          <SelectRegion
            placeholder="请选择省市区"
            v-decorator="['cascader', { rules: [{ required: true, message: '请选择省市区' }] }]"
          />
        </a-form-item>
        <a-form-item label="详细地址" :labelCol="labelCol" :wrapperCol="wrapperCol">
          <a-input placeholder="请输入详细地址" v-decorator="['detail', { rules: [{ required: true }] }]" />
        </a-form-item>
      </a-form>
    </a-spin>
  </a-modal>
</template>

<script>
import { pick } from 'lodash'
import * as Api from '@/api/order/event'
import { SelectRegion } from '@/components'

export default {
  components: {
    SelectRegion
  },
  data () {
    return {
      // 对话框标题
      title: '修改收货地址',
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
        setFieldsValue(pick(record.address, ['name', 'phone', 'cascader', 'detail']))
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
      Api.updateAddress(this.record['order_id'], { form: values })
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
