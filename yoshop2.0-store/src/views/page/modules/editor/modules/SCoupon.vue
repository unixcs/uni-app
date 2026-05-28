<template>
  <div class="select-coupon">
    <div class="data-preview">
      <draggable
        :list="selectedItems"
        v-bind="{ animation: 120, filter: 'input', preventOnFilter: false }"
      >
        <div v-for="(item, index) in selectedItems" :key="index" class="data-item">
          <a-icon
            class="icon-close"
            theme="filled"
            type="close-circle"
            @click="handleDeleteItem(index)"
          />
          <div class="item-inner">
            <span class="mr-5">{{ item.name }}</span>
            <span class="mr-5">（ID: {{ item.coupon_id }}）</span>
          </div>
        </div>
      </draggable>
    </div>
    <div class="data-add">
      <a-button icon="plus" @click="handleSelectCoupon()">选择优惠券</a-button>
    </div>
    <CouponModal
      ref="CouponModal"
      :defaultList="selectedItems"
      @handleSubmit="handleSelectCouponSubmit"
    />
  </div>
</template>

<script>
import PropTypes from 'ant-design-vue/es/_util/vue-types'
import draggable from 'vuedraggable'
import { pick, cloneDeep } from 'lodash'
import { CouponModal } from '@/components/Modal'

// 主键字段
const primaryKey = 'coupon_id'

// 仅需要的字段
const itemColumns = [primaryKey, 'name', 'coupon_type', 'reduce_price', 'discount', 'min_price']

// 优惠券选择器组件(仅适用于页面设计)
export default {
  name: 'SelectCoupon',
  components: {
    CouponModal,
    draggable
  },
  model: {
    prop: 'value',
    event: 'change'
  },
  props: {
    // 选中的优惠券数据
    value: PropTypes.array.def([])
  },
  data () {
    return {
      // 已选择的优惠券列表
      selectedItems: []
    }
  },
  watch: {
    value: {
      // 首次加载的时候执行函数
      immediate: true,
      handler (val) {
        this.onUpdate(val)
      }
    }
  },
  created () {
  },
  methods: {

    // 更新已选择的数据 selectedItems
    onUpdate (items) {
      this.selectedItems = items
      this.onChange()
    },

    // 打开优惠券选择器
    handleSelectCoupon () {
      this.$refs.CouponModal.handle()
    },

    // 优惠券库modal确认回调
    handleSelectCouponSubmit (result) {
      const { selectedItems } = result
      this.onUpdate(this.filterItems(selectedItems))
    },

    // 过滤仅需要的数据
    filterItems (selectedItems) {
      return selectedItems.filter(item => item[primaryKey] != undefined).map(itm => pick(itm, itemColumns))
    },

    // 删除优惠券
    handleDeleteItem (index) {
      const { selectedItems } = this
      if (selectedItems.length <= 1) {
        this.$message.warning('请至少保留1个', 1)
        return
      }
      selectedItems.splice(index, 1)
      this.onUpdate(selectedItems)
    },

    // 触发change事件
    onChange () {
      const { selectedItems } = this
      return this.$emit('change', selectedItems)
    }

  }
}
</script>

<style lang="less" scoped>
@import '~ant-design-vue/es/style/themes/default.less';
// 优惠券信息
.data-preview {
  .data-item {
    margin: 10px 5px;
    background: #f7fafc;
    position: relative;
    border-radius: 3px;
    cursor: move;
    border: 1px solid #fff;

    &:hover {
      border: 1px solid #dadada;
      .icon-close {
        display: block;
      }
    }
  }

  // 删除图标
  .icon-close {
    display: none;
    position: absolute;
    top: -8px;
    right: -8px;
    cursor: pointer;
    font-size: 16px;
    color: #c5c5c5;
    &:hover {
      color: #7d7d7d;
    }
  }
  .item-inner {
    padding: 10px 15px;
    border-radius: 4px;
    background: #f7fafc;
  }
}

// 新增数据
.data-add {
  margin-top: 10px;

  button {
    width: 100%;
    color: @primary-color;
    height: 40px;
    border-radius: 5px;
    font-size: 12px;
    border-color: #cccccc21;

    &:hover {
      border-color: @primary-color;
    }
  }
}
</style>
