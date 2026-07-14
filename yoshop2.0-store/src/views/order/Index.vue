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
            <a-form-item label="服务单字段">
              <a-checkbox-group :options="serviceSearchFieldOptions" v-decorator="['serviceSearchFields', { initialValue: [] }]" />
            </a-form-item>
            <a-form-item label="游戏平台">
              <a-select style="width: 120px" v-decorator="['gamePlatform', { initialValue: '' }]">
                <a-select-option value="">全部</a-select-option>
                <a-select-option v-for="(item, index) in gamePlatformOptions" :key="index" :value="item.value">{{ item.label }}</a-select-option>
              </a-select>
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
            <a-form-item v-if="dataType === 'all'" label="支付渠道">
              <a-select style="width: 120px" v-decorator="['paymentChannel', { initialValue: '' }]">
                <a-select-option value="">全部</a-select-option>
                <a-select-option value="ios_apple">iOS订单</a-select-option>
                <a-select-option value="non_ios">非iOS订单</a-select-option>
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
            <div class="ant-table-body order-table-scroll">
              <table class="order-table">
                <thead class="ant-table-thead">
                  <tr>
                    <th v-for="(item, index) in columns" :key="index" :class="{ 'operation-column': index === columns.length - 1 }">
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
                        <p>游戏平台：{{ getGamePlatformText(getServiceContactField(item, 'game_platform')) || '--' }}</p>
                        <p>游戏ID：{{ getServiceContactField(item, 'game_account_id') || '--' }}</p>
                        <p>联系方式：{{ getServiceContactField(item, 'contact_mobile') || '--' }}</p>
                        <p class="c-muted-1">成年人下单：{{ getAdultConfirmText(getServiceContactField(item, 'adult_confirm')) }}</p>
                      </td>
                      <td>
                        <a-tag :color="item.pay_status == PayStatusEnum.SUCCESS.value ? 'green' : ''">{{ getEnumName(PayStatusEnum, item.pay_status) }}</a-tag>
                      </td>
                      <td>
                        <a-tag :color="getServiceStatusColor(item)">{{ getServiceStatusText(item) }}</a-tag>
                        <p v-if="item.backend_action_flags && item.backend_action_flags.ios_refund_risk_status >= 10" class="mt-5">
                          <a-tag :color="item.backend_action_flags.ios_refund_risk_status >= 20 ? 'green' : 'red'">
                            {{ item.backend_action_flags.refund_display_state_text || item.backend_action_flags.ios_refund_risk_text || '服务已冻结' }}
                          </a-tag>
                        </p>
                      </td>
                      <td>
                        <p>￥{{ item.pay_price }}</p>
                      </td>
                      <td>
                        <p>买家备注：{{ item.buyer_remark || '--' }}</p>
                        <p class="c-muted-1 mt-5">商家备注：{{ item.merchant_remark || '--' }}</p>
                      </td>
                      <td class="operation-column">
                        <div class="actions">
                          <router-link v-if="$auth('/order/detail')" class="detail-button" :to="{ path: '/order/detail', query: { orderId: item.order_id } }" target="_blank">详情</router-link>
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
import { getEnumName } from '@/utils/enum'
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
  { name: '会员ID', value: 30 }
]

const serviceSearchFieldOptions = [
  { label: '游戏ID', value: 'game_account_id' },
  { label: '联系方式', value: 'contact_mobile' },
  { label: '备注', value: 'buyer_remark' }
]

const gamePlatformOptions = [
  { label: '端游', value: 'pc' },
  { label: '手游', value: 'mobile' }
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
      serviceSearchFieldOptions,
      gamePlatformOptions,
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
    getEnumName,
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
      this.queryParam = {}
      this.page = 1
      this.handleRefresh(true)
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
    getServiceContactField (item, field) {
      if (!item || typeof item !== 'object') {
        return ''
      }
      if (Object.prototype.hasOwnProperty.call(item, field) && item[field] !== null && typeof item[field] !== 'undefined') {
        return item[field]
      }
      if (item.service_contact && Object.prototype.hasOwnProperty.call(item.service_contact, field)) {
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
      return Object.prototype.hasOwnProperty.call(serviceContact, field) ? serviceContact[field] : ''
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
  .order-table-scroll {
    width: 100%;
    overflow-x: auto;
  }
  .order-table {
    width: 100%;
    min-width: 1280px;
    table-layout: fixed;
  }
  .order-table .operation-column {
    width: 96px;
    min-width: 96px;
    text-align: center;
  }
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

.actions {
  display: flex;
  justify-content: center;
}

.detail-button {
  display: inline-flex;
  min-width: 64px;
  height: 32px;
  padding: 0 15px;
  align-items: center;
  justify-content: center;
  color: #1890ff;
  line-height: 30px;
  white-space: nowrap;
  border: 1px solid #1890ff;
  border-radius: 4px;
  transition: all 0.2s;

  &:hover,
  &:focus {
    color: #40a9ff;
    border-color: #40a9ff;
  }
}
</style>
