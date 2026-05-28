<template>
  <a-card :bordered="false">
    <div class="card-title">设置支付方式</div>
    <a-spin :spinning="isLoading">
      <div class="payment-item" v-for="(group, index) in options" :key="index">
        <div class="item-client">
          <div class="name">{{ group.name }}</div>
          <p class="desc">{{ group.desc }}</p>
        </div>
        <a-table
          class="item-method"
          :rowKey="method => rowKey(method.key, group.client)"
          :columns="columns"
          :dataSource="group.methods"
          :pagination="false"
          bordered
        >
          <!-- 支付方式 -->
          <template slot="method" slot-scope="text">
            <div class="pay-method-item">
              <span class="pay-icon" :style="{ color: PayMethodIcons[text].color }">
                <a-icon class="icon" :class="[text]" :component="PayMethodIcons[text].icon" />
              </span>
              <span>{{ PaymentMethodEnum[text].name }}</span>
            </div>
          </template>
          <!-- 支付模板 -->
          <template slot="template" slot-scope="templateId, item">
            <a-select
              v-if="item.method === PaymentMethodEnum.WECHAT.value"
              class="select-template"
              placeholder="请选择支付模板"
              v-model="item.template_id"
            >
              <a-select-option :value="0">无</a-select-option>
              <a-select-option
                v-for="(item, index) in wechatTemplateList"
                :key="index"
                :value="item.template_id"
              >{{ item.name }}</a-select-option>
            </a-select>
            <a-select
              v-if="item.method === PaymentMethodEnum.ALIPAY.value"
              class="select-template"
              placeholder="请选择支付模板"
              v-model="item.template_id"
            >
              <a-select-option :value="0">无</a-select-option>
              <a-select-option
                v-for="(item, index) in alipayTemplateList"
                :key="index"
                :value="item.template_id"
              >{{ item.name }}</a-select-option>
            </a-select>
            <router-link
              v-if="item.is_must_template && $auth(createTemplatePath)"
              :to="{ path: createTemplatePath }"
              class="ml-15 f-12"
              target="_blank"
            >新增模板</router-link>
            <span v-if="!item.is_must_template" class="f-12 c-muted-9">无需支付模板</span>
          </template>
          <!-- 是否启用 -->
          <template slot="enable" slot-scope="text, item">
            <a-switch size="small" v-model="item.is_enable" />
          </template>
          <!-- 是否为默认支付 -->
          <template slot="default" slot-scope="text, item, key">
            <a-switch
              size="small"
              :checked="item.is_default"
              @change="onChangeDefault($event, key, group.methods)"
            />
          </template>
        </a-table>
      </div>
      <!-- 保存按钮 -->
      <a-row>
        <a-col :offset="7">
          <a-button type="primary" :loading="isBtnLoading" @click="handleSubmit">保存</a-button>
        </a-col>
      </a-row>
    </a-spin>
  </a-card>
</template>

<script>
import { payAlipay, payBalance, payWechat } from '@/core/icons'
import * as Api from '@/api/setting/payment'
import * as TemplateApi from '@/api/setting/payment/template'
import { PaymentMethodEnum } from '@/common/enum/payment'

// 支付方式图标
const PayMethodIcons = {
  [PaymentMethodEnum.ALIPAY.value]: { icon: payAlipay, color: '#009fe8' },
  [PaymentMethodEnum.BALANCE.value]: { icon: payBalance, color: '#e8a807' },
  [PaymentMethodEnum.WECHAT.value]: { icon: payWechat, color: '#59b64c' },
}

const columns = [
  {
    title: '支付方式',
    dataIndex: 'method',
    width: '25%',
    scopedSlots: { customRender: 'method' },
  },
  {
    title: '支付模板',
    dataIndex: 'template_id',
    width: '35%',
    scopedSlots: { customRender: 'template' },
  },
  {
    title: '是否启用',
    dataIndex: 'is_enable',
    width: '20%',
    scopedSlots: { customRender: 'enable' },
  },
  {
    title: '是否为默认支付',
    dataIndex: 'is_default',
    width: '20%',
    scopedSlots: { customRender: 'default' },
  },
];

export default {
  components: {},
  data () {
    return {
      // 正在加载
      isLoading: false,
      isBtnLoading: false,
      // 标签布局属性
      labelCol: { span: 3 },
      // 输入框布局属性
      wrapperCol: { span: 10 },
      // 枚举类
      PaymentMethodEnum,
      PayMethodIcons,
      // 创建模板页链接
      createTemplatePath: '/setting/payment/template/create',
      // 表结构字段
      columns,
      // 表单记录
      options: {},
      // 支付模板列表
      templateList: [],
    }
  },
  computed: {
    // 支付模板列表(微信支付)
    wechatTemplateList () {
      return this.templateList.filter(item => item.method === 'wechat')
    },
    // 支付模板列表(支付宝)
    alipayTemplateList () {
      return this.templateList.filter(item => item.method === 'alipay')
    }
  },
  // 初始化数据
  async created () {
    // 获取当前详情记录
    await this.getDetail()
    // 获取支付模板列表
    await this.getTemplateList()
  },
  methods: {

    // 获取当前记录
    async getDetail () {
      this.isLoading = true
      Api.options(this.templateId)
        .then(result => this.options = result.data.options)
        .finally(() => this.isLoading = false)
    },

    // 获取支付模板列表
    async getTemplateList () {
      this.isLoading = true
      return TemplateApi.all()
        .then(result => this.templateList = result.data.list)
        .finally(() => this.isLoading = false)
    },

    rowKey (key, client) {
      return `${key}-${client}`
    },

    // 设置默认支付项
    onChangeDefault (val, key, methods) {
      methods.forEach(method => method.is_default = false)
      methods[key].is_default = val
    },

    // 点击表单提交
    handleSubmit (e) {
      e.preventDefault()
      this.isLoading = true
      this.isBtnLoading = true
      Api.update({ form: this.options })
        .then(result => {
          this.$message.success(result.message, 1.5)
          this.getDetail()
        })
        .finally(() => {
          this.isLoading = false
          this.isBtnLoading = false
        })
    },

  }
}
</script>

<style lang="less" scoped>
/deep/.ant-table-thead > tr > th,
/deep/.ant-table-tbody > tr > td {
  padding: 11px 16px;
}

.ant-switch-small {
  min-width: 38px;
  height: 20px;
  line-height: 14px;
  /deep/.ant-switch-inner {
    margin-right: 3px;
    margin-left: 20px;
    font-size: 12px;
  }

  &::after {
    width: 16px;
    height: 16px;
  }
}

.payment-item {
  display: flex;
  margin-bottom: 22px;
  .name {
    font-size: 18px;
    color: #333;
    line-height: 1;
    margin-bottom: 20px;
    font-weight: 700;
  }
}

.item-client {
  width: 20%;
  min-width: 180px;
  display: flex;
  justify-content: center;
  align-items: center;
  flex-direction: column;
  border: 1px solid #e8e8e8;
  border-right: none;
}

.item-method {
  width: 80%;
  min-width: 800px;

  .pay-method-item {
    display: flex;
    align-items: center;
    .pay-icon {
      font-size: 24px;
      margin-right: 8px;
    }
  }

  .select-template {
    width: 200px;
  }
}
</style>