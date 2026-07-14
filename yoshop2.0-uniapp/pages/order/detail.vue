<template>
  <view class="container" :style="appThemeStyle">
    <view v-if="!isLoading && loadError" class="load-error i-card">
      <text>{{ loadError }}</text>
      <view class="retry-btn" @click="getOrderDetail()">重新加载</view>
    </view>
    <block v-if="!isLoading && !loadError">
    <view class="header">
      <view class="order-status">
        <view class="status-text"><text>{{ getOrderStateText(order) }}</text></view>
      </view>
      <view v-if="canPayOrder" class="next-action">
        <view class="action-btn" @click="onPay(order.order_id)">去支付</view>
      </view>
    </view>

    <view class="card-area">
      <view class="goods-list i-card">
        <view class="goods-item" v-for="(goods, idx) in order.goods" :key="idx">
          <view class="goods-main" @click="handleTargetGoods(goods.goods_id)">
            <view class="goods-image"><image class="image" :src="goods.goods_image" mode="scaleToFill"></image></view>
            <view class="goods-content">
              <view class="goods-title"><text class="twoline-hide">{{ goods.goods_name }}</text></view>
              <view class="goods-props clearfix">
                <view class="goods-props-item" v-for="(props, pidx) in goods.goods_props" :key="pidx"><text>{{ props.value.name }}</text></view>
              </view>
            </view>
            <view class="goods-trade">
              <view class="goods-price"><text class="unit">￥</text><text class="value">{{ goods.is_user_grade ? goods.grade_goods_price : goods.goods_price }}</text></view>
              <view class="goods-num"><text>×{{ goods.total_num }}</text></view>
            </view>
          </view>
        </view>
      </view>

      <view class="order-info i-card">
        <view class="info-item"><view class="item-lable">订单编号</view><view class="item-content"><text>{{ order.order_no }}</text><view class="act-copy" @click="handleCopy(order.order_no)"><text>复制</text></view></view></view>
        <view class="info-item"><view class="item-lable">下单时间</view><view class="item-content"><text>{{ order.create_time }}</text></view></view>
        <view v-if="isServiceOrder" class="info-item"><view class="item-lable">游戏平台</view><view class="item-content"><text>{{ getGamePlatformText((order.service_contact && order.service_contact.game_platform) || '') || '--' }}</text></view></view>
        <view v-if="isServiceOrder" class="info-item"><view class="item-lable">游戏 ID</view><view class="item-content"><text>{{ (order.service_contact && order.service_contact.game_account_id) || '--' }}</text></view></view>
        <view v-if="isServiceOrder" class="info-item"><view class="item-lable">联系方式</view><view class="item-content"><text>{{ (order.service_contact && order.service_contact.contact_mobile) || '--' }}</text></view></view>
        <view v-if="isServiceOrder" class="info-item"><view class="item-lable">成年人下单</view><view class="item-content"><text>{{ getAdultConfirmText(order.service_contact && order.service_contact.adult_confirm) }}</text></view></view>
        <view class="info-item"><view class="item-lable">备注</view><view class="item-content"><text>{{ order.remark || '--' }}</text></view></view>
      </view>

      <view class="trade-info i-card">
        <view class="info-item"><view class="item-lable">订单金额</view><view class="item-content"><text>￥{{ order.total_price }}</text></view></view>
        <view v-if="order.coupon_money > 0" class="info-item"><view class="item-lable">优惠券抵扣</view><view class="item-content"><text>-￥{{ order.coupon_money }}</text></view></view>
        <view class="divider"></view>
        <view class="trade-total"><text class="lable">实付款</text><view class="goods-price"><text class="unit">￥</text><text class="value">{{ order.pay_price }}</text></view></view>
      </view>

      <view v-if="hasRefundInfo" class="refund-info i-card">
        <view class="card-title">退款反馈</view>
        <view class="info-item"><view class="item-lable">状态</view><view class="item-content"><text>{{ refundDisplayText || '--' }}</text></view></view>
        <view v-if="refundInfo.refund_guidance" class="info-item"><view class="item-lable">退款说明</view><view class="item-content"><text>{{ refundInfo.refund_guidance }}</text></view></view>
        <view v-if="shouldShowSubmittedIosGuide" class="info-item ios-guide-item"><IosAppleRefundGuide :submitted="true" /></view>
        <view v-if="refundInfo.apply_desc" class="info-item"><view class="item-lable">退款原因</view><view class="item-content"><text>{{ refundInfo.apply_desc }}</text></view></view>
        <view v-if="refundInfo.refund_money !== '' && refundInfo.refund_money != null" class="info-item"><view class="item-lable">退款金额</view><view class="item-content"><text>￥{{ refundInfo.refund_money }}</text></view></view>
        <view v-if="refundInfo.refuse_desc" class="info-item"><view class="item-lable">处理备注</view><view class="item-content"><text>{{ refundInfo.refuse_desc }}</text></view></view>
      </view>
    </view>

    <view v-if="showFooterActions" class="footer-fixed">
      <view class="btn-wrapper">
        <block v-if="canCancelOrder"><view class="btn-item" @click="onCancel(order.order_id)">取消</view></block>
        <block v-if="canPayOrder"><view class="btn-item active" @click="onPay(order.order_id)">去支付</view></block>
        <block v-if="canApplyRefund"><view class="btn-item active" @click="onRefund()">{{ refundActionText }}</view></block>
      </view>
    </view>
    </block>
  </view>
