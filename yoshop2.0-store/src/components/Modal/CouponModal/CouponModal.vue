<template>
  <a-modal
    class="noborder"
    :title="title"
    :width="920"
    :visible="visible"
    :isLoading="isLoading"
    :maskClosable="false"
    @ok="handleSubmit"
    @cancel="handleCancel"
  >
    <!-- 搜索板块 -->
    <div class="table-operator">
      <a-row>
        <a-col class="flex flex-x-end">
          <a-input-search
            style="max-width: 300px; min-width: 150px;float: right;"
            v-model="queryParam.search"
            placeholder="请输入优惠券名称"
            @search="handleSearch"
          />
        </a-col>
      </a-row>
    </div>
    <s-table
      ref="table"
      :scroll="{ y: '420px', scrollToFirstRowOnChange: true }"
      :rowKey="fieldName"
      :loading="isLoading"
      :columns="columns"
      :data="loadData"
      :rowSelection="rowSelection"
      :pageSize="15"
    >
      <!-- 优惠券名称 -->
      <template slot="name" slot-scope="text">
        <p class="oneline-hide">{{ text }}</p>
      </template>
      <!-- 优惠券类型 -->
      <template slot="coupon_type" slot-scope="text">
        <a-tag>{{ CouponTypeEnum[text].name }}</a-tag>
      </template>
      <!-- 最低消费金额 -->
      <template slot="min_price" slot-scope="text">
        <p class="c-p">{{ text }}</p>
      </template>
      <!-- 优惠方式 -->
      <template slot="discount" slot-scope="item">
        <template v-if="item.coupon_type == 10">
          <span>立减</span>
          <span class="c-p mlr-2">{{ item.reduce_price }}</span>
          <span>元</span>
        </template>
        <template v-if="item.coupon_type == 20">
          <span>打</span>
          <span class="c-p mlr-2">{{ item.discount }}</span>
          <span>折</span>
        </template>
      </template>
      <!-- 有效期 -->
      <template slot="duetime" slot-scope="item">
        <template v-if="item.expire_type == 10">
          <span>领取</span>
          <span class="c-p mlr-2">{{ item.expire_day }}</span>
          <span>天内有效</span>
        </template>
        <template v-if="item.expire_type == 20">
          <span>{{ item.start_time }} ~ {{ item.end_time }}</span>
        </template>
      </template>
      <!-- 状态 -->
      <template slot="status" slot-scope="text">
        <a-tag :color="text ? 'green' : ''">{{ text ? '显示' : '隐藏' }}</a-tag>
      </template>
    </s-table>
  </a-modal>
</template>

<script>
import PropTypes from 'ant-design-vue/es/_util/vue-types'
import cloneDeep from 'lodash.clonedeep'
import * as Api from '@/api/market/coupon'
import { STable } from '@/components/Table'
import { ApplyRangeEnum, CouponTypeEnum, ExpireTypeEnum } from '@/common/enum/coupon'

// table表头
const columns = [
  {
    title: '优惠券ID',
    dataIndex: 'coupon_id',
    width: '12%',
  },
  {
    title: '优惠券名称',
    dataIndex: 'name',
    scopedSlots: { customRender: 'name' },
    width: '26%',
  },
  {
    title: '优惠券类型',
    dataIndex: 'coupon_type',
    scopedSlots: { customRender: 'coupon_type' }
  },
  {
    title: '最低消费金额 (元)',
    dataIndex: 'min_price',
    scopedSlots: { customRender: 'min_price' }
  },
  {
    title: '优惠方式',
    scopedSlots: { customRender: 'discount' }
  },
  {
    title: '状态',
    dataIndex: 'status',
    scopedSlots: { customRender: 'status' }
  }
]

