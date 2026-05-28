<template>
  <div>
    <!-- 加载中 -->
    <a-spin :spinning="isLoading" />
    <!-- 订单内容 -->
    <div v-if="!isLoading" class="order-content">
      <!-- 订单进度步骤条 -->
      <a-card :bordered="false">
        <div class="order-progress" :class="`progress-${progress}`">
          <ul>
            <li>
              <span>下单时间</span>
              <div class="tip">{{ record.create_time }}</div>
            </li>
            <li>
              <span>付款</span>
              <div
                v-if="record.pay_status == PayStatusEnum.SUCCESS.value"
                class="tip"
              >付款于 {{ record.pay_time }}</div>
            </li>
            <li>
              <span>发货</span>
              <div
                v-if="record.delivery_status != DeliveryStatusEnum.NOT_DELIVERED.value"
                class="tip"
              >发货于 {{ record.delivery_time }}</div>
            </li>
            <li>
              <span>收货</span>
              <div
                v-if="record.receipt_status == ReceiptStatusEnum.RECEIVED.value"
                class="tip"
              >收货于 {{ record.receipt_time }}</div>
            </li>
            <li>
              <span>完成</span>
              <div
                v-if="record.order_status == OrderStatusEnum.COMPLETED.value"
                class="tip"
              >完成于 {{ record.receipt_time }}</div>
            </li>
          </ul>
        </div>
      </a-card>

      <!-- 订单信息 -->
      <a-card class="mt-20" :bordered="false">
        <!-- 订单操作 -->
        <template v-if="record.order_status != OrderStatusEnum.CANCELLED.value">
          <div class="ant-descriptions-title">订单操作</div>
          <!-- 提示栏 -->
          <div class="alerts mt-10 mb-15">
            <a-alert
              v-if="record.order_status== OrderStatusEnum.APPLY_CANCEL.value"
              message="当前买家已付款并申请取消订单，请审核是否同意，如同意则自动退回付款金额（原路退款）并关闭订单。"
              banner
            />
          </div>
          <!-- 操作栏 -->
          <div class="actions clearfix mt-10">
            <div
              v-if="$module('order-updatePrice') && $auth('/order/detail.updatePrice')"
              class="action-item"
            >
              <a-button
                v-if="record.pay_status == PayStatusEnum.PENDING.value"
                @click="handleUpdatePrice"
              >订单改价</a-button>
            </div>
            <div class="action-item" v-if="$auth('/order/list/all.deliver')">
              <a-button
                v-if="(
                  record.pay_status == PayStatusEnum.SUCCESS.value
                    && inArray(record.delivery_type, [ DeliveryTypeEnum.EXPRESS.value, DeliveryTypeEnum.NOTHING.value])
                    && record.delivery_status != DeliveryStatusEnum.DELIVERED.value
                    && !inArray(record.order_status, [OrderStatusEnum.CANCELLED.value, OrderStatusEnum.APPLY_CANCEL.value])
                )"
                type="primary"
                @click="handleDelivery"
              >发货</a-button>
            </div>
            <div class="action-item" v-if="$auth('/order/list/all.cancel')">
              <a-button
                v-if="record.order_status == OrderStatusEnum.APPLY_CANCEL.value"
                type="primary"
                @click="handleCancel"
              >审核取消订单</a-button>
            </div>
            <div class="action-item" v-if="$auth('/order/detail.merchantRemark')">
              <a-button @click="handleMerchantRemark">商家备注</a-button>
            </div>
            <div class="action-item" v-if="$auth('/order/detail.updateAddress')">
              <a-button
                v-if="(
                    record.delivery_type == DeliveryTypeEnum.EXPRESS.value
                    && record.delivery_status != DeliveryStatusEnum.DELIVERED.value
                    && !inArray(record.order_status, [OrderStatusEnum.CANCELLED.value, OrderStatusEnum.APPLY_CANCEL.value])
                )"
                @click="handleUpdateAddress()"
              >修改收货地址</a-button>
            </div>
            <div
              v-if="$module('order-printer') && $auth('/order/detail.printer')"
              class="action-item"
            >
              <a-button @click="handlePrinter">打印小票</a-button>
            </div>
          </div>
          <a-divider class="o-divider" />
        </template>
        <!-- 订单信息 -->
        <a-descriptions title="订单信息">
          <a-descriptions-item label="订单号">{{ record.order_no }}</a-descriptions-item>
          <a-descriptions-item label="实付款金额">￥{{ record.pay_price }}</a-descriptions-item>
          <a-descriptions-item label="支付方式">
            <a-tag
              color="green"
              v-if="record.pay_method"
            >{{ PaymentMethodEnum[record.pay_method].name }}</a-tag>
            <span v-else>--</span>
          </a-descriptions-item>
          <a-descriptions-item label="配送方式">
            <a-tag color="green">{{ DeliveryTypeEnum[record.delivery_type].name }}</a-tag>
          </a-descriptions-item>
          <a-descriptions-item label="运费金额">￥{{ record.express_price }}</a-descriptions-item>
          <a-descriptions-item label="订单状态">
            <a-tag
              :color="renderOrderStatusColor(record.order_status)"
            >{{ OrderStatusEnum[record.order_status].name }}</a-tag>
          </a-descriptions-item>
          <a-descriptions-item label="买家信息">
            <a-tooltip>
              <template slot="title">会员ID: {{ record.user ? record.user.user_id : '-' }}</template>
              <span class="c-p">{{ record.user ? record.user.nick_name: '-' }}</span>
            </a-tooltip>
          </a-descriptions-item>
          <a-descriptions-item label="买家留言">
            <span>{{ record.buyer_remark ? record.buyer_remark :'-' }}</span>
          </a-descriptions-item>
          <a-descriptions-item label="商家备注">
            <span>{{ record.merchant_remark ? record.merchant_remark :'-' }}</span>
          </a-descriptions-item>
          <a-descriptions-item v-if="record.trade" label="第三方支付订单号">
            <span>{{ record.trade ? record.trade.out_trade_no :'-' }}</span>
          </a-descriptions-item>
          <a-descriptions-item v-if="record.trade" label="支付流水号">
            <span>{{ record.trade ? record.trade.trade_no :'-' }}</span>
          </a-descriptions-item>
        </a-descriptions>
      </a-card>

      <!-- 商品信息 -->
      <a-card class="mt-20" :bordered="false">
        <div class="ant-descriptions-title">订单商品</div>
        <div class="goods-list">
          <a-table
            rowKey="order_goods_id"
            :columns="goodsColumns"
            :dataSource="record.goods"
            :pagination="false"
          >
            <!-- 商品信息 -->
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
            <!-- 商品编码 -->
            <span slot="goods_no" slot-scope="text">{{ text ? text : '--' }}</span>
            <!-- 单价	 -->
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
            <!-- 购买数量	 -->
            <span slot="total_num" slot-scope="text">x{{ text }}</span>
            <!-- 商品总价 -->
            <span slot="total_price" slot-scope="text">￥{{ text }}</span>
          </a-table>
          <!-- 订单价格明细 -->
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
                <tr v-if="record.update_price.value != 0">
                  <td>商家改价：</td>
                  <td>{{ record.update_price.symbol }}￥{{ record.update_price.value }}</td>
                </tr>
                <tr>
                  <td>运费金额：</td>
                  <td>+￥{{ record.express_price }}</td>
                </tr>
                <tr>
                  <td>实付款金额：</td>
                  <td>
                    <strong class="c-p f-15">￥{{ record.pay_price }}</strong>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </a-card>

      <template v-if="record.order_type == OrderTypeEnum.PHYSICAL.value">
        <!-- 物流配送信息 -->
        <a-card
          v-if="(
            record.pay_status == PayStatusEnum.SUCCESS.value
              && record.delivery_status != DeliveryStatusEnum.NOT_DELIVERED.value
              && !inArray(record.order_status, [OrderStatusEnum.CANCELLED.value, OrderStatusEnum.APPLY_CANCEL.value])
          )"
          class="mt-20"
          :bordered="false"
        >
          <!-- 此处仅用于兼容旧物流数据 -->
          <a-descriptions v-if="!record.delivery.length && record.express" title="配送信息">
            <a-descriptions-item label="物流公司">{{ record.express.express_name }}</a-descriptions-item>
            <a-descriptions-item label="物流单号">{{ record.express_no }}</a-descriptions-item>
            <a-descriptions-item label="发货状态">
              <a-tag
                :color="record.delivery_status == DeliveryStatusEnum.DELIVERED.value ? 'green' : ''"
              >{{ DeliveryStatusEnum[record.delivery_status].name }}</a-tag>
            </a-descriptions-item>
            <a-descriptions-item label="发货时间">{{ record.delivery_time }}</a-descriptions-item>
          </a-descriptions>
          <!-- 新物流数据 (来源于发货单) -->
          <div v-if="record.delivery.length">
            <a-descriptions title="发货信息"></a-descriptions>
            <a-tabs :default-active-key="1" :class="{ 'hide-bar': record.delivery.length < 2 }">
              <a-tab-pane
                :tab="`包裹${index + 1}`"
                v-for="(item, index) in record.delivery"
                :key="index + 1"
              >
                <a-descriptions>
                  <a-descriptions-item label="发货状态">
                    <a-tag color="green">已发货</a-tag>
                  </a-descriptions-item>
                  <a-descriptions-item
                    label="物流公司"
                  >{{ item.delivery_method == DeliveryMethodEnum.NORMAL.value ? '无需物流' : item.express.express_name }}</a-descriptions-item>
                  <a-descriptions-item label="物流单号">{{ item.express_no ? item.express_no : '--' }}</a-descriptions-item>
                  <a-descriptions-item
                    v-if="item.delivery_method != DeliveryMethodEnum.NORMAL.value"
                    label="物流跟踪"
                  >
                    <a href="javascript:void(0);" @click="handleShowExpress(index)">点击查看</a>
                  </a-descriptions-item>
                </a-descriptions>
                <!-- 发货商品列表 -->
                <div class="deliver-goods-list clearfix">
                  <div class="goods-item" v-for="(goods, idx) in item.goods" :key="idx">
                    <a-tooltip>
                      <template slot="title">{{ goods.goods.goods_name }}</template>
                      <img class="goods-img" :src="goods.goods.goods_image" alt="商品图片" />
                      <div class="title">共{{ goods.delivery_num }}件</div>
                    </a-tooltip>
                  </div>
                </div>
              </a-tab-pane>
            </a-tabs>
          </div>
        </a-card>

        <!-- 买家收货信息 -->
        <a-card
          v-if="record.delivery_type == DeliveryTypeEnum.EXPRESS.value"
          class="mt-20"
          :bordered="false"
        >
          <!-- 收货信息 -->
          <a-descriptions title="买家收货地址">
            <a-descriptions-item label="收货人姓名">{{ record.address.name }}</a-descriptions-item>
            <a-descriptions-item label="联系电话">{{ record.address.phone }}</a-descriptions-item>
            <a-descriptions-item label="收货地区">
              {{ record.address.region.province }}
              {{ record.address.region.city }}
              {{ record.address.region.region }}
            </a-descriptions-item>
            <a-descriptions-item label="详细地址">{{ record.address.detail }}</a-descriptions-item>
          </a-descriptions>
        </a-card>
      </template>
    </div>
    <DeliveryForm ref="DeliveryForm" @handleSubmit="handleRefresh" />
    <CancelForm ref="CancelForm" @handleSubmit="handleRefresh" />
    <PrinterForm ref="PrinterForm" @handleSubmit="handleRefresh" />
    <PriceForm ref="PriceForm" @handleSubmit="handleRefresh" />
    <RemarkForm ref="RemarkForm" @handleSubmit="handleRefresh" />
    <AddressForm ref="AddressForm" @handleSubmit="handleRefresh" />
    <ExpressForm ref="ExpressForm" />
  </div>
