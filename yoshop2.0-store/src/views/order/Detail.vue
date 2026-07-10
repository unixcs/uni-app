<template>
  <div>
    <a-spin :spinning="isLoading" />
    <div v-if="!isLoading" class="order-content">
      <a-card :bordered="false">
        <a-steps :current="progress" size="small">
          <a-step title="提交订单" :description="record.create_time || '--'" />
          <a-step title="完成付款" :description="record.pay_time || '待支付'" />
          <a-step title="开始服务" :description="record.delivery_time || '待开始'" />
          <a-step title="服务完成" :description="serviceCompletedAt" />
        </a-steps>
      </a-card>

      <a-card class="mt-20" :bordered="false">
        <div class="header-row">
          <div>
            <div class="ant-descriptions-title">服务单操作</div>
            <div class="status-tags mt-12">
              <a-tag :color="renderStateColor()">{{ record.state_text || '--' }}</a-tag>
              <a-tag :color="record.pay_status === PayStatusEnum.SUCCESS.value ? 'green' : 'default'">
                {{ PayStatusEnum[record.pay_status].name }}
              </a-tag>
              <a-tag v-if="latestRefund && latestRefund.state_text" color="orange">
                {{ latestRefund.state_text }}
              </a-tag>
            </div>
          </div>
          <div class="actions clearfix">
            <div v-if="$module('order-updatePrice') && $auth('/order/detail.updatePrice')" class="action-item">
              <a-button v-if="record.pay_status === PayStatusEnum.PENDING.value" @click="handleUpdatePrice">订单改价</a-button>
            </div>
            <div v-if="$auth('/order/detail.merchantRemark')" class="action-item">
              <a-button @click="handleMerchantRemark">商家备注</a-button>
            </div>
            <div v-if="canStartService && canUseStartServiceAction" class="action-item">
              <a-button type="primary" @click="handleStartService">开始服务</a-button>
            </div>
            <div v-if="canCompleteService && canUseCompleteServiceAction" class="action-item">
              <a-button type="primary" @click="handleCompleteService">完成服务</a-button>
            </div>
            <div v-if="canRefundBeforeService && canUseRefundBeforeServiceAction" class="action-item">
              <a-button type="danger" @click="handleRefundBeforeService">服务前退款</a-button>
            </div>
            <div v-if="canViewActiveRefund" class="action-item">
              <a-button :type="canAuditActiveRefund ? 'primary' : 'default'" @click="handleViewActiveRefund">
                {{ canAuditActiveRefund ? '服务中退款' : '查看退款' }}
              </a-button>
            </div>
          </div>
        </div>
      </a-card>

      <a-card class="mt-20" :bordered="false">
        <a-descriptions title="服务单信息" :column="3">
          <a-descriptions-item label="订单号">{{ record.order_no }}</a-descriptions-item>
          <a-descriptions-item label="服务状态">
            <a-tag :color="renderStateColor()">{{ record.state_text || '--' }}</a-tag>
          </a-descriptions-item>
          <a-descriptions-item label="支付方式">
            <a-tag color="green" v-if="record.pay_method">{{ PaymentMethodEnum[record.pay_method].name }}</a-tag>
            <span v-else>--</span>
          </a-descriptions-item>
          <a-descriptions-item label="买家信息">
            <a-tooltip>
              <template slot="title">会员ID: {{ record.user ? record.user.user_id : '-' }}</template>
              <span class="c-p">{{ record.user ? record.user.nick_name : '-' }}</span>
            </a-tooltip>
          </a-descriptions-item>
          <a-descriptions-item label="游戏平台">{{ getGamePlatformText(getServiceContactField('game_platform')) || '--' }}</a-descriptions-item>
          <a-descriptions-item label="游戏ID">{{ getServiceContactField('game_account_id') || '--' }}</a-descriptions-item>
          <a-descriptions-item label="联系方式">{{ getServiceContactField('contact_mobile') || '--' }}</a-descriptions-item>
          <a-descriptions-item label="成年人下单">{{ getAdultConfirmText(getServiceContactField('adult_confirm')) }}</a-descriptions-item>
          <a-descriptions-item label="买家留言">{{ record.buyer_remark || '-' }}</a-descriptions-item>
          <a-descriptions-item label="商家备注">{{ record.merchant_remark || '-' }}</a-descriptions-item>
          <a-descriptions-item v-if="record.trade" label="第三方支付订单号">{{ record.trade.out_trade_no || '-' }}</a-descriptions-item>
          <a-descriptions-item v-if="record.trade" label="支付流水号">{{ record.trade.trade_no || '-' }}</a-descriptions-item>
          <a-descriptions-item label="付款时间">{{ record.pay_time || '--' }}</a-descriptions-item>
          <a-descriptions-item label="开始服务时间">{{ record.delivery_time || '--' }}</a-descriptions-item>
          <a-descriptions-item label="完成服务时间">{{ serviceCompletedAt }}</a-descriptions-item>
        </a-descriptions>
      </a-card>

      <a-card v-if="virtualPaymentSummary.enabled" class="mt-20" :bordered="false">
        <a-descriptions title="虚拟支付信息" :column="3">
          <a-descriptions-item label="支付通道">微信虚拟支付</a-descriptions-item>
          <a-descriptions-item label="支付环境">{{ virtualPaymentEnvText }}</a-descriptions-item>
          <a-descriptions-item label="支付状态">{{ virtualPaymentTradeStateText }}</a-descriptions-item>
          <a-descriptions-item label="交易号">{{ record.trade ? (record.trade.out_trade_no || '-') : '-' }}</a-descriptions-item>
          <a-descriptions-item label="支付流水号">{{ record.trade ? (record.trade.trade_no || '-') : '-' }}</a-descriptions-item>
          <a-descriptions-item label="productId">{{ virtualPaymentSummary.product_id || '-' }}</a-descriptions-item>
          <a-descriptions-item label="平台价格">￥{{ virtualPaymentGoodsPriceText }}</a-descriptions-item>
          <a-descriptions-item label="履约回执">{{ virtualPaymentProvideGoodsText }}</a-descriptions-item>
          <a-descriptions-item label="退款状态">{{ virtualPaymentRefundText }}</a-descriptions-item>
          <a-descriptions-item label="通知次数">{{ virtualPaymentSummary.notify_times || 0 }}</a-descriptions-item>
          <a-descriptions-item label="最近通知时间">{{ virtualPaymentLastNotifyText }}</a-descriptions-item>
        </a-descriptions>
      </a-card>

      <a-card class="mt-20" :bordered="false">
        <div class="ant-descriptions-title">服务套餐</div>
        <div class="goods-list">
          <a-table rowKey="order_goods_id" :columns="goodsColumns" :dataSource="record.goods || []" :pagination="false">
            <template slot="goodsInfo" slot-scope="text, item">
              <GoodsItem
                :data="{
                  image: item.goods_image,
                  imageAlt: '商品图片',
                  title: item.goods_name,
                  goodsProps: item.goods_props
                }"
              />
            </template>
            <template slot="goods_price" slot-scope="text, item">
              <p :class="{ 'f-through': item.is_user_grade }">￥{{ text }}</p>
              <p v-if="item.is_user_grade">
                <a-tooltip>
                  <template slot="title">
                    <span class="f-13">会员等级折扣价</span>
                  </template>
                  <strong>会员价：</strong>
                  <span>￥{{ item.grade_goods_price }}</span>
                </a-tooltip>
              </p>
            </template>
            <span slot="total_num" slot-scope="text">x{{ text }}</span>
            <span slot="total_price" slot-scope="text">￥{{ text }}</span>
          </a-table>
          <div class="order-price clearfix">
            <table class="fl-r">
              <tbody>
                <tr>
                  <td>订单金额：</td>
                  <td>￥{{ record.total_price }}</td>
                </tr>
                <tr v-if="record.coupon_money > 0">
                  <td>优惠券抵扣：</td>
                  <td>-￥{{ record.coupon_money }}</td>
                </tr>
                <tr v-if="record.points_money > 0">
                  <td>积分抵扣：</td>
                  <td>-￥{{ record.points_money }}</td>
                </tr>
                <tr v-if="record.update_price && record.update_price.value != 0">
                  <td>商家改价：</td>
                  <td>{{ record.update_price.symbol }}￥{{ record.update_price.value }}</td>
                </tr>
                <tr>
                  <td>实付款金额：</td>
                  <td><strong class="c-p f-15">￥{{ record.pay_price }}</strong></td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </a-card>

      <a-card v-if="latestRefund" class="mt-20" :bordered="false">
        <a-descriptions title="退款信息" :column="2">
          <a-descriptions-item label="退款状态">{{ latestRefund.state_text || '--' }}</a-descriptions-item>
          <a-descriptions-item label="退款金额">￥{{ latestRefund.refund_money || '0.00' }}</a-descriptions-item>
          <a-descriptions-item label="申请说明">{{ latestRefund.apply_desc || '-' }}</a-descriptions-item>
          <a-descriptions-item label="拒绝原因">{{ latestRefund.refuse_desc || '-' }}</a-descriptions-item>
        </a-descriptions>
      </a-card>
    </div>
    <PriceForm ref="PriceForm" @handleSubmit="handleRefresh" />
    <RemarkForm ref="RemarkForm" @handleSubmit="handleRefresh" />
  </div>
