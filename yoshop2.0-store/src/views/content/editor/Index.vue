<template>
  <a-card :bordered="false">
    <div class="card-title">{{ $route.meta.title }}</div>
    <a-spin :spinning="isLoading">
      <a-form :form="form" @submit="handleSubmit">
        <a-divider orientation="left">首页首登弹窗</a-divider>
        <a-form-item class="mt-30" label="是否开启首登弹窗" :labelCol="labelCol" :wrapperCol="wrapperCol">
          <a-radio-group v-decorator="['firstLoginPopupEnabled', { rules: [{ required: true }] }]">
            <a-radio :value="true">开启</a-radio>
            <a-radio :value="false">关闭</a-radio>
          </a-radio-group>
          <p class="form-item-help">
            <small>仅微信小程序首页生效；关闭、未登录、正文为空或账号已展示过时都不会弹出</small>
          </p>
        </a-form-item>
        <a-form-item label="首登弹窗正文" :labelCol="labelCol" :wrapperCol="wrapperCol">
          <a-textarea
            :rows="6"
            v-decorator="['firstLoginPopupBody']"
            placeholder="请输入首页首登业务弹窗正文"
          />
          <p class="form-item-help">
            <small>支持多行文本；同一账号只会消费一次展示机会</small>
          </p>
        </a-form-item>

        <a-divider orientation="left">隐私协议内容</a-divider>
        <a-form-item label="隐私协议正文" :labelCol="labelCol" :wrapperCol="wrapperCol">
          <a-textarea
            :rows="10"
            v-decorator="['privacyAgreementContent']"
            placeholder="请输入隐私协议正文（支持 HTML 富文本内容）"
          />
          <p class="form-item-help">
            <small>“我的 - 隐私协议” 专页将直接渲染这里保存的内容</small>
          </p>
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
import { isEmpty } from '@/utils/util'
import * as Api from '@/api/client/wxapp/setting'

const editableFields = ['firstLoginPopupEnabled', 'firstLoginPopupBody', 'privacyAgreementContent']

export default {
  data () {
    return {
      labelCol: { span: 4 },
      wrapperCol: { span: 10 },
      isLoading: false,
      form: this.$form.createForm(this),
      key: 'basic',
      record: {}
    }
  },
  created () {
    this.getDetail()
  },
  methods: {
    getDetail () {
      this.isLoading = true
      Api.detail(this.key)
        .then(result => {
          this.record = result.data.detail || {}
          this.setFieldsValue()
        })
        .finally(() => {
          this.isLoading = false
        })
    },
    setFieldsValue () {
      const { record, $nextTick, form } = this
      !isEmpty(form.getFieldsValue()) && $nextTick(() => {
        form.setFieldsValue(pick(record, editableFields))
      })
    },
    handleSubmit (e) {
      e.preventDefault()
      this.form.validateFields((errors, values) => {
        !errors && this.onFormSubmit(values)
      })
    },
    onFormSubmit (values) {
      this.isLoading = true
      Api.update(this.key, { form: values })
        .then(result => this.$message.success(result.message, 1.5))
        .finally(() => {
          this.isLoading = false
        })
    }
  }
}
</script>

<style lang="less" scoped>
.ant-form-item {
  margin-bottom: 15px;
}
/deep/.ant-form-item-control {
  padding-left: 10px;

  .ant-form-item-control {
    padding-left: 0;
  }
}
.ant-divider {
  margin-top: 60px !important;
}
</style>
