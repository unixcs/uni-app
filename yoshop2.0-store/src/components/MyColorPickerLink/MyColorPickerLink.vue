<template>
  <div class="color-picker-link">
    <MyColorPicker v-model="sValue[0]" :width="width" :horizontal="horizontal" />
    <div class="link">
      <a-icon class="icon" :component="Icons.link" />
    </div>
    <MyColorPicker v-model="sValue[1]" :width="width" :horizontal="horizontal" />
  </div>
</template>

<script>
import PropTypes from 'ant-design-vue/es/_util/vue-types'
import * as Icons from '@/core/icons'
import MyColorPicker from '@/components/MyColorPicker'

// 颜色选择器
export default {
  name: 'MyColorPickerLink',
  model: {
    prop: 'value',
    event: 'change'
  },
  props: {
    // v-model 当前颜色值 (数组)
    value: PropTypes.array.def([]),
    // 选择器宽度
    width: PropTypes.integer.def(50),
    // 显示位置 right left
    horizontal: PropTypes.string.def('right'),
  },
  components: {
    MyColorPicker
  },
  computed: {
    positionCom () {
      // const val = horizontal === 'left' ? { left: 0 } : { right: 0 }
      const val = { [this.horizontal]: 0 }
      return { top: '40px', ...val }
    }
  },
  data () {
    return {
      Icons,
      // 当前值
      sValue: ['#fff', '#000']
    }
  },
  watch: {
    value: {
      // 首次加载的时候执行函数
      immediate: true,
      handler (val) {
        this.sValue = val
      }
    }
  },
  methods: {

    // 触发change事件
    onChange (value) {
      this.$emit('change', value)
    }

  }
}
</script>

<style lang="less" scoped>
.color-picker-link {
  display: flex;
  position: relative;
  .link {
    line-height: 32px;
    font-size: 20px;
    color: #707070;
    margin: 0 -3px;
    z-index: 2;
  }
}
</style>