</template>

<script>
  import IosAppleRefundGuide from '@/components/refund/IosAppleRefundGuide.vue'
  import { OrderStatusEnum, PayStatusEnum } from '@/common/enum/order'
  import RefundStatusEnum from '@/common/enum/order/refund/RefundStatus'
  import * as OrderApi from '@/api/order'

  export default {
    components: { IosAppleRefundGuide },
    data() {
      return {
        OrderStatusEnum,
        PayStatusEnum,
        RefundStatusEnum,
        orderId: null,
        isLoading: true,
        loadError: '',
        order: {},
        refundInfo: {},
        setting: {},
        canReset: false,
        hasShownOnce: false
      }
    },
    computed: {
      canCancelOrder() {
        return !!(this.order && this.order.action_flags && this.order.action_flags.can_cancel)
      },
      canPayOrder() {
        return !!(this.order && this.order.action_flags && this.order.action_flags.can_pay)
      },
      canApplyRefund() {
        if (!this.order) {
          return false
        }
        if (this.hasActiveRefund) {
          return false
        }
        if (!this.isServiceOrder) {
          return false
        }
        if (this.order.action_flags && typeof this.order.action_flags.can_apply_refund !== 'undefined') {
          return !!this.order.action_flags.can_apply_refund
        }
        return !!this.order.can_apply_refund
      },
      isServiceOrder() {
        if (!this.order) {
          return false
        }
        if (typeof this.order.is_service_order !== 'undefined') {
          return !!this.order.is_service_order
        }
        const contact = this.order.service_contact || {}
        return !!(contact.game_platform || contact.game_account_id || contact.contact_mobile || this.isAdultConfirmed(contact.adult_confirm))
      },
      hasRefundInfo() {
        const info = this.refundInfo || {}
        return !!(info.order_refund_id || info.state_text || info.service_state_text || info.display_state_text || info.refund_guidance || info.apply_desc || info.refuse_desc || info.ios_apple_refund_required)
      },
      hasPrimaryRefundState() {
        const info = this.refundInfo || {}
        return !!info.order_refund_id && Number(info.state) === this.RefundStatusEnum.NORMAL.value && !info.is_terminal
      },
      hasActiveRefund() {
        const info = this.refundInfo || {}
        return !!info.order_refund_id && Number(info.state) === this.RefundStatusEnum.NORMAL.value
      },
      isIosAppleRefundMode() {
        const actionFlags = (this.order && this.order.action_flags) || {}
        if (typeof actionFlags.ios_apple_refund_required !== 'undefined') {
          return actionFlags.ios_apple_refund_required === true || actionFlags.ios_apple_refund_required === 1
        }
        return !!this.refundInfo && (this.refundInfo.ios_apple_refund_required === true || this.refundInfo.ios_apple_refund_required === 1)
      },
      refundActionText() {
        return '退款'
      },
      shouldShowSubmittedIosGuide() {
        return this.isIosAppleRefundMode && [
          'local_refund_submitted',
          'merchant_approved_apply_required',
          'merchant_approved_reapply_required'
        ].includes(this.refundInfo.display_state)
      },
      refundDisplayText() {
        const info = this.refundInfo || {}
        return info.display_state_text || info.service_state_text || info.state_text || ''
      },
      showFooterActions() {
        return this.canCancelOrder || this.canPayOrder || this.canApplyRefund
      }
    },
    onLoad({ orderId }) {
      this.orderId = orderId
      this.getOrderDetail()
      uni.$on('syncRefresh', (val, isCur) => {
        if (!isCur) this.canReset = val
      })
    },
    onUnload() { uni.$off('syncRefresh') },
    onShow() {
      if (this.hasShownOnce) {
        this.getOrderDetail()
      }
      this.hasShownOnce = true
      this.canReset = false
    },
    methods: {
      getOrderDetail(canReset = false) {
        this.isLoading = true
        this.loadError = ''
        OrderApi.detail(this.orderId)
          .then(result => {
            const order = result && result.data ? result.data.order : null
            if (!order || !order.order_id) throw new Error('订单详情数据无效')
            this.order = order
            this.setting = result.data.setting || {}
            this.refundInfo = this.normalizeRefundInfo(this.order)
          })
          .catch(() => {
            this.order = {}
            this.refundInfo = {}
            this.loadError = '订单详情加载失败，请稍后重试'
          })
          .finally(() => { this.isLoading = false })
        canReset && uni.$emit('syncRefresh', true, true)
      },
      getOrderStateText(order) {
        if (!order || !order.order_id) return '--'
        if (this.hasPrimaryRefundState) return this.refundInfo.display_state_text || this.refundInfo.service_state_text || this.refundInfo.state_text || '--'
        return order.service_state_text || order.state_text || '--'
      },
      normalizeRefundInfo(order) {
        const source = (order && order.refund_info) || {}
        return {
          order_refund_id: source.order_refund_id || '',
          state: source.state == null ? '' : Number(source.state),
          state_text: source.state_text || '',
          service_state: source.service_state || '',
          service_state_text: source.service_state_text || '',
          display_state: source.display_state || '',
          display_state_text: source.display_state_text || '',
          refund_entry_mode: source.refund_entry_mode || '',
          refund_guidance: source.refund_guidance || '',
          ios_apple_refund_required: !!source.ios_apple_refund_required,
          ios_refund_risk_status: Number(source.ios_refund_risk_status || 0),
          ios_refund_risk_text: source.ios_refund_risk_text || '',
          ios_refund_inquiry_received: !!source.ios_refund_inquiry_received,
          latest_ios_refund_inquiry: source.latest_ios_refund_inquiry || null,
          can_cancel: source.can_cancel === true,
          apply_desc: source.apply_desc || '',
          refuse_desc: source.refuse_desc || '',
          refund_money: source.refund_money == null ? '' : source.refund_money,
          audit_status: source.audit_status == null ? '' : Number(source.audit_status),
          is_terminal: !!source.is_terminal,
          images: source.images || []
        }
      },
      getGamePlatformText(value) {
        if (value === 'pc') return '端游'
        if (value === 'mobile') return '手游'
        return ''
      },
      isAdultConfirmed(value) {
        return value === true || value === 1 || value === '1' || value === 'true'
      },
      getAdultConfirmText(value) {
        if (value === null || typeof value === 'undefined' || value === '') return '--'
        return this.isAdultConfirmed(value) ? '已确认' : '未确认'
      },
      getRefundOrderGoodsId(order = this.order) {
        const goodsList = Array.isArray(order.goods) ? order.goods : []
        const refundableGoods = goodsList.find(item => item.can_refund)
        return refundableGoods ? refundableGoods.order_goods_id : (goodsList[0] ? goodsList[0].order_goods_id : '')
      },
      handleCopy(value) {
        uni.setClipboardData({ data: value, success: () => this.$toast('复制成功'), fail: ({ errMsg }) => this.$toast('复制失败 ' + errMsg) })
      },
      handleTargetGoods(goodsId) {
        this.$navTo('pages/goods/detail', { goodsId })
      },
      onCancel(orderId) {
        uni.showModal({ title: '友情提示', content: '确认要取消该订单吗？', success: o => { if (o.confirm) OrderApi.cancel(orderId).then(result => { this.$toast(result.message); setTimeout(() => this.getOrderDetail(true), 1500) }) } })
      },
      onPay(orderId) {
        this.$navTo('pages/checkout/cashier/index', { orderId })
      },
      onRefund() {
        const orderGoodsId = this.getRefundOrderGoodsId()
        if (!orderGoodsId) return this.$toast('暂无可退款商品')
        this.$navTo('pages/refund/apply', { orderGoodsId })
      }
    }
  }
