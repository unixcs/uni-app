<template>
  <a-card :bordered="false">
    <a-spin :spinning="isLoading">
      <div class="card-title">{{ $route.meta.title }}</div>
      <div class="table-operator">
        <a-row class="row-item-search">
          <a-form class="search-form" :form="searchForm" layout="inline" @submit="handleSearch">
            <a-form-item label="关键词">
              <a-input style="width: 350px" placeholder="请输入关键词" v-decorator="['searchValue']">
                <a-select slot="addonBefore" v-decorator="['searchType', { initialValue: 10 }]" style="width: 150px">
                  <a-select-option v-for="(item, index) in SearchTypeEnum" :key="index" :value="item.value">{{ item.name }}</a-select-option>
                </a-select>
              </a-input>
            </a-form-item>
            <a-form-item label="订单来源">
              <a-select v-decorator="['orderSource', { initialValue: -1 }]">
                <a-select-option :value="-1">全部</a-select-option>
                <a-select-option v-for="(item, index) in OrderSourceEnum.data" :key="index" :value="item.value">{{ item.name }}</a-select-option>
              </a-select>
            </a-form-item>
            <a-form-item label="支付方式">
              <a-select v-decorator="['payMethod', { initialValue: '' }]">
                <a-select-option value>全部</a-select-option>
                <a-select-option v-for="(item, index) in PaymentMethodEnum.data" :key="index" :value="item.value">{{ item.name }}</a-select-option>
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
      </div>

      <div class="ant-table ant-table-scroll-position-left ant-table-default ant-table-bordered">
        <div class="ant-table-content">
          <div class="ant-table-scroll">
            <div class="ant-table-body" style="overflow-x: scroll;">
              <table style="width: 1500px;">
                <thead class="ant-table-thead">
                  <tr>
                    <th v-for="(item, index) in columns" :key="index">
                      <span class="ant-table-header-column">
                        <div><span class="ant-table-column-title">{{ item.title }}</span></div>
                      </span>
                    </th>
                  </tr>
                </thead>
                <tbody class="ant-table-tbody">
                  <template v-for="item in orderList.data">
                    <tr class="order-empty" :key="`order_${item.order_id}_1`"><td colspan="8"></td></tr>
                    <tr :key="`order_${item.order_id}_2`">
                      <td colspan="8">
                        <span class="mr-20">{{ item.create_time }}</span>
                        <span class="mr-20">订单号：{{ item.order_no }}</span>
                        <platform-icon :name="item.platform" :showTips="true" />
                      </td>
                    </tr>
                    <tr :key="`order_${item.order_id}_3`">
                      <td>
                        <GoodsItem
                          :data="{
                            image: firstGoods(item).goods_image,
                            imageAlt: '套餐图片',
                            title: firstGoods(item).goods_name,
                            goodsProps: firstGoods(item).goods_props
                          }"
                        />
                        <p class="c-muted-1 mt-5">共 {{ totalGoodsNum(item) }} 件</p>
                      </td>
                      <td>
                        <UserItem :user="item.user" />
                        <p class="c-muted-1 mt-5">会员ID：{{ item.user ? item.user.user_id : '--' }}</p>
                      </td>
                      <td>
                        <p>联系人：{{ getServiceContactField(item, 'contact_name') || '--' }}</p>
                        <p>电话：{{ getServiceContactField(item, 'contact_mobile') || '--' }}</p>
                        <p class="c-muted-1">时间偏好：{{ getServiceContactField(item, 'time_preference') || '--' }}</p>
                      </td>
                      <td>
                        <a-tag :color="item.pay_status == PayStatusEnum.SUCCESS.value ? 'green' : ''">{{ PayStatusEnum[item.pay_status].name }}</a-tag>
                      </td>
                      <td>
                        <a-tag :color="getServiceStatusColor(item)">{{ getServiceStatusText(item) }}</a-tag>
                      </td>
                      <td>
                        <p>￥{{ item.pay_price }}</p>
                      </td>
                      <td>
                        <p>买家备注：{{ item.buyer_remark || '--' }}</p>
                        <p class="c-muted-1 mt-5">商家备注：{{ item.merchant_remark || '--' }}</p>
                      </td>
                      <td>
                        <div class="actions">
                          <router-link v-if="$auth('/order/detail')" :to="{ path: '/order/detail', query: { orderId: item.order_id } }" target="_blank">详情</router-link>
                        </div>
                      </td>
                    </tr>
                  </template>
                </tbody>
              </table>
            </div>
            <a-empty v-if="!orderList.data.length" :image="simpleImage" />
          </div>
        </div>
      </div>

      <div v-if="orderList.data.length" class="pagination">
        <a-pagination :current="page" :pageSize="orderList.per_page" :total="orderList.total" @change="onChangePage" />
      </div>
    </a-spin>
  </a-card>