export default {
  name: 'CouponModal',
  props: {
    // 多选模式, 如果false为单选
    multiple: PropTypes.bool.def(true),
    // // 最大选择的数量限制, multiple模式下有效
    // maxNum: PropTypes.integer.def(100),
    // 默认选中的列表记录
    defaultList: PropTypes.array.def([])
  },
  components: {
    STable
  },
  data () {
    return {
      // 对话框标题
      title: '选择优惠券',
      // modal(对话框)是否可见
      visible: false,
      // loading状态
      isLoading: false,
      // 查询参数
      queryParam: {},
      // 枚举类
      ApplyRangeEnum,
      CouponTypeEnum,
      ExpireTypeEnum,
      // table表头
      columns,
      // 加载数据方法 必须为 Promise 对象
      loadData: param => {
        return Api.list({ ...param, ...this.queryParam })
          .then(response => {
            return response.data.list
          })
      },
      // 主键ID字段名
      fieldName: 'coupon_id',
      // 已选择的元素
      selectedRowKeys: [],
      // 已选择的列表记录
      selectedItems: []
    }
  },
  computed: {
    rowSelection () {
      return {
        selectedRowKeys: this.selectedRowKeys,
        onChange: this.onSelectChange,
        type: !this.multiple ? 'radio' : 'checkbox'
      }
    }
  },
  created () {

  },
  methods: {

    // 显示对话框
    handle () {
      // 显示窗口
      this.visible = true
      this.$nextTick(() => {
        // 刷新列表数据
        // this.handleRefresh(true)
        // 设置默认数据
        this.setDefaultValue()
      })
    },

    // 设置默认数据
    setDefaultValue () {
      const { fieldName, defaultList } = this
      if (defaultList.length) {
        this.selectedItems = cloneDeep(defaultList)
        this.selectedRowKeys = defaultList.map(item => item[fieldName])
      }
    },

    // 选中项发生变化时的回调
    onSelectChange (selectedRowKeys, newSelectedItems) {
      const { selectedItems } = this
      this.selectedRowKeys = selectedRowKeys
      this.selectedItems = this.createSelectedItems(selectedRowKeys, selectedItems, newSelectedItems)
    },

    /**
     * 生成已选中的元素列表
     * @param array selectedRowKeys 当前已选中的ID集
     * @param array oldSelectedItems 已选择的列表记录 (change前)
     * @param array newSelectedItems 已选择的列表记录 (change后)
     */
    createSelectedItems (selectedRowKeys, oldSelectedItems, newSelectedItems) {
      const { fieldName } = this
      const selectedItems = []
      oldSelectedItems.forEach(item => {
        if (selectedRowKeys.includes(item[fieldName])) {
          selectedItems.push(item)
        }
      })
      const oldSelectedKeys = oldSelectedItems.map(item => item[fieldName])
      newSelectedItems.forEach(item => {
        if (!oldSelectedKeys.includes(item[fieldName]) && selectedRowKeys.includes(item[fieldName])) {
          selectedItems.push(item)
        }
      })
      return selectedItems
    },

    /**
     * 刷新列表
     * @param Boolean bool 强制刷新到第一页
     */
    handleRefresh (bool = false) {
      this.$refs.table.refresh(true)
    },

    // 确认搜索
    handleSearch () {
      this.handleRefresh(true)
    },

    // 关闭对话框事件
    handleCancel () {
      this.visible = false
      this.queryParam = {}
      this.selectedRowKeys = []
      this.selectedItems = []
    },

    // 确认按钮
    handleSubmit (e) {
      e.preventDefault()
      // 通知父端组件提交完成了
      this.$emit('handleSubmit', {
        selectedItems: this.selectedItems,
        selectedRowKeys: this.selectedRowKeys
      })
      // 关闭对话框
      this.handleCancel()
    }

  }
}
</script>

<style lang="less" scoped>
.ant-modal-root {
  background: #ccc;
  /deep/.ant-modal-body {
    padding-bottom: 8px;
  }
  /deep/.ant-modal-footer {
    padding-top: 0;
  }
}
// 搜索表单
.search-form {
  /deep/.ant-form-item-control-wrapper {
    min-width: 180px;
  }
}
</style>
