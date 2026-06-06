<template>
  <a-card :bordered="false">
    <content-header title="定时任务"></content-header>
    <a-alert
      style="margin-bottom: 20px"
      message="系统定时任务用于处理商城系统的订单状态、订单结算、优惠券状态、分销结算、会员等级等数据的更新，所以必须要开启"
      type="warning"
      show-icon
    />
    <div class="content">
      <h4 class="title">第一步、启动定时任务</h4>
      <p class="text">
        <a href="https://www.example.com/doc/10046" target="_blank">点击查看定时任务启动教程</a>
      </p>
      <h4 class="title">第二步、测试定时任务是否启动</h4>
      <p class="text mtb-10">
        <a-button :loading="isBtnLoading" @click="handleTest()">点击测试</a-button>
      </p>
      <p class="text">
        <span>测试过程可能需要3秒-5秒钟时间</span>
      </p>
    </div>
  </a-card>
</template>

<script>
import * as Api from '@/api/setting/timer'
import { ContentHeader } from '@/components'

export default {
  components: {
    ContentHeader
  },
  data () {
    return {
      // 正在提交
      isBtnLoading: false
    }
  },
  created () {
  },
  methods: {

    /**
     * 点击测试
     */
    handleTest () {
      this.isBtnLoading = true
      Api.test()
        .then(result => {
          this.$message.success(result.message, 5)
        })
        .finally(() => this.isBtnLoading = false)
    },

  }
}
</script>

<style lang="less" scoped>
.content {
  padding: 20px 30px;
  .title {
    margin-top: 50px;
    margin-bottom: 10px;
    font-size: 15px;
    // font-weight: 600;
    &:first-child {
      margin-top: 0;
    }
  }
  .text {
    margin: 6px 0;
  }
}
</style>