<template>
  <a-card :bordered="false">
    <a-spin :spinning="isLoading">
      <div class="card-title">发货记录（物流配送）</div>
      <div class="table-operator">
        <!-- 搜索板块 -->
        <a-row class="row-item-search">
          <a-form class="search-form" :form="searchForm" layout="inline" @submit="handleSearch">
            <a-form-item label>
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
            <a-form-item label="发货方式">
              <a-select v-decorator="['deliveryMethod', { initialValue: -1 }]">
                <a-select-option :value="-1">全部</a-select-option>
                <a-select-option
                  v-for="(item, index) in DeliveryMethodEnum.data"
                  :key="index"
                  :value="item.value"
                >{{ item.name }}</a-select-option>
              </a-select>
            </a-form-item>
            <a-form-item label="发货时间">
              <a-range-picker format="YYYY-MM-DD" v-decorator="['betweenTime']" />
            </a-form-item>
            <a-form-item class="search-btn">
              <a-button class="mr-15" type="primary" icon="search" html-type="submit">搜索</a-button>
              <a-button @click="handleReset">重置</a-button>
            </a-form-item>
          </a-form>
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
                  <tr class="order-empty" :key="`order_${item.delivery_id}_1`">
                    <td colspan="8"></td>
                  </tr>
                  <tr :key="`order_${item.delivery_id}_2`">
                    <td colspan="8">
                      <span class="mr-20">发货时间：{{ item.create_time }}</span>
                      <span class="mr-20">订单号：{{ item.orderData.order_no }}</span>
                      <platform-icon :name="item.orderData.platform" :showTips="true" />
                    </td>
                  </tr>
                  <tr
                    v-for="(goodsItm, goodsIdx) in item.goods"
                    :key="`orderGoods_${item.delivery_id}_${goodsIdx}`"
                  >
                    <td>
                      <GoodsItem
                        :data="{
                          image: goodsItm.goods.goods_image,
                          imageAlt: '商品图片',
                          title: goodsItm.goods.goods_name,
                          goodsProps: goodsItm.goods.goods_props
                        }"
                      />
                    </td>
                    <td>
                      <p>×{{ goodsItm.goods.delivery_num }}</p>
                    </td>
                    <template v-if="goodsIdx === 0">
                      <td :rowspan="item.goods.length">
                        <UserItem :user="item.orderData.user" />
                      </td>
                      <td :rowspan="item.goods.length">
                        <a-tag>{{ DeliveryMethodEnum[item.delivery_method].name }}</a-tag>
                      </td>
                      <td :rowspan="item.goods.length">
                        <div v-if="item.express" class="f-12">
                          <p>{{ item.express.express_name }}</p>
                          <p>{{ item.express_no }}</p>
                        </div>
                        <span v-else>--</span>
                      </td>
                      <td :rowspan="item.goods.length">
                        <div v-if="item.orderData.address" class="f-12">
                          <p>收货人：{{ item.orderData.address.name }}</p>
                          <p>联系电话：{{ item.orderData.address.phone }}</p>
                          <p style="max-width: 260px" class="oneline-hide">
                            收货地址： {{ item.orderData.address.region.province }}
                            {{ item.orderData.address.region.city }}
                            {{ item.orderData.address.region.region }}
                            {{ item.orderData.address.detail }}
                          </p>
                        </div>
                        <span v-else>--</span>
                      </td>
                      <td :rowspan="item.goods.length">
                        <div class="actions">
                          <router-link
                            v-if="$auth('/order/detail')"
                            :to="{ path: '/order/detail', query: { orderId: item.order_id } }"
                            target="_blank"
                          >订单详情</router-link>
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
    </a-spin>
  </a-card>
</template>

<script>
import { Empty } from 'ant-design-vue'
import { inArray, assignment } from '@/utils/util'
import * as Api from '@/api/order/delivery'
import PlatformIcon from '@/components/PlatformIcon'
import { GoodsItem, UserItem } from '@/components/Table'
import { DeliveryMethodEnum } from '@/common/enum/order/delivery'

// 表格字段
const columns = [
  {
    title: '商品信息',
    align: 'center',
    dataIndex: 'goods',
    scopedSlots: { customRender: 'goods' }
  },
  {
    title: '发货数量',
    align: 'center',
    scopedSlots: { customRender: 'delivery_num' }
  },
  {
    title: '买家',
    dataIndex: 'user',
    scopedSlots: { customRender: 'user' }
  },
  {
    title: '物流方式',
    dataIndex: 'delivery_method',
    scopedSlots: { customRender: 'delivery_method' }
  },
  {
    title: '物流公司/单号',
    dataIndex: 'express',
    scopedSlots: { customRender: 'express' }
  },
  {
    title: '配送信息',
    dataIndex: 'address',
    scopedSlots: { customRender: 'address' }
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
  { name: '订单号', value: 10 }
]

export default {
  name: 'Index',
  components: {
    PlatformIcon,
    GoodsItem,
    UserItem
  },
  data () {
    return {
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
    assignment(this, {
      inArray,
      DeliveryMethodEnum,
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
      const { queryParam, page } = this
      this.isLoading = true
      return Api.list({ ...queryParam, page })
        .then(response => this.orderList = response.data.list)
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
