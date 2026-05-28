<template>
  <a-modal
    :title="title"
    :width="560"
    :visible="visible"
    :isLoading="isLoading"
    :confirmLoading="isLoading"
    :maskClosable="false"
    @ok="handleCancel"
    @cancel="handleCancel"
  >
    <a-spin :spinning="isLoading">
      <div class="container">
        <!-- 物流信息 -->
        <div class="express-info">
          <div class="info-item">
            <div class="item-lable">物流公司：</div>
            <div class="item-content">
              <span v-if="record.delivery_method == DeliveryMethodEnum.NORMAL.value">无需物流</span>
              <span v-else>{{ record.express ? record.express.express_name : '--' }}</span>
            </div>
          </div>
          <div class="info-item">
            <div class="item-lable">物流单号：</div>
            <div class="item-content">
              <span>{{ record.express_no ? record.express_no : '--' }}</span>
              <div
                v-show="record.express_no"
                class="act-copy"
                @click.stop="handleCopy(record.express_no)"
              >复制</div>
            </div>
          </div>
        </div>
        <!-- 物流轨迹 -->
        <div v-if="traces.length" class="logis-detail">
          <div
            class="logis-item"
            :class="{ first: index === 0 }"
            v-for="(item, index) in traces"
            :key="index"
          >
            <div class="logis-item-content">
              <div class="logis-item-content__describe">
                <span class="f-13">{{ item.context }}</span>
              </div>
              <div class="logis-item-content__time">
                <span class="f-11">{{ item.time }}</span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </a-spin>
  </a-modal>
</template>

<script>
import { assignment } from '@/utils/util'
import * as DeliveryApi from '@/api/order/delivery'
import { DeliveryMethodEnum } from '@/common/enum/order/delivery'

export default {
  data () {
    return {
      // 对话框标题
      title: '物流跟踪',
      // 标签布局属性
      labelCol: { span: 6 },
      // 输入框布局属性
      wrapperCol: { span: 14 },
      // modal(对话框)是否可见
      visible: false,
      // modal(对话框)确定按钮 loading
      isLoading: false,
      // 当前记录
      record: {},
      // 物流跟踪信息
      traces: []
    }
  },
  beforeCreate () {
    assignment(this, { DeliveryMethodEnum })
  },
  created () {
  },
  methods: {

    // 显示对话框
    show (record, index) {
      // 显示窗口
      this.visible = true
      // 当前记录
      this.record = record
      // 查询物流跟踪记录
      this.getExpressTraces()
    },

    // 查询物流跟踪记录
    getExpressTraces () {
      this.isLoading = true
      DeliveryApi.traces(this.record.delivery_id)
        .then(result => this.traces = result.data.traces)
        .finally(() => this.isLoading = false)
    },

    // 复制指定内容
    handleCopy (value) {
      this.$copyText(value).then(res => {
        this.$message.success('复制成功', 0.8)
      })
    },

    // 关闭对话框事件
    handleCancel () {
      this.visible = false
      this.traces = []
    }

  }
}
</script>
<style lang="less" scoped>
@import '~ant-design-vue/es/style/themes/default.less';

// 物流公司
.express-info {
  background: #fff;
  padding: 12px 0;
  margin-bottom: 10px;

  .info-item {
    display: flex;
    margin-bottom: 12px;

    &:last-child {
      margin-bottom: 0;
    }

    .item-lable {
      display: flex;
      align-items: center;
      font-size: 13px;
      color: #333;
      margin-right: 3px;
    }

    .item-content {
      flex: 1;
      display: flex;
      align-items: center;
      font-size: 13px;
      color: #333;

      .act-copy {
        margin-left: 10px;
        padding: 1px 10px;
        font-size: 11px;
        color: #666;
        border: 1px solid #c1c1c1;
        border-radius: 8px;
        cursor: pointer;
        transition: all 0.4s;

        &:hover {
          color: @primary-color;
          border: 1px solid @primary-color;
        }
      }
    }
  }
}

// 物流轨迹
.logis-detail {
  padding: 0 15px;
  background-color: #fff;
  max-height: 420px;
  overflow-y: auto;

  .logis-item {
    position: relative;
    padding: 10px 0 10px 25px;
    box-sizing: border-box;
    border-left: 2px solid #ccc;

    &.first {
      border-left: 2px solid @primary-color;

      &:after {
        background: @primary-color;
      }

      .logis-item-content {
        background: @primary-color;
        color: #fff;

        &:after {
          border-bottom-color: @primary-color;
        }
      }
    }

    &:after {
      content: ' ';
      display: inline-block;
      position: absolute;
      left: -6px;
      top: 30px;
      width: 9px;
      height: 9px;
      border-radius: 10px;
      background: #bdbdbd;
      border: 2px solid #fff;
    }

    .logis-item-content {
      position: relative;
      background: #f9f9f9;
      padding: 5px 10px;
      box-sizing: border-box;
      color: #666;

      &:after {
        content: '';
        display: inline-block;
        position: absolute;
        left: -10px;
        top: 18px;
        border-left: 10px solid #fff;
        border-bottom: 10px solid #f3f3f3;
      }
    }
  }
}
</style>