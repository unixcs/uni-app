<template>
  <div class="select-link">
    <a class="choice" @click="handleSelectLink()">
      <span class="mr-5">绘制热区</span>
      <a-icon type="edit" />
    </a>
    <HotZoneModal ref="HotZoneModal" @handleSubmit="onUpdate" />
  </div>
</template>

<script>
import PropTypes from 'ant-design-vue/es/_util/vue-types'
import HotZoneModal from './HotZoneModal'

// 绘制热区组件
export default {
  name: 'SelectHotZone',
  components: {
    HotZoneModal
  },
  model: {
    prop: 'value',
    event: 'change'
  },
  props: {
    // 热区数据
    value: PropTypes.array.def([]),
    // 背景图片
    image: PropTypes.string.def('')
  },
  data () {
    return {
      // 已选择的链接
      maps: []
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

    // 更新已选择的数据 
    onUpdate (maps) {
      this.maps = maps
      this.$emit('change', maps)
    },

    // 打开链接选择器
    handleSelectLink () {
      const { maps, image } = this
      this.$refs.HotZoneModal.handle(maps, image)
    },

  }
}
</script>

<style lang="less" scoped>
.select-link {
  flex: 1;
  min-width: 150px;
}
.choice,
.link-title {
  display: block;
  line-height: 32px;
  white-space: nowrap;
  font-size: 12px;
}
</style>
