<template>
  <div>
    <a-button @click="handleSelectCoupon">选择优惠券</a-button>
    <a-table
      v-show="selectedItems.length"
      class="table-couponList"
      rowKey="coupon_id"
      :columns="columns"
      :dataSource="selectedItems"
      :pagination="false"
    >
      <!-- 优惠券名称 -->
      <template slot="name" slot-scope="text">
        <p class="oneline-hide">{{ text }}</p>
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
      <!-- 操作项 -->
      <span slot="action" slot-scope="text, item, index">
        <a v-action:delete @click="handleDeleteItem(index)">删除</a>
      </span>
    </a-table>
    <CouponModal
      ref="CouponModal"
      :multiple="multiple"
      :defaultList="selectedItems"
      @handleSubmit="handleSelectCouponSubmit"
    />
  </div>
</template>

<script>
import PropTypes from 'ant-design-vue/es/_util/vue-types'
import cloneDeep from 'lodash.clonedeep'
import { CouponModal } from '@/components/Modal'
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
    width: '26%'
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
    title: '操作',
    width: '180px',
    dataIndex: 'action',
    scopedSlots: { customRender: 'action' }
  }
]

// 优惠券选择器组件
export default {
  name: 'SelectCoupon',
  components: {
    CouponModal
  },
  model: {
    prop: 'value',
    event: 'change'
  },
  props: {
    // 多选模式, 如果false为单选
    multiple: PropTypes.bool.def(true),
    // 默认选中的优惠券
    defaultList: PropTypes.array.def([])
  },
  data () {
    return {
      // 枚举类
      ApplyRangeEnum,
      CouponTypeEnum,
      ExpireTypeEnum,
      // table表头
      columns,
      // 已选择的优惠券列表
      selectedItems: [],
      // 已选择的优惠券ID集
      selectedCouponIds: []
    }
  },
  watch: {
    // 设置默认数据
    defaultList: {
      // 首次加载的时候执行函数
      immediate: true,
      handler (val) {
        const { selectedItems } = this
        if (val.length && !selectedItems.length) {
          this.onUpdate(cloneDeep(val))
        }
      }
    }
  },
  created () {
  },
  methods: {

    // 更新数据
    onUpdate (selectedItems) {
      if (this.multiple || !selectedItems.length) {
        // 多选模式
        this.selectedItems = selectedItems
        this.selectedCouponIds = selectedItems.map(item => item.coupon_id)
      } else {
        // 单选模式
        const single = selectedItems[selectedItems.length - 1]
        this.selectedItems = [single]
        this.selectedCouponIds = [single.coupon_id]
      }
      this.onChange()
    },

    // 打开优惠券选择器
    handleSelectCoupon () {
      this.$refs.CouponModal.handle()
    },

    // 优惠券库modal确认回调
    handleSelectCouponSubmit (result) {
      const { selectedItems } = result
      this.onUpdate(cloneDeep(selectedItems))
    },

    // 删除优惠券
    handleDeleteItem (index) {
      const { selectedItems } = this
      selectedItems.splice(index, 1)
      this.onUpdate(selectedItems)
    },

    // 触发change事件
    onChange () {
      const { multiple, selectedCouponIds } = this
      const sCouponIds = multiple ? selectedCouponIds : (selectedCouponIds.length ? selectedCouponIds[0] : undefined)
      return this.$emit('change', sCouponIds)
    }

  }
}
</script>

<style lang="less" scoped>
// 优惠券信息
.table-couponList {
  margin-top: 10px;
  min-width: 620px;
}
</style>