</template>

<script>
import { inArray } from '@/utils/util'
import * as Api from '@/api/order'
import { GoodsItem, UserItem } from '@/components/Table'
import { DeliveryForm, CancelForm, PrinterForm, PriceForm, RemarkForm, ExpressForm, AddressForm } from './modules'
import {
  OrderTypeEnum,
  DeliveryStatusEnum,
  DeliveryTypeEnum,
  OrderSourceEnum,
  OrderStatusEnum,
  PayStatusEnum,
  ReceiptStatusEnum
} from '@/common/enum/order'
import { PaymentMethodEnum } from '@/common/enum/payment'
import { DeliveryMethodEnum } from '@/common/enum/order/delivery'

// 商品内容表头
const goodsColumns = [
  {
    title: '商品信息',
    scopedSlots: { customRender: 'goodsInfo' }
  },
  {
    title: '商品编码',
    dataIndex: 'goods_no',
    scopedSlots: { customRender: 'goods_no' }
  },
  {
    title: '重量(Kg)',
    dataIndex: 'goods_weight',
    scopedSlots: { customRender: 'goods_weight' }
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
    title: '商品总价',
    dataIndex: 'total_price',
    scopedSlots: { customRender: 'total_price' }
  }
]

export default {
  name: 'Index',
  components: {
    GoodsItem,
    UserItem,
    DeliveryForm,
    CancelForm,
    PrinterForm,
    PriceForm,
    RemarkForm,
    ExpressForm,
    AddressForm
  },
  data () {
    return {
      // 枚举类
      OrderTypeEnum,
      DeliveryStatusEnum,
      DeliveryTypeEnum,
      OrderSourceEnum,
      OrderStatusEnum,
      PayStatusEnum,
      ReceiptStatusEnum,
      PaymentMethodEnum,
      DeliveryMethodEnum,
      // 外部方法
      inArray,
      // 正在加载
      isLoading: true,
      // 订单ID
      orderId: null,
      // 订单详情
      record: {},
      // 订单步骤位置
      progress: 2,
      // 商品内容表头
      goodsColumns
    }
  },
  created () {
    // 记录订单ID
    this.orderId = this.$route.query.orderId
    // 刷新页面
    this.handleRefresh()
  },
  methods: {

    // 刷新页面
    handleRefresh () {
      // 获取当前记录
      this.getDetail()
    },

    // 获取当前记录
    getDetail () {
      const { orderId } = this
      this.isLoading = true
      Api.detail({ orderId })
        .then(result => {
          // 当前记录
          this.record = result.data.detail
          // 初始化数据
          this.initData()
        })
        .finally(() => this.isLoading = false)
    },

    // 初始化数据
    initData () {
      // 步骤条位置
      this.initProgress()
      // 发货单的商品信息
      this.initDeliveryGoods()
    },

    // 步骤条位置
    initProgress () {
      const { record } = this
      this.progress = 2
      record.pay_status == PayStatusEnum.SUCCESS.value && (this.progress += 1)
      record.delivery_status != DeliveryStatusEnum.NOT_DELIVERED.value && (this.progress += 1)
      record.receipt_status == ReceiptStatusEnum.RECEIVED.value && (this.progress += 1)
    },

    // 发货单的商品信息
    initDeliveryGoods () {
      const { record } = this
      if (record.delivery.length) {
        record.delivery.forEach(deliveryItem => {
          deliveryItem.goods.forEach(deliveryGoodsItem => {
            deliveryGoodsItem.goods = record.goods.find(goods => goods.order_goods_id == deliveryGoodsItem.order_goods_id)
          })
        })
      }
    },

    // 渲染订单状态标签颜色
    renderOrderStatusColor (orderStatus) {
      const { OrderStatusEnum } = this
      const ColorEnum = {
        [OrderStatusEnum.NORMAL.value]: '',
        [OrderStatusEnum.CANCELLED.value]: 'red',
        [OrderStatusEnum.APPLY_CANCEL.value]: 'red',
        [OrderStatusEnum.COMPLETED.value]: 'green'
      }
      return ColorEnum[orderStatus]
    },

    // 订单发货
    handleDelivery () {
      const { record } = this
      this.$refs.DeliveryForm.show(record)
    },

    // 审核取消订单
    handleCancel () {
      const { record } = this
      this.$refs.CancelForm.show(record)
    },

    // 小票打印
    handlePrinter () {
      const { record } = this
      this.$refs.PrinterForm.show(record)
    },

    // 订单改价
    handleUpdatePrice () {
      const { record } = this
      this.$refs.PriceForm.show(record)
    },

    // 修改收货地址
    handleUpdateAddress () {
      const { record } = this
      this.$refs.AddressForm.show(record)
    },

    // 商家备注
    handleMerchantRemark () {
      const { record } = this
      this.$refs.RemarkForm.show(record)
    },

    // 查看物流跟踪信息
    handleShowExpress (index) {
      const { record } = this
      this.$refs.ExpressForm.show(record.delivery[index], index)
    },

  }
}
</script>
<style lang="less" scoped>
@import '~ant-design-vue/es/style/themes/default.less';
// 订单详情页
.order-content {
  margin-bottom: 70px;

  /deep/.ant-descriptions-item > span {
    vertical-align: middle;
  }

  // 分割线
  .o-divider {
    margin-bottom: 32px;
  }

  // 步骤条
  .order-progress {
    height: 26px;
    line-height: 26px;
    background: #f8f8f8;
    border-radius: 13px;
    font-size: @font-size-base;
    text-align: center;
    position: relative;

    &:before,
    &:after {
      content: '';
      position: absolute;
      z-index: 2;
      left: 0;
      top: 0;
      bottom: 0;
      border-radius: 13px;
      background: @primary-color;
    }

    &:after {
      background: ~`colorPalette('@{primary-color}', 3) `;
      z-index: 1;
    }

    &.progress-1 {
      &:before {
        width: 0;
      }

      &:after {
        width: 20%;
      }
    }

    &.progress-2 {
      &:before {
        width: 20%;
      }

      &:after {
        width: 40%;
      }
    }

    &.progress-3 {
      &:before {
        width: 40%;
      }

      &:after {
        width: 60%;
      }
    }

    &.progress-4 {
      &:before {
        width: 60%;
      }

      &:after {
        width: 80%;
      }
    }

    &.progress-5 {
      &:before {
        width: 100%;
      }

      &:after {
        width: 100%;
      }

      li {
        &:nth-child(5) {
          color: #fff;
        }
      }
    }

    li {
      width: 20%;
      float: left;
      border-radius: 13px;
      position: relative;
      z-index: 3;
    }

    .tip {
      font-size: 12px;
      padding-top: 10px;
      color: #8c8c8c;
    }

    &.progress-1 li:nth-child(1),
    &.progress-2 li:nth-child(1),
    &.progress-3 li:nth-child(1),
    &.progress-4 li:nth-child(1),
    &.progress-5 li:nth-child(1) {
      color: #fff;
    }

    &.progress-2 li:nth-child(2),
    &.progress-3 li:nth-child(2),
    &.progress-4 li:nth-child(2),
    &.progress-5 li:nth-child(2) {
      color: #fff;
    }

    &.progress-3 li:nth-child(3),
    &.progress-4 li:nth-child(3),
    &.progress-5 li:nth-child(3) {
      color: #fff;
    }

    &.progress-4 li:nth-child(4),
    &.progress-5 li:nth-child(4) {
      color: #fff;
    }
  }
  // 商品列表
  .goods-list {
    /deep/table {
      table-layout: auto;
    }
    .order-price {
      padding: 8px 20px;
      text-align: right;
    }
  }
  // 操作栏
  .actions {
    .action-item {
      float: left;
      margin-right: 8px;
    }
  }
}

// 隐藏tab的标签
.hide-bar {
  /deep/.ant-tabs-bar {
    display: none;
  }
}

/deep/.ant-tabs-bar {
  margin-bottom: 20px;
}

// 已发货的商品列表
.deliver-goods-list {
  .goods-item {
    position: relative;
    border-radius: 4px;
    overflow: hidden;
    width: 65px;
    height: 65px;
    float: left;
    margin-right: 15px;
  }

  .goods-img {
    display: block;
    width: 100%;
    height: 100%;
  }

  .title {
    position: absolute;
    bottom: 0;
    width: 100%;
    text-align: center;
    background: rgba(0, 0, 0, 0.6);
    color: #fff;
    padding: 2px 0;
    font-size: 12px;
  }
}
</style>
