<template>
  <a-upload
    :beforeUpload="beforeUpload"
    :remove="handleRemove"
    :accept="accept"
    :multiple="false"
    :fileList="fileList"
  >
    <a-button>
      <a-icon type="upload" />选择文件
    </a-button>
  </a-upload>
</template>

<script>
import PropTypes from 'ant-design-vue/es/_util/vue-types'

// input选择文件
export default {
  name: 'InputFile',
  model: {
    prop: 'value',
    event: 'change'
  },
  props: {
    // v-model 当前输入框值
    value: PropTypes.string.def(''),
    // 接受上传的文件类型
    accept: PropTypes.string.def('')
  },
  data () {
    return {
      // 文件列表
      fileList: [],
    }
  },
  watch: {
    value: {
      // 首次加载的时候执行函数
      immediate: true,
      handler (name) {
        if (name && this.fileList.length === 0) {
          this.fileList = [{ uid: 'default', name }]
        }
      }
    },
    fileList (val) {
      const file = val.length > 0 ? val[0] : null
      const fileName = file ? file.name : null
      if (file.uid != 'default') {
        this.$emit('change', fileName, file)
      }
    }
  },
  methods: {

    // 上传文件之前的钩子
    beforeUpload (file) {
      this.fileList = [file]
      return false
    },

    // 文件移除
    handleRemove (file) {
      const { fileList } = this
      const index = fileList.indexOf(file)
      if (index > -1) {
        fileList.splice(index, 1)
      }
    },

  }
}
</script>

<style lang="less" scoped>
</style>
