<template>
  <a-card :bordered="false">
    <div class="card-title">{{ $route.meta.title }}</div>
    <a-spin :spinning="isLoading">
      <a-form :form="form" @submit="handleSubmit">
        <a-form-item class="mt-30" label="是否开启访问" :labelCol="labelCol" :wrapperCol="wrapperCol">
          <a-radio-group v-decorator="['enabled', { rules: [{ required: true }] }]">
            <a-radio :value="true">开启</a-radio>
            <a-radio :value="false">关闭</a-radio>
          </a-radio-group>
          <div class="form-item-help">
            <small>注：如关闭，用户则无法通过微信小程序端访问商城</small>
          </div>
        </a-form-item>
        <a-form-item class="mt-30" label="小程序 AppID" :labelCol="labelCol" :wrapperCol="wrapperCol">
          <a-input
            v-decorator="['app_id', { rules: [{ required: true, message: '请输入小程序AppID' }] }]"
          />
          <p class="form-item-help">
            <small>登录微信小程序平台，开发 - 开发管理 - 开发设置，记录AppID (小程序ID)</small>
          </p>
        </a-form-item>
        <a-form-item label="小程序 AppSecret" :labelCol="labelCol" :wrapperCol="wrapperCol">
          <a-input
            type="password"
            v-decorator="['app_secret', { rules: [{ required: true, message: '请输入小程序AppSecret' }] }]"
          />
          <p class="form-item-help">
            <small>登录微信小程序平台，开发 - 开发管理 - 开发设置，记录AppSecret (小程序密钥)</small>
          </p>
        </a-form-item>
        <a-form-item label="发货信息管理" :labelCol="labelCol" :wrapperCol="wrapperCol">
          <a-radio-group v-decorator="['enableShipping', { rules: [{ required: true }] }]">
            <a-radio :value="true">开启</a-radio>
            <a-radio :value="false">关闭</a-radio>
          </a-radio-group>
          <p class="form-item-help">
            <small>登录微信小程序平台，在功能菜单中查找是否存在《发货信息管理》，如果存在则需开启</small>
          </p>
        </a-form-item>

        <a-divider orientation="left">授权域名设置</a-divider>
        <a-form-item
          v-for="(item , index) in domainList"
          :key="index"
          class="mt-30"
          :label="`${item.name}合法域名`"
          :labelCol="labelCol"
          :wrapperCol="wrapperCol"
          required
        >
          <span class="f-14">{{ `${item.protocol}://${domain}` }}</span>
          <a
            class="ml-15 f-12"
            href="javascript:void(0);"
            @click="handleCopyLink(`${item.protocol}://${domain}`)"
          >点击复制</a>
          <p class="form-item-help">
            <small>登录小程序平台，开发 - 开发管理 - 开发设置 - 服务器域名，修改{{ item.protocol }}协议业务域名</small>
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

// 授权域名列表
const domainList = [
  {
    name: 'request',
    protocol: 'https'
  },
  {
    name: 'socket',
    protocol: 'wss'
  },
  {
    name: 'uploadFile',
    protocol: 'https'
  },
  {
    name: 'downloadFile',
    protocol: 'https'
  },
]

export default {
  data () {
    return {
      // 标签布局属性
      labelCol: { span: 4 },
      // 输入框布局属性
      wrapperCol: { span: 10 },
      // loading状态
      isLoading: false,
      // 当前表单元素
      form: this.$form.createForm(this),
      // 当前设置项key
      key: 'basic',
      // 授权域名列表
      domainList,
      // 当前记录详情
      record: {},
      // 服务端域名
      domain: '',
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
          this.record = result.data.detail
          this.domain = result.data.domain
          // 设置默认值
          this.setFieldsValue()
        })
        .finally(() => this.isLoading = false)
    },

    // 设置默认值
    setFieldsValue () {
      const { record, $nextTick, form } = this
      !isEmpty(form.getFieldsValue()) && $nextTick(() => {
        form.setFieldsValue(pick(record, ['enabled', 'app_id', 'app_secret', 'enableShipping']))
      })
    },

    // 复制链接地址
    handleCopyLink (url) {
      this.$copyText(url).then(res => {
        this.$message.success('复制成功', 0.8)
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

.ant-input-disabled {
  background-color: #fafafa;
  color: rgba(0, 0, 0, 0.45);
}
</style>
