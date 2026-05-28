<template>
  <a-card :bordered="false">
    <a-spin :spinning="isLoading">
      <div class="card-title">{{ $route.meta.title }}</div>
      <div class="table-operator">
        <!-- 搜索板块 -->
        <a-row class="row-item-search">
          <a-form class="search-form" :form="searchForm" layout="inline" @submit="handleSearch">
            <a-form-item label="订单查询">
              <a-input style="width: 337px" placeholder="请输入关键词" v-decorator="['searchValue']">
                <a-select
                  slot="addonBefore"
                  v-decorator="['searchType', { initialValue: 10 }]"
                  style="width: 100px"
                >
                  <a-select-option
                    v-for="(item, index) in SearchTypeEnum"
                    :key="index"
                    :value="item.value"
                  >{{ item.name }}</a-select-option>
                </a-select>
              </a-input>
            </a-form-item>
            <a-form-item label="订单来源">
              <a-select v-decorator="['orderSource', { initialValue: -1 }]">
                <a-select-option :value="-1">全部</a-select-option>
                <a-select-option
                  v-for="(item, index) in OrderSourceEnum.data"
                  :key="index"
                  :value="item.value"
                >{{ item.name }}</a-select-option>
              </a-select>
            </a-form-item>
            <a-form-item label="支付方式">
              <a-select v-decorator="['payMethod', { initialValue: '' }]">
                <a-select-option value>全部</a-select-option>
                <a-select-option
                  v-for="(item, index) in PaymentMethodEnum.data"
                  :key="index"
                  :value="item.value"
                >{{ item.name }}</a-select-option>
              </a-select>
            </a-form-item>
            <a-form-item label="下单时间">
              <a-range-picker format="YYYY-MM-DD" v-decorator="['betweenTime']" />
            </a-form-item>
            <a-form-item class="search-btn">
              <a-button class="mr-15" type="primary" icon="search" html-type="submit">搜索</a-button>
              <a-button @click="handleReset">重置</a-button>
            </a-form-item>
          </a-form>
        </a-row>
        <!-- 操作板块 -->
        <a-row class="actions">
          <div class="action-item" v-if="$auth('/order/tools/delivery/record')">
            <router-link :to="{ path: '/order/tools/delivery/record' }">
              <a-button type="default">发货记录</a-button>
            </router-link>
          </div>
        </a-row>
      </div>
      <!-- 列表内容 -->
      <div class="ant-table ant-table-scroll-position-left ant-table-default ant-table-bordered">
        <div class="ant-table-content">
          <div class="ant-table-body">
            <table>
              <thead class="ant-table-thead">
                <tr>
                  <th v-for="(item, index) in columns" :key="index">
                    <span class="ant-table-header-column">
                      <div>
                        <span class="ant-table-column-title">{{ item.title }}</span>
                      </div>
                    </span>
                  </th>
                </tr>
              </thead>
              <tbody class="ant-table-tbody">
                <template v-for="(item) in orderList.data">
                  <tr class="order-empty" :key="`order_${item.order_id}_1`">
                    <td colspan="8"></td>
                  </tr>
                  <tr :key="`order_${item.order_id}_2`">
                    <td colspan="8">
                      <span class="mr-20">{{ item.create_time }}</span>
                      <span class="mr-20">订单号：{{ item.order_no }}</span>
                      <platform-icon :name="item.platform" :showTips="true" />
                    </td>
                  </tr>
                  <tr
                    v-for="(goodsItm, goodsIdx) in item.goods"
                    :key="`orderGoods_${item.order_id}_${goodsIdx}`"
                  >
                    <td>
                      <GoodsItem
                        :data="{
                          image: goodsItm.goods_image,
                          imageAlt: '商品图片',
                          title: goodsItm.goods_name,
                          goodsProps: goodsItm.goods_props
                        }"
                      />
                    </td>
                    <td>
                      <p>￥{{ goodsItm.goods_price }}</p>
                      <p>×{{ goodsItm.total_num }}</p>
                    </td>
                    <template v-if="goodsIdx===0">
                      <td :rowspan="item.goods.length">
                        <p>￥{{ item.pay_price }}</p>
                        <p class="c-muted-1">(含运费：￥{{ item.express_price }})</p>
                      </td>
                      <td :rowspan="item.goods.length">
                        <UserItem :user="item.user" />
                      </td>
                      <td :rowspan="item.goods.length">
                        <a-tag>{{ DeliveryTypeEnum[item.delivery_type].name }}</a-tag>
                      </td>
                      <td :rowspan="item.goods.length">
                        <div class="f-12">
                          <p>收货人：{{ item.address.name }}</p>
                          <p>联系电话：{{ item.address.phone }}</p>
                          <p style="max-width: 260px" class="oneline-hide">
                            收货地址： {{ item.address.region.province }}
                            {{ item.address.region.city }}
                            {{ item.address.region.region }}
                            {{ item.address.detail }}
                          </p>
                        </div>
                      </td>
                      <td :rowspan="item.goods.length">
                        <p class="mtb-2">
                          <a-tag
                            :color="item.delivery_status == DeliveryStatusEnum.DELIVERED.value ? 'green' : ''"
                          >{{ DeliveryStatusEnum[item.delivery_status].name }}</a-tag>
                        </p>
                      </td>
                      <td :rowspan="item.goods.length">
                        <div class="actions">
                          <router-link
                            v-if="$auth('/order/detail')"
                            :to="{ path: '/order/detail', query: { orderId: item.order_id } }"
                          >详情</router-link>
                          <template
                            v-if="(
                              item.pay_status == PayStatusEnum.SUCCESS.value
                                && item.delivery_type == DeliveryTypeEnum.EXPRESS.value
                                && item.delivery_status != DeliveryStatusEnum.DELIVERED.value
                                && !inArray(item.order_status, [OrderStatusEnum.CANCELLED.value, OrderStatusEnum.APPLY_CANCEL.value])
                            )"
                          >
                            <a v-action:deliver @click="handleDelivery(item)">发货</a>
                          </template>
                        </div>
                      </td>
                    </template>
                  </tr>
                </template>
              </tbody>
            </table>
          </div>
          <!-- 空状态 -->
          <a-empty v-if="!orderList.data.length" :image="simpleImage" />
        </div>
      </div>
      <!-- 分页器 -->
      <div v-if="orderList.data.length" class="pagination">
        <a-pagination
          :current="page"
          :pageSize="orderList.per_page"
          :total="orderList.total"
          @change="onChangePage"
        />
      </div>
      <DeliveryForm ref="DeliveryForm" @handleSubmit="handleRefresh" />
    </a-spin>
  </a-card>
