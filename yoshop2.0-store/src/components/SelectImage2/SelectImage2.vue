<template>
  <div class="image-custom">
    <a-tooltip>
      <template v-if="tips" slot="title">{{ tips }}</template>
      <div v-if="imgUrl" class="image-box" :style="{ width: `${width}px`, height: `${height}px` }">
        <img :src="imgUrl" alt />
        <div class="update-box-black"></div>
        <div class="uodate-repalce" @click="handleSelectImage">替换</div>
      </div>
      <div
        v-else
        class="selector"
        :style="{ width: `${width}px`, height: `${width}px` }"
        @click="handleSelectImage"
      >
        <a-icon class="icon-plus" :style="{ fontSize: `${width * 0.4}px` }" type="plus" />
      </div>
    </a-tooltip>

    <!-- 文件选择器 -->
    <FilesModal ref="FilesModal" :multiple="false" @handleSubmit="handleSelectImageSubmit" />
  </div>
</template>

<script>
import PropTypes from 'ant-design-vue/es/_util/vue-types'
import { FilesModal } from '@/components/Modal'

// 图片选择器组件 (单选)
export default {
  name: 'SelectImage2',
  components: {
    FilesModal
  },
  model: {
    prop: 'value',
    event: 'change'
  },
  props: {
    // 默认显示的图片
    value: PropTypes.string.def(''),
    // 气泡提示内容
    tips: PropTypes.string.def(''),
    // 元素的尺寸(宽)
    width: PropTypes.integer.def(80),
    // 元素的尺寸(高)
    height: PropTypes.integer.def(80)

  },
  data () {
    return {
      // 选择的图片
      imgUrl: ''
    }
  },
  watch: {
    value: {
      // 首次加载的时候执行函数
      immediate: true,
      handler (val) {
        this.imgUrl = val
      }
    }
  },
  created () {
  },
  methods: {

    // 打开文件选择器
    handleSelectImage () {
      this.$refs.FilesModal.show()
    },

    // 文件库modal确认回调
    handleSelectImageSubmit (result) {
      if (result.length > 0) {
        const file = result[0]
        this.onChange(file)
      }
    },

    // 触发change事件
    onChange (file) {
      // 记录imgUrl
      this.imgUrl = file.preview_url
      // v-model
      this.$emit('change', this.imgUrl)
      // 触发update事件
      this.$emit('update', file)
    }

  }
}
</script>

<style lang="less" scoped>
.image-custom {
  display: flex;
  align-items: center;

  .image-box {
    position: relative;
    width: 70px;
    height: 70px;
    cursor: pointer;
    display: flex;
    justify-content: center;
    align-items: center;
    border-radius: 4px;

    .update-box-black {
      background: #000;
      opacity: 0.5;
      display: none;
      position: absolute;
      z-index: 2;
      top: 0;
      right: 0;
      left: 0;
      bottom: 0;
      border-radius: 4px;
    }

    .uodate-repalce {
      width: 55px;
      height: 25px;
      line-height: 25px;
      font-size: 12px;
      text-align: center;
      position: absolute;
      top: 0;
      right: 0;
      left: 0;
      bottom: 0;
      margin: auto;
      display: none;
      z-index: 2;
      background: #fff;
      border-radius: 4px;
      font-weight: 600;
      color: #595961;
    }

    &:hover {
      .update-box-black,
      .uodate-repalce {
        display: block;
      }
    }

    img {
      width: 100%;
      height: 100%;
      object-fit: cover;
      border-radius: 4px;
    }
  }
}

// 选择器
.selector {
  width: 80px;
  height: 80px;
  float: left;
  border: 1px dashed #e2e2e2;
  text-align: center;
  color: #dad9d9;
  cursor: pointer;
  display: flex;
  justify-content: center;
  align-items: center;
  &:hover {
    border: 1px dashed #40a9ff;
    color: #40a9ff;
  }
  .icon-plus {
    font-size: 32px;
  }
}
</style>
