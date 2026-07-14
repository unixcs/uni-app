<template>
  <view class="ios-refund-guide">
    <view class="guide-intro">{{ submitted ? submittedIntro : applyIntro }}</view>
    <view class="guide-reason">由于 iOS 系统端的机制限制，您的这笔订单是通过 Apple（App Store）完成的，因此需要前往 Apple 官方渠道再次提交退款申请。</view>
    <view class="guide-title">操作步骤如下：</view>
    <view>1. 打开 iPhone“设置” &gt; 点击顶部您的名字 &gt; “媒体与购买项目” &gt; “查看账户” &gt; “购买记录”。</view>
    <view>2. 在列表中找到这笔订单，点击“报告问题”。</view>
    <view>3. 选择“请求退款”并按提示提交即可。</view>
    <view class="guide-title">请注意：</view>
    <view>1. 退款由 Apple 审核，最终结果以 Apple 为准。</view>
    <view>2. 退款成功后，您将失去对应商品的权限。</view>
  </view>
</template>
<script>
export default {
  name: 'IosAppleRefundGuide',
  props: { submitted: { type: Boolean, default: false } },
  data() {
    return {
      applyIntro: '如需退款，请先点击下方的“提交退款申请”按钮，并参考以下 iOS 官方退款流程。',
      submittedIntro: '本地退款申请已提交，请继续前往 Apple 官方渠道申请退款。'
    }
  }
}
</script>
<style lang="scss" scoped>
.ios-refund-guide { color: #666; font-size: 26rpx; line-height: 1.8; white-space: normal; }
.guide-intro { color: #333; font-weight: 500; }
.guide-reason, .guide-title { margin-top: 12rpx; }
.guide-title { color: #333; font-weight: 500; }
</style>