</template>

<script>
import { Empty } from 'ant-design-vue'
import { inArray, assignment } from '@/utils/util'
import * as Api from '@/api/order'
import PlatformIcon from '@/components/PlatformIcon'
import { GoodsItem, UserItem } from '@/components/Table'
import {
  DeliveryStatusEnum,
  DeliveryTypeEnum,
  OrderSourceEnum,
  OrderStatusEnum,
  PayStatusEnum,
  ReceiptStatusEnum
} from '@/common/enum/order'
import { PaymentMethodEnum } from '@/common/enum/payment'
import { DeliveryForm } from '../../modules'

// 表格字段
const columns = [
  {
    title: '商品信息',
    align: 'center',
    dataIndex: 'goods',
    scopedSlots: { customRender: 'goods' }
  },
  {
    title: '单价/数量',
    align: 'center',
    scopedSlots: { customRender: 'unit_price' }
  },
  {
    title: '实付款',
    align: 'center',
    dataIndex: 'pay_price',
    scopedSlots: { customRender: 'pay_price' }
  },
  {
    title: '买家',
    dataIndex: 'user',
    scopedSlots: { customRender: 'user' }
  },
  {
    title: '配送方式',
    dataIndex: 'delivery_type',
    scopedSlots: { customRender: 'delivery_type' }
  },
  {
    title: '配送信息',
    dataIndex: 'delivery',
    scopedSlots: { customRender: 'delivery' }
  },
  {
    title: '发货状态',
    dataIndex: 'status',
    scopedSlots: { customRender: 'status' }
  },
  {
    title: '操作',
    dataIndex: 'action',
    width: '180px',
    scopedSlots: { customRender: 'action' }
  }
]

