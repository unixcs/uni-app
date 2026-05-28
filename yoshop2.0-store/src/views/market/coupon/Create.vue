<template>
  <a-card :bordered="false">
    <div class="card-title">{{ $route.meta.title }}</div>
    <a-spin :spinning="isLoading">
      <a-form :form="form" @submit="handleSubmit" :labelCol="labelCol" :wrapperCol="wrapperCol">
        <a-form-item label="优惠券名称">
          <a-input
            placeholder="请输入优惠券名称"
            v-decorator="['name', { rules: [{ required: true, min: 2, message: '请输入至少2个字符' }] }]"
          />
        </a-form-item>
        <a-form-item label="优惠券类型">
          <a-radio-group
            v-decorator="['coupon_type', { initialValue: 10, rules: [{ required: true }] }]"
          >
            <a-radio :value="10">满减券</a-radio>
            <a-radio :value="20">折扣券</a-radio>
          </a-radio-group>
        </a-form-item>
        <a-form-item
          v-if="form.getFieldValue('coupon_type') == 10"
          label="减免金额"
          :labelCol="labelCol"
          :wrapperCol="wrapperCol"
        >
          <a-input-number
            :min="0.01"
            :precision="2"
            v-decorator="['reduce_price', { rules: [{ required: true, message: '请输入减免金额' }] }]"
          />
          <span class="ml-5">元</span>
        </a-form-item>
        <a-form-item
          v-if="form.getFieldValue('coupon_type') == 20"
          label="折扣率"
          :labelCol="labelCol"
          :wrapperCol="wrapperCol"
        >
          <a-input-number
            :min="0"
            :max="9.9"
            :precision="1"
            v-decorator="['discount', { initialValue: 9.9, rules: [{ required: true, message: '请输入折扣率' }] }]"
          />
          <span class="ml-5">%</span>
          <p class="form-item-help">
            <small>折扣率范围 0-9.9，8代表打8折，0代表不折扣</small>
          </p>
        </a-form-item>
        <a-form-item label="最低消费金额">
          <a-input-number
            :min="1"
            :precision="2"
            v-decorator="['min_price', { rules: [{ required: true, message: '请输入最低消费金额' }] }]"
          />
          <span class="ml-5">元</span>
        </a-form-item>
        <a-form-item label="到期类型">
          <a-radio-group
            v-decorator="['expire_type', { initialValue: 10, rules: [{ required: true }] }]"
          >
            <a-radio :value="10">领取后生效</a-radio>
            <a-radio :value="20">固定时间</a-radio>
          </a-radio-group>
          <a-form-item v-show="form.getFieldValue('expire_type') == 10" class="expire_type-10">
            <InputNumberGroup
              addonBefore="有效期"
              addonAfter="天"
              :inputProps="{ min: 1, precision: 0 }"
              v-decorator="['expire_day', { initialValue: 7, rules: [{ required: true, message: '请输入有效期天数' }] }]"
            />
          </a-form-item>
          <a-form-item v-show="form.getFieldValue('expire_type') == 20" class="expire_type-20">
            <a-range-picker
              format="YYYY-MM-DD"
              v-decorator="['betweenTime', { initialValue: defaultDate, rules: [{ required: true, message: '请选择有效期范围' }] }]"
            />
          </a-form-item>
        </a-form-item>
        <a-form-item label="券适用范围">
          <a-radio-group
            v-decorator="['apply_range', { initialValue: ApplyRangeEnum.ALL.value, rules: [{ required: true }] }]"
          >
            <a-radio
              v-for="(item, index) in ApplyRangeEnum.data"
              :value="item.value"
              :key="index"
            >{{ item.name }}</a-radio>
          </a-radio-group>
          <a-form-item v-if="form.getFieldValue('apply_range') != ApplyRangeEnum.ALL.value">
            <SelectGoods
              v-decorator="['apply_range_config.goodsIds', { rules: [{ required: true, message: '请选择适用的商品' }] }]"
            />
          </a-form-item>
        </a-form-item>
        <a-form-item label="发放总数量">
          <a-input-number
            :min="-1"
            :precision="0"
            v-decorator="['total_num', { initialValue: -1, rules: [{ required: true, message: '请输入发放总数量' }] }]"
          />
          <span class="ml-5">张</span>
          <p class="form-item-help">
            <small>发放的优惠券总数量，-1为不限制</small>
          </p>
        </a-form-item>
        <a-form-item label="显示状态">
          <a-radio-group v-decorator="['status', { initialValue: 1, rules: [{ required: true }] }]">
            <a-radio :value="1">显示</a-radio>
            <a-radio :value="0">隐藏</a-radio>
          </a-radio-group>
          <p class="form-item-help">
            <small>如果设为隐藏将不会展示在用户端页面</small>
          </p>
        </a-form-item>
        <a-form-item label="优惠券描述">
          <a-textarea :autoSize="{ minRows: 4 }" v-decorator="['describe']" />
        </a-form-item>
        <a-form-item label="排序" extra="数字越小越靠前">
          <a-input-number
            :min="0"
            v-decorator="['sort', { initialValue: 100, rules: [{ required: true, message: '请输入排序值' }] }]"
          />
        </a-form-item>
        <a-form-item class="mt-20" :wrapperCol="{ span: wrapperCol.span, offset: labelCol.span }">
          <a-button type="primary" html-type="submit" :loading="isBtnLoading">提交</a-button>
        </a-form-item>
      </a-form>
    </a-spin>
  </a-card>
</template>

<script>
import moment from 'moment'
import * as Api from '@/api/market/coupon'
import { InputNumberGroup, SelectGoods } from '@/components'
import { ApplyRangeEnum, CouponTypeEnum, ExpireTypeEnum } from '@/common/enum/coupon'

export default {
  components: {
    SelectGoods,
    InputNumberGroup
  },
  data () {
    return {
      // 枚举类
      ApplyRangeEnum,
      CouponTypeEnum,
      ExpireTypeEnum,
      // 正在加载
      isLoading: false,
      isBtnLoading: false,
      // 标签布局属性
      labelCol: { span: 3 },
      // 输入框布局属性
      wrapperCol: { span: 10 },
      // 当前表单元素
      form: this.$form.createForm(this),
      // 默认日期范围
      defaultDate: [moment(), moment()]
    }
  },
  created () {
    this.$nextTick(() => {
      this.$forceUpdate()
    })
  },
  methods: {

    // 确认按钮
    handleSubmit (e) {
      e.preventDefault()
      // 表单验证
      const { form: { validateFields }, onFormSubmit } = this
      validateFields((errors, values) => {
        !errors && onFormSubmit(values)
      })
    },

    // 提交到后端api
    onFormSubmit (values) {
      this.isLoading = true
      this.isBtnLoading = true
      Api.add({ form: values })
        .then(result => {
          // 显示提示信息
          this.$message.success(result.message, 1.5)
          // 跳转到列表页
          setTimeout(() => this.$router.push('./index'), 1500)
        })
        .catch(() => this.isBtnLoading = false)
        .finally(() => this.isLoading = false)
    }

  }
}
</script>
<style lang="less" scoped>
.ant-form-item {
  .ant-form-item {
    margin-bottom: 0;
  }
}

/deep/.ant-form-item-control {
  padding-left: 10px;
}
</style>
