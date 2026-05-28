<template>
  <div class="goods-type">
    <div
      class="type-item"
      v-for="(item, index) in list"
      :key="index"
      :class="{ checked: value == item.value }"
      v-show="!onlyShowChecked || value == item.value"
      @click="handleItem(item.value)"
    >
      <div class="type-title">{{ item.title }}</div>
      <div class="type-help">{{ item.help }}</div>
      <a-icon v-if="value == item.value" class="icon-checked" type="check" />
    </div>
  </div>
</template>

<script>
import PropTypes from 'ant-design-vue/es/_util/vue-types'
import { GoodsTypeEnum } from '@/common/enum/goods'

// 数字输入框组
export default {
  name: 'GoodsType',
  model: {
    prop: 'value',
    event: 'change'
  },
  props: {
    // 当前选中项的value
    value: PropTypes.any,
    // 仅显示选中的项
    onlyShowChecked: PropTypes.bool.def(false)
  },
  data () {
    return {
      defaultValue: GoodsTypeEnum.PHYSICAL.value,
      list: [
        {
          title: GoodsTypeEnum.PHYSICAL.name,
          help: '物流发货',
          value: GoodsTypeEnum.PHYSICAL.value
        }
      ]
    }
  },
  methods: {

    // 切换选中事件
    handleItem (value) {
      this.$emit('change', value)
    }

  }
}
</script>

<style lang="less" scoped>
// 商品类型
.goods-type {
  display: flex;
  margin-bottom: 15px;

  .type-item {
    position: relative;
    border-radius: 2px;
    padding: 8px 26px;
    margin-right: 20px;
    line-height: 20px;
    text-align: center;
    background-color: #ffffff;
    color: #262626;
    border: 1px solid #d9d9d9;
    cursor: pointer;
    user-select: none;
    transition: all .2s ease .1s;

    &.checked {
      border-color: @primary-color;

      .type-title {
        color: @primary-color;
        transition: all .2s ease .1s;
      }

      &:after {
        position: absolute;
        right: 0;
        bottom: 0;
        content: '';
        width: 0;
        height: 0;
        border: 20px solid transparent;
        border-bottom-color: @primary-color;
        transform: translate(20px, 20px) rotate(135deg);
      }
    }

    .type-title {
      color: #262626;
      font-size: 14px;
    }

    .type-help {
      color: #8a9099;
      font-size: 12px;
    }

    .icon-checked {
      position: absolute;
      right: 2px;
      bottom: 2px;
      z-index: 1;
      font-size: 12px;
      color: #fff;
    }
  }
}
</style>