</script>

<style>
  page { background: #f4f4f4; }
</style>
<style lang="scss" scoped>
  .container { padding-bottom: calc(env(safe-area-inset-bottom) + 106rpx + 6rpx); }
  .load-error { margin-top: 30rpx; color: #666; text-align: center; .retry-btn { display: inline-block; margin-top: 24rpx; padding: 12rpx 30rpx; border: 1rpx solid #c1c1c1; border-radius: 28rpx; color: #333; } }
  .header { display: flex; justify-content: space-between; background-color: #e8c269; height: 280rpx; padding: 56rpx 30rpx 0; .order-status { display: flex; align-items: center; height: 128rpx; .status-text { color: #fff; font-size: 38rpx; font-weight: bold; } } .next-action { display: flex; align-items: center; height: 128rpx; .action-btn { min-width: 152rpx; height: 56rpx; padding: 0 30rpx; background-color: #fff; border-radius: 28rpx; color: #c7a157; display: flex; justify-content: center; align-items: center; } } }
  .card-area { margin-top: -50rpx; }
  .i-card { background: #fff; padding: 24rpx; width: 94%; box-shadow: 0 1rpx 5rpx 0 rgba(0,0,0,.05); margin: 0 auto 20rpx; border-radius: 20rpx; }
  .goods-list .goods-item { margin-bottom: 40rpx; .goods-main { display: flex; } .goods-image { width: 180rpx; height: 180rpx; .image { display: block; width: 100%; height: 100%; border-radius: 8rpx; } } .goods-content { flex: 1; padding-left: 16rpx; padding-top: 16rpx; } .goods-trade { padding-top: 16rpx; width: 150rpx; text-align: right; font-size: 26rpx; } }
  .order-info, .trade-info, .refund-info { .info-item { display: flex; margin-bottom: 24rpx; .item-lable { font-size: 24rpx; color: #999; margin-right: 30rpx; } .item-content { flex: 1; display: flex; align-items: center; font-size: 26rpx; color: #333; .act-copy { margin-left: 20rpx; padding: 2rpx 20rpx; font-size: 22rpx; color: #666; border: 1rpx solid #c1c1c1; border-radius: 16rpx; } } } .divider { height: 1rpx; background: #f1f1f1; margin-bottom: 24rpx; } .trade-total { display: flex; justify-content: flex-end; .goods-price { color: $main-bg; font-size: 28rpx; } } }
  .refund-info { .card-title { font-size: 28rpx; font-weight: bold; margin-bottom: 24rpx; color: #333; } }
  .footer-fixed { position: fixed; bottom: var(--window-bottom); left: var(--window-left); right: var(--window-right); z-index: 11; box-shadow: 0 -4rpx 40rpx 0 rgba(151,151,151,.24); background: #fff; padding-bottom: env(safe-area-inset-bottom); .btn-wrapper { height: 106rpx; display: flex; align-items: center; justify-content: flex-end; padding: 0 30rpx; } .btn-item { min-width: 180rpx; border-radius: 30rpx; padding: 12rpx 26rpx; font-size: 28rpx; color: #383838; text-align: center; border: 1rpx solid #a8a8a8; margin-left: 24rpx; &.active { border: none; background: linear-gradient(to right, $main-bg, $main-bg2); color: $main-text; } } }
</style>
