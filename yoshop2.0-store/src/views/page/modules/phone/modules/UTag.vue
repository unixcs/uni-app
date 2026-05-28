<template>
  <div class="u-tag" :class="[`u-size-${size}`, `u-mode-${mode}-error`]">
    <span>{{ text }}</span>
  </div>
</template>

<script>
import PropTypes from 'ant-design-vue/es/_util/vue-types'

// u-tag组件
export default {
  name: 'UTag',
  components: {
  },
  props: {
    // 标签文字
    text: PropTypes.string.def(''),
    // 标签尺寸  default mini
    size: PropTypes.string.def('default'),
    // 主题类型  primary / success / info / warning / error
    type: PropTypes.string.def('primary'),
    // 模式选择  dark(深色背景)、light(浅色背景)、plain(白色背景)
    mode: PropTypes.string.def('light'),
  },
  data () {
    return {}
  }
}
</script>

<style lang="less" scoped>
.u-tag {
  box-sizing: border-box;
  align-items: center;
  border-radius: 3px;
  display: inline-block;
  line-height: 1;
}
.u-size-mini {
  font-size: 10px;
  padding: 3px 6px;
}
.u-mode-plain-error {
  background-color: #ffffff;
  color: #fa3534;
  border: 1px solid #fa3534;
}
.u-mode-light-error {
  background-color: #fef0f0;
  color: #fa3534;
  border: 1px solid #fab6b6;
}
</style>