</template>

<script>
import { Empty } from 'ant-design-vue'
import { assignment } from '@/utils/util'
import * as Api from '@/api/order'
import PlatformIcon from '@/components/PlatformIcon'
import { GoodsItem, UserItem } from '@/components/Table'
import { OrderSourceEnum, OrderStatusEnum, PayStatusEnum } from '@/common/enum/order'
import { PaymentMethodEnum } from '@/common/enum/payment'

const columns = [
  { title: '套餐信息' },
  { title: '买家' },
  { title: '联系方式' },
  { title: '付款状态' },
  { title: '服务状态' },
  { title: '金额' },
  { title: '备注' },
  { title: '操作' }
]

const SearchTypeEnum = [
  { name: '订单号', value: 10 },
  { name: '第三方支付订单号', value: 60 },
  { name: '会员昵称', value: 20 },
  { name: '会员ID', value: 30 },
  { name: '联系人姓名', value: 40 },
  { name: '联系人电话', value: 50 }
]

export default {
  name: 'Index',
  components: { PlatformIcon, GoodsItem, UserItem },
  data () {
    return {
      dataType: this.getDataType(),
      searchForm: this.$form.createForm(this),
      queryParam: {},
      isLoading: false,
      columns,
      page: 1,
      orderList: { data: [], total: 0, per_page: 10 }
    }
  },
  beforeCreate () {
    assignment(this, {
      OrderSourceEnum,
      OrderStatusEnum,
      PayStatusEnum,
      PaymentMethodEnum,
      SearchTypeEnum,
      simpleImage: Empty.PRESENTED_IMAGE_SIMPLE
    })
  },
  watch: {
    $route () {
      this.init()
    }
  },
  created () {
    this.init()
  },
  methods: {
    init () {
      this.dataType = this.getDataType()
      this.searchForm.resetFields()
      this.queryParam = {}
      this.handleRefresh(true)
    },
    getDataType () {
      return this.$route.path.split('/')[3].replace('-', '_')
    },
    getList () {
      const { dataType, queryParam, page } = this
      this.isLoading = true
      return Api.list({ dataType, ...queryParam, page })
        .then(response => { this.orderList = response.data.list })
        .finally(() => { this.isLoading = false })
    },
    handleRefresh (bool = false) {
      bool && (this.page = 1)
      this.getList()
    },
    handleSearch (e) {
      e.preventDefault()
      this.searchForm.validateFields((error, values) => {
        if (!error) {
          this.queryParam = { ...this.queryParam, ...values }
          this.handleRefresh(true)
        }
      })
    },
    handleReset () {
      this.searchForm.resetFields()
    },
    onChangePage (current) {
      this.page = current
      this.handleRefresh()
    },
    getServiceStatusText (item) {
      return (item.refund_info && item.refund_info.service_state_text) || item.service_state_text || item.state_text || '--'
    },
    getServiceStatusColor (item) {
      const status = item.service_state || item.service_state_text || ''
      if (item.order_status === OrderStatusEnum.COMPLETED.value) return 'green'
      if (item.order_status === OrderStatusEnum.CANCELLED.value) return 'red'
      if (item.order_status === OrderStatusEnum.APPLY_CANCEL.value) return 'orange'
      if (item.pay_status === PayStatusEnum.PENDING.value) return ''
      return status === '已退款' ? 'red' : 'blue'
    },
    getServiceContactField (item, field) {
      if (!item || typeof item !== 'object') {
        return ''
      }
      if (item[field]) {
        return item[field]
      }
      if (item.service_contact && item.service_contact[field]) {
        return item.service_contact[field]
      }
      let sourceData = item.order_source_data || {}
      if (typeof sourceData === 'string') {
        try {
          sourceData = JSON.parse(sourceData)
        } catch (e) {
          sourceData = {}
        }
      }
      const serviceContact = sourceData.service_contact || {}
      return serviceContact[field] || ''
    },
    firstGoods (item) {
      return (item.goods && item.goods[0]) || {}
    },
    totalGoodsNum (item) {
      return (item.goods || []).reduce((sum, goods) => sum + (Number(goods.total_num) || 0), 0)
    }
  }
}
</script>

<style lang="less" scoped>
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
}
</style>
