<template>
  <div class="color-picker" :style="{ width: `${width}px` }">
    <color-picker v-model="sValue" :position="positionCom" @change="onChange" />
    <!-- <div class="color-btn"></div>
    <div class="picker-box">
    </div>-->
  </div>
</template>

<script>
import Vue from 'vue'
import PropTypes from 'ant-design-vue/es/_util/vue-types'
import { ColorPicker, ColorPanel } from 'one-colorpicker'

// Vue.use(ColorPicker)
Vue.use(ColorPanel)

// 颜色选择器
export default {
  name: 'MyColorPicker',
  model: {
    prop: 'value',
    event: 'change'
  },
  props: {
    // v-model 当前颜色值 hex srgb
    value: PropTypes.string.def(''),
    // 选择器宽度
    width: PropTypes.integer.def(60),
    // 显示位置 right left
    horizontal: PropTypes.string.def('right'),
  },
  components: {
    ColorPicker,
    // ColorPanel,
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
      // 当前值
      sValue: ''
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
.color-picker {
  display: block;
  background-color: #ffffff;
  border: 1px solid #efefef;
  padding: 5px;
  width: 32px;
  height: 32px;
  position: relative;
}
/deep/.one-colorpicker {
  width: 100%;
  height: 100%;
  .color-block {
    width: 100%;
    height: 100%;
    border: 1px solid #000;
  }
  .one-colorpanel {
    z-index: 10;
  }
}
</style>