</template>

<script>
import * as Api from '@/api/order'
import * as EventApi from '@/api/order/event'
import { GoodsItem } from '@/components/Table'
import { PriceForm, RemarkForm } from './modules'
import {
  OrderStatusEnum,
  PayStatusEnum
} from '@/common/enum/order'
import { PaymentMethodEnum } from '@/common/enum/payment'

const goodsColumns = [
  {
    title: '套餐信息',
    scopedSlots: { customRender: 'goodsInfo' }
  },
  {
    title: '单价',
    dataIndex: 'goods_price',
    scopedSlots: { customRender: 'goods_price' }
  },
  {
    title: '购买数量',
    dataIndex: 'total_num',
    scopedSlots: { customRender: 'total_num' }
  },
  {
    title: '小计',
    dataIndex: 'total_price',
    scopedSlots: { customRender: 'total_price' }
  }
]

export default {
  name: 'Index',
  components: {
    GoodsItem,
    PriceForm,
    RemarkForm
  },
  data () {
    return {
      OrderStatusEnum,
      PayStatusEnum,
      PaymentMethodEnum,
      isLoading: true,
      orderId: null,
      record: {},
      progress: 0,
      goodsColumns
    }
  },
  computed: {
    serviceCompletedAt () {
      return this.record.receipt_time || (this.record.order_status === OrderStatusEnum.COMPLETED.value ? this.record.update_time : '--') || '--'
    },
    backendActionFlags () {
      return this.record.backend_action_flags || {}
    },
    canStartService () {
      return !!this.backendActionFlags.can_start_service
    },
    canCompleteService () {
      return !!this.backendActionFlags.can_complete_service
    },
    canRefundBeforeService () {
      return !!this.backendActionFlags.can_refund_before_service
    },
    canUseStartServiceAction () {
      return this.$auth('/order.event/startService') || this.$auth('/order/list/all.deliver')
    },
    canUseCompleteServiceAction () {
      return this.$auth('/order.event/completeService') || this.$auth('/order/list/all.deliver')
    },
    canUseRefundBeforeServiceAction () {
      return this.$auth('/order.event/refundBeforeService') || this.$auth('/order/list/all.cancel')
    },
    activeRefundId () {
      return Number(this.backendActionFlags.active_refund_id || 0)
    },
    canAuditActiveRefund () {
      return !!this.backendActionFlags.can_audit_refund && this.$auth('/order/refund/index.audit')
    },
    canViewActiveRefund () {
      return this.activeRefundId > 0 && this.canUseRefundDetailAction
    },
    canUseRefundDetailAction () {
      return this.$auth('/order/refund/detail') || this.$auth('/order/refund/index')
    },
    latestRefund () {
      const goodsList = this.record.goods || []
      const refunds = []
      for (const goods of goodsList) {
        if (goods && goods.refund) {
          refunds.push(goods.refund)
        }
      }
      if (!refunds.length) {
        return null
      }
      if (this.activeRefundId > 0) {
        const activeRefund = refunds.find(item => Number(item.order_refund_id || 0) === this.activeRefundId)
        if (activeRefund) {
          return activeRefund
        }
      }
      return refunds.sort((a, b) => {
        const idDiff = Number(b.order_refund_id || 0) - Number(a.order_refund_id || 0)
        if (idDiff !== 0) {
          return idDiff
        }
        return Number(b.create_time || 0) - Number(a.create_time || 0)
      })[0]
    },
    virtualPaymentSummary () {
      return this.record.virtual_payment_summary || {}
    },
    virtualPaymentEnvText () {
      if (!this.virtualPaymentSummary.enabled) return '--'
      return Number(this.virtualPaymentSummary.env) === 1 ? '沙箱' : '现网'
    },
    virtualPaymentGoodsPriceText () {
      if (!this.virtualPaymentSummary.enabled) return '--'
      return (Number(this.virtualPaymentSummary.goods_price || 0) / 100).toFixed(2)
    },
    virtualPaymentLastNotifyText () {
      const value = Number(this.virtualPaymentSummary.last_notify_time || 0)
      if (!value) return '--'
      return this.$moment.unix(value).format('YYYY-MM-DD HH:mm:ss')
    },
    virtualPaymentProvideGoodsText () {
      const status = this.virtualPaymentSummary.provide_goods_status || ''
      if (!status) return '--'
      if (status === 'success') return '已回执'
      if (status === 'sending') return '发送中'
      if (status === 'failed') return '失败待补偿'
      if (status === 'skipped') return '已跳过'
      return status
    },
    virtualPaymentRefundText () {
      return this.virtualPaymentSummary.refund_status || '--'
    },
    virtualPaymentTradeStateText () {
      const state = Number(this.virtualPaymentSummary.trade_state || 0)
      if (state === 20) return '已支付'
      if (state === 30) return '退款中/已退款'
      if (state === 40) return '已关闭'
      return '待支付'
    }
  },
  created () {
    this.orderId = this.$route.query.orderId
    this.handleRefresh()
  },
  methods: {
    getGamePlatformText (value) {
      if (value === 'pc') return '端游'
      if (value === 'mobile') return '手游'
      return ''
    },
    isAdultConfirmed (value) {
      return value === true || value === 1 || value === '1' || value === 'true'
    },
    getAdultConfirmText (value) {
      if (value === null || typeof value === 'undefined' || value === '') return '--'
      return this.isAdultConfirmed(value) ? '已确认' : '未确认'
    },
    getServiceContactField (field) {
      const record = this.record || {}
      if (Object.prototype.hasOwnProperty.call(record, field) && record[field] !== null && typeof record[field] !== 'undefined') {
        return record[field]
      }
      if (record.service_contact && Object.prototype.hasOwnProperty.call(record.service_contact, field)) {
        return record.service_contact[field]
      }
      let sourceData = record.order_source_data || {}
      if (typeof sourceData === 'string') {
        try {
          sourceData = JSON.parse(sourceData)
        } catch (e) {
          sourceData = {}
        }
      }
      const serviceContact = sourceData.service_contact || {}
      return Object.prototype.hasOwnProperty.call(serviceContact, field) ? serviceContact[field] : ''
    },
    handleRefresh () {
      this.getDetail()
    },
    getDetail () {
      this.isLoading = true
      Api.detail({ orderId: this.orderId })
        .then(result => {
          this.record = result.data.detail || {}
          this.initProgress()
        })
        .finally(() => {
          this.isLoading = false
        })
    },
    initProgress () {
      const record = this.record
      let current = 0
      if (record.pay_status === PayStatusEnum.SUCCESS.value) current = 1
      if (record.delivery_time) current = 2
      if (record.order_status === OrderStatusEnum.COMPLETED.value || record.receipt_time) current = 3
      this.progress = current
    },
    renderStateColor () {
      if (this.record.order_status === OrderStatusEnum.COMPLETED.value) return 'green'
      if (this.record.order_status === OrderStatusEnum.CANCELLED.value) return 'red'
      if (this.record.order_status === OrderStatusEnum.APPLY_CANCEL.value) return 'orange'
      return this.record.pay_status === PayStatusEnum.SUCCESS.value ? 'blue' : 'default'
    },
    handleUpdatePrice () {
      this.$refs.PriceForm.show(this.record)
    },
    handleMerchantRemark () {
      this.$refs.RemarkForm.show(this.record)
    },
    confirmServiceAction (title, content, request) {
      this.$confirm({
        title,
        content,
        onOk: () => request().then(() => {
          this.$message.success('操作成功')
          this.handleRefresh()
        })
      })
    },
    handleStartService () {
      this.confirmServiceAction(
        '确认开始服务？',
        '开始服务后，订单将进入服务中，默认不再支持直接退款。',
        () => EventApi.startService(this.orderId)
      )
    },
    handleCompleteService () {
      this.confirmServiceAction(
        '确认完成服务？',
        '确认后订单将标记为已完成。',
        () => EventApi.completeService(this.orderId)
      )
    },
    handleRefundBeforeService () {
      this.confirmServiceAction(
        '确认服务前退款？',
        '该操作会按原路退款并关闭当前服务单。',
        () => EventApi.refundBeforeService(this.orderId)
      )
    },
    handleViewActiveRefund () {
      if (!this.activeRefundId) {
        return
      }
      this.$router.push({
        path: '/order/refund/detail',
        query: { orderRefundId: this.activeRefundId }
      })
    }
  }
}
</script>

<style lang="less" scoped>
.order-content {
  margin-bottom: 70px;

  /deep/.ant-descriptions-item > span {
    vertical-align: middle;
  }

  .header-row {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 16px;
    flex-wrap: wrap;
  }

  .status-tags {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
  }

  .goods-list {
    /deep/table {
      table-layout: auto;
    }

    .order-price {
      padding: 8px 20px;
      text-align: right;
    }
  }

  .actions {
    .action-item {
      float: left;
      margin-right: 8px;
      margin-bottom: 8px;
    }
  }
}
</style>
