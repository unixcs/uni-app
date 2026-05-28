<template>
  <a-modal
    :title="title"
    :width="720"
    :visible="visible"
    :confirmLoading="confirmLoading"
    :maskClosable="false"
    @ok="handleSubmit"
    @cancel="handleCancel"
  >
    <a-spin :spinning="confirmLoading">
      <a-form :form="form">
        <a-form-item label="打印机名称" :labelCol="labelCol" :wrapperCol="wrapperCol">
          <a-input
            v-decorator="['printer_name', { rules: [{ required: true, min: 2, message: '请输入至少2个字符' }] }]"
          />
        </a-form-item>
        <a-form-item label="打印机类型" :labelCol="labelCol" :wrapperCol="wrapperCol">
          <a-radio-group
            v-decorator="['printer_type', { initialValue: PrinterEnum.FEI_E_YUN.value, rules: [{ required: true }] }]"
          >
            <a-radio
              v-for="(item, index) in PrinterEnum.data"
              :key="index"
              :value="item.value"
            >{{ item.name }}</a-radio>
          </a-radio-group>
        </a-form-item>

        <!-- 飞鹅打印机 -->
        <div
          v-if="form.getFieldValue('printer_type') == PrinterEnum.FEI_E_YUN.value"
          :class="PrinterEnum.FEI_E_YUN.value"
        >
          <a-form-item
            label="USER"
            :labelCol="labelCol"
            :wrapperCol="wrapperCol"
            extra="飞鹅云后台注册用户名"
          >
            <a-input v-decorator="['printer_config.USER', { rules: [{ required: true }] }]" />
          </a-form-item>
          <a-form-item
            label="UKEY"
            :labelCol="labelCol"
            :wrapperCol="wrapperCol"
            extra="飞鹅云后台登录生成的UKEY"
          >
            <a-input v-decorator="['printer_config.UKEY', { rules: [{ required: true }] }]" />
          </a-form-item>
          <a-form-item
            label="打印机编号"
            :labelCol="labelCol"
            :wrapperCol="wrapperCol"
            extra="打印机编号为9位数字，查看飞鹅打印机底部贴纸上面的编号"
          >
            <a-input v-decorator="['printer_config.SN', { rules: [{ required: true }] }]" />
          </a-form-item>
        </div>

        <!-- 365打印机 -->
        <div
          v-if="form.getFieldValue('printer_type') == PrinterEnum.PRINT_CENTER.value"
          :class="PrinterEnum.PRINT_CENTER.value"
        >
          <a-form-item label="打印机编号" :labelCol="labelCol" :wrapperCol="wrapperCol">
            <a-input v-decorator="['printer_config.deviceNo', { rules: [{ required: true }] }]" />
          </a-form-item>
          <a-form-item label="打印机秘钥" :labelCol="labelCol" :wrapperCol="wrapperCol">
            <a-input v-decorator="['printer_config.key', { rules: [{ required: true }] }]" />
          </a-form-item>
        </div>

        <a-form-item label="打印联数" :labelCol="labelCol" :wrapperCol="wrapperCol" extra="同一订单，打印的次数">
          <a-input-number
            :min="0"
            v-decorator="['print_times', { initialValue: 1, rules: [{ required: true, message: '请输入打印联数' }] }]"
          />
        </a-form-item>
        <a-form-item label="排序" :labelCol="labelCol" :wrapperCol="wrapperCol" extra="数字越小越靠前">
          <a-input-number
            :min="0"
            v-decorator="['sort', { initialValue: 100, rules: [{ required: true, message: '请输入排序值' }] }]"
          />
        </a-form-item>
      </a-form>
    </a-spin>
  </a-modal>
</template>

<script>
import _ from 'lodash'
import PrinterEnum from '@/common/enum/setting/Printer'
import * as Api from '@/api/setting/printer'

export default {
  data () {
    return {
      // 对话框标题
      title: '编辑打印机',
      // 标签布局属性
      labelCol: { span: 7 },
      // 输入框布局属性
      wrapperCol: { span: 13 },
      // modal(对话框)是否可见
      visible: false,
      // modal(对话框)确定按钮 loading
      confirmLoading: false,
      // 当前表单元素
      form: this.$form.createForm(this),
      // 枚举类: 打印机类型
      PrinterEnum,
      // 当前记录
      record: {}
    }
  },
  methods: {

    // 显示对话框
    edit (record) {
      // 显示窗口
      this.visible = true
      // 当前记录
      this.record = record
      // 设置默认值
      this.setFieldsValue()
    },

    // 设置默认值
    setFieldsValue () {
      const { form: { setFieldsValue } } = this
      this.$nextTick(() => {
        setFieldsValue(_.pick(this.record, ['printer_name', 'printer_type', 'print_times', 'sort']))
        this.$nextTick(() => {
          setFieldsValue(_.pick(this.record, ['printer_config']))
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

    // 关闭对话框事件
    handleCancel () {
      this.visible = false
      this.form.resetFields()
    },

    // 提交到后端api
    onFormSubmit (values) {
      this.confirmLoading = true
      Api.edit({ printerId: this.record.printer_id, form: values })
        .then(result => {
          // 显示成功
          this.$message.success(result.message, 1.5)
          // 关闭对话框事件
          this.handleCancel()
          // 通知父端组件提交完成了
          this.$emit('handleSubmit', values)
        })
        .finally(() => this.confirmLoading = false)
    }

  }
}
</script>
