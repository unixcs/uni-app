<template>
  <a-card :bordered="false">
    <div class="card-title">{{ $route.meta.title }}</div>
    <a-spin :spinning="isLoading">
      <a-form-model
        ref="myForm"
        class="my-form"
        :model="record"
        :labelCol="labelCol"
        :wrapperCol="wrapperCol"
      >
        <a-form-model-item label="开启商城客服" :labelCol="labelCol" :wrapperCol="wrapperCol" required>
          <a-radio-group v-model="record.enabled">
            <a-radio :value="1">开启</a-radio>
            <a-radio :value="0">关闭</a-radio>
          </a-radio-group>
          <p class="form-item-help">
            <small>开启后将在用户端商品详情页、个人中心页显示在线客服按钮</small>
          </p>
        </a-form-model-item>
        <div v-if="record.enabled == 1">
          <a-form-model-item label="在线客服方式" :labelCol="labelCol" :wrapperCol="wrapperCol" required>
            <a-radio-group v-model="record.provider">
              <a-radio value="mpwxkf">微信小程序客服</a-radio>
            </a-radio-group>
            <div v-show="record.provider === 'mpwxkf'" class="form-item-help">
              <p class="extra">仅支持微信小程序端，其他端不支持</p>
              <p class="extra">
                <span>网页版客服工具：</span>
                <a href="https://mpkf.weixin.qq.com" target="_blank">https://mpkf.weixin.qq.com</a>
              </p>
            </div>
          </a-form-model-item>
        </div>
        <a-form-model-item :wrapperCol="{ span: wrapperCol.span, offset: labelCol.span }">
          <a-button type="primary" :loading="confirmLoading" @click="handleSubmit">保存</a-button>
        </a-form-model-item>
      </a-form-model>
    </a-spin>
  </a-card>
</template>

<script>
import { cloneDeep } from 'lodash'
import * as Api from '@/api/setting/store'
import { SettingEnum } from '@/common/enum/store'

// 默认数据
const defaultData = {
  enabled: 1,
  provider: 'mpwxkf',
  config: {
    mpwxkf: {}
  }
}

export default {
  data () {
    return {
      // 当前设置项的key
      key: SettingEnum.CUSTOMER.value,
      // 标签布局属性
      labelCol: { span: 4 },
      // 输入框布局属性
      wrapperCol: { span: 10 },
      // 加载状态
      isLoading: false,
      confirmLoading: false,
      // 当前记录详情
      record: cloneDeep(defaultData),
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
        .then(result => this.record = result.data.values)
        .finally(() => this.isLoading = false)
    },

    // 确认按钮
    handleSubmit (e) {
      this.$refs.myForm.validate(valid => {
        if (valid) {
          this.confirmLoading = true
          Api.update(this.key, { form: this.record })
            .then(result => {
              // 显示提示信息
              this.$message.success(result.message, 1.5)
            })
            .finally(result => this.confirmLoading = false)
        }
      })
    },

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
