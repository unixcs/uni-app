<template>
  <div class="select-link">
    <a-tooltip>
      <template slot="title">设置跳转的链接</template>
      <a class="choice" :style="{ color: color }" @click="handleSelectLink()">{{ text }}</a>
    </a-tooltip>
    <LinkModal ref="LinkModal" @handleSubmit="handleSubmit" />
  </div>
</template>

<script>
import PropTypes from 'ant-design-vue/es/_util/vue-types'
import { LinkModal } from '@/components/Modal'

// 链接选择器组件（文字形式）
export default {
  name: 'SLink2',
  components: {
    LinkModal
  },
  model: {
    prop: 'value',
    event: 'change'
  },
  props: {
    // 选中的链接
    value: PropTypes.object.def({}),
    // 链接文字
    text: PropTypes.string.def('链接'),
    // 链接颜色
    color: '#fff',
  },
  data () {
    return {
      // 已选择的链接
      sLink: null
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

    // 更新已选择的数据 sLink
    onUpdate (sLink) {
      this.sLink = sLink
      this.onChange()
    },

    // 打开链接选择器
    handleSelectLink () {
      const { sLink } = this
      this.$refs.LinkModal.handle(sLink)
    },

    // 链接选择器确认回调
    handleSubmit (result) {
      this.onUpdate(result)
    },

    // 触发change事件
    onChange () {
      const { sLink } = this
      return this.$emit('change', sLink)
    }

  }
}
</script>

<style lang="less" scoped>
.select-link {
  display: inline;
  font-size: 12px;
  .choice {
    color: #fff;
  }
}
</style>