// 搜索关键词类型枚举
const SearchTypeEnum = [
  { name: '订单号', value: 10 },
  { name: '会员昵称', value: 20 },
  { name: '会员ID', value: 30 }
]

export default {
  name: 'Index',
  components: {
    PlatformIcon,
    GoodsItem,
    UserItem,
    DeliveryForm
  },
  data () {
    return {
      // 订单类型
      dataType: 'delivery',
      // 当前表单元素
      searchForm: this.$form.createForm(this),
      // 查询参数
      queryParam: {},
      // 正在加载
      isLoading: false,
      // 表格字段
      columns,
      // 当前页码
      page: 1,
      // 列表数据
      orderList: { data: [], total: 0, per_page: 10 },
    }
  },
  beforeCreate () {
    // 批量给当前实例赋值
    assignment(this, {
      inArray,
      DeliveryStatusEnum,
      DeliveryTypeEnum,
      OrderSourceEnum,
      OrderStatusEnum,
      PayStatusEnum,
      ReceiptStatusEnum,
      PaymentMethodEnum,
      SearchTypeEnum,
      simpleImage: Empty.PRESENTED_IMAGE_SIMPLE
    })
  },
  created () {
    // 初始化页面
    this.init()
  },
  methods: {

    // 初始化页面
    init () {
      this.searchForm.resetFields()
      this.queryParam = {}
      this.handleRefresh(true)
    },

    // 获取列表数据
    getList () {
      const { dataType, queryParam, page } = this
      this.isLoading = true
      return Api.list({ dataType, ...queryParam, deliveryType: DeliveryTypeEnum.EXPRESS.value, page })
        .then(response => {
          this.orderList = response.data.list
        })
        .finally(() => this.isLoading = false)
    },

    /**
     * 刷新列表
     * @param Boolean bool 强制刷新到第一页
     */
    handleRefresh (bool = false) {
      bool && (this.page = 1)
      this.getList()
    },

    // 确认搜索
    handleSearch (e) {
      e.preventDefault()
      this.searchForm.validateFields((error, values) => {
        if (!error) {
          this.queryParam = { ...this.queryParam, ...values }
          this.handleRefresh(true)
        }
      })
    },

    // 重置搜索表单
    handleReset () {
      this.searchForm.resetFields()
    },

    // 翻页事件
    onChangePage (current) {
      this.page = current
      this.handleRefresh()
    },

    // 订单发货
    handleDelivery (item) {
      this.$refs.DeliveryForm.show(item)
    }

  }
}
</script>
<style lang="less" scoped>
// 分页器
.pagination {
  margin-top: 16px;
  .ant-pagination {
    float: right;
  }
}

.ant-table {
  table {
    border: none;
    border-collapse: collapse;
  }
  .ant-table-thead > tr {
    border: 1px solid #e8e8e8;
  }
  tr.order-empty {
    height: 15px;
    border: 1px solid #fff;
    td {
      padding: 0;
      border-right: none;
      border-left: none;
      background: none !important;
    }
  }
}

.ant-table-thead > tr > th {
  border-right: none;
  border-bottom: none;
  padding: 12px 12px;
  font-weight: bold;
}
.ant-table-tbody > tr > td {
  border-right: 1px solid #e8e8e8;
  border-left: 1px solid #e8e8e8;
  padding: 12px 12px;
  // text-align: center;
}
</style>
