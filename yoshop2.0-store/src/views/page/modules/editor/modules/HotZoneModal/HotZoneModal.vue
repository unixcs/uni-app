<template>
  <a-modal
    class="noborder"
    :title="title"
    :width="804"
    :visible="visible"
    :isLoading="isLoading"
    :maskClosable="false"
    :destroyOnClose="true"
    @cancel="handleCancel"
  >
    <div class="scroll-view">
      <div class="zone-body" :style="{ height: `${imageH}px` }">
        <div class="bg-image">
          <img ref="image" class="image" :src="imageSrc" @load="imageLoad" alt />
        </div>
        <template v-if="imageW > 0 && imageH > 0">
          <vue-draggable-resizable
            v-for="(item, index) in maps"
            :key="item.key"
            class-name="my-vdr"
            class-name-handle="my-handle"
            :minWidth="60"
            :minHeight="20"
            :x="item.left"
            :y="item.top"
            :w="item.width"
            :h="item.height"
            :parent="true"
            @dragstop="(left, top) => { item.left = left; item.top = top }"
            @resizestop="(left, top, width, height) => { item.left = left; item.top = top; item.width = width; item.height = height }"
          >
            <div class="title">热区{{ index + 1 }}</div>
            <div class="actions">
              <a-popconfirm title="您确定要删除该热区吗？" @confirm="handleDelZone(index)">
                <a href="javascript:;">删除</a>
              </a-popconfirm>
              <SLink2 v-model="item.link" />
            </div>
          </vue-draggable-resizable>
        </template>
      </div>
    </div>
    <template slot="footer">
      <a-button key="back" @click="handleAddZone">添加新热区</a-button>
      <a-button key="submit" type="primary" @click="handleSubmit">保存</a-button>
    </template>
  </a-modal>
</template>

<script>
import _ from 'lodash'
import VueDraggableResizable from 'vue-draggable-resizable'
import { SLink2 } from '../'

// 绘制热区组件
export default {
  name: 'HotZoneModal',
  model: {
    prop: 'value',
    event: 'change'
  },
  components: {
    VueDraggableResizable,
    SLink2
  },
  data () {
    return {
      // 对话框标题
      title: '绘制热区',
      // modal(对话框)是否可见
      visible: false,
      // loading状态
      isLoading: false,
      // 热区数据
      maps: [],
      // 背景图片地址
      imageSrc: '',
      // 背景图宽度
      imageW: 0,
      // 背景图高度
      imageH: 0
    }
  },

  methods: {

    // 显示对话框
    handle (maps, image) {
      this.visible = true
      this.maps = _.cloneDeep(maps)
      this.imageSrc = image
    },

    // 图片加载事件
    imageLoad (e) {
      this.imageW = this.$refs.image.offsetWidth
      this.imageH = this.$refs.image.offsetHeight
    },

    // 新增热区
    handleAddZone () {
      this.maps.push({ width: 100, height: 100, left: 0, top: 0, link: null, key: new Date().getTime() })
    },

    // 删除热区
    handleDelZone (index) {
      this.maps.splice(index, 1)
    },

    // 确认按钮
    handleSubmit (e) {
      this.$emit('handleSubmit', this.maps)
      this.handleCancel()
    },

    // 关闭对话框事件
    handleCancel () {
      this.visible = false
      this.imageSrc = ''
      this.imageW = 0
      this.imageH = 0
      this.maps = []
    },

  }
}
</script>

<style lang="less" scoped>
@import '~ant-design-vue/es/style/themes/default.less';
.ant-modal-root {
  background: #ccc;
  /deep/.ant-modal-body {
    padding: 0 24px 20px 24px;
  }
  /deep/.ant-modal-footer {
    padding-top: 0;
  }
}

/deep/.ant-collapse-header {
  padding: 14px 16px;
  font-size: @font-size-base;
  font-weight: 700;
  color: #595961;
}

/deep/.ant-collapse-content-box {
  padding-top: 0 !important;
}

/deep/.ant-collapse > .ant-collapse-item {
  border-bottom: none;
  margin-bottom: 15px;
  &:last-child {
    margin-bottom: 0;
  }
}

.scroll-view {
  max-height: 500px;
  overflow-y: auto;
}

.zone-body {
  position: relative;
  overflow: hidden;
  width: 750px;
  background: #ccc;
  .bg-image {
    position: absolute;
    top: 0;
    bottom: 0;
    user-select: none;
    width: 100%;
    .image {
      display: block;
      width: 100%;
    }
  }
}

.my-vdr {
  border: 1px solid red;
  background: rgba(45, 140, 240, 0.6);
  border: 1px solid #479bf4;
  cursor: all-scroll;
  display: flex;
  justify-content: center;
  align-items: center;
  position: absolute;

  &.active {
    .title {
      display: none;
    }

    .actions {
      display: block;
    }
  }

  .title {
    display: block;
    color: #fff;
    font-size: 12px;
    overflow: hidden;
    white-space: nowrap;
    user-select: none;
  }

  .actions {
    display: none;
    color: #fff;
    font-size: 12px;
    user-select: none;
    a {
      margin-right: 6px;
      color: #fff;
    }

    a:last-child {
      margin-right: 0;
    }
  }
}

/deep/.my-handle {
  position: absolute;
  height: 10px;
  width: 10px;
  transition: all 300ms linear;
  background: #fff;
  border: 1px solid #479bf4;
  border-radius: 50%;
}

/deep/.my-handle-tl {
  top: -6px;
  left: -6px;
  cursor: nw-resize;
}

/deep/.my-handle-tm {
  top: -6px;
  left: 50%;
  margin-left: -7px;
  cursor: n-resize;
}

/deep/.my-handle-tr {
  top: -6px;
  right: -6px;
  cursor: ne-resize;
}

/deep/.my-handle-ml {
  top: 50%;
  margin-top: -7px;
  left: -6px;
  cursor: w-resize;
}

/deep/.my-handle-mr {
  top: 50%;
  margin-top: -7px;
  right: -6px;
  cursor: e-resize;
}

/deep/.my-handle-bl {
  bottom: -6px;
  left: -6px;
  cursor: sw-resize;
}

/deep/.my-handle-bm {
  bottom: -6px;
  left: 50%;
  margin-left: -7px;
  cursor: s-resize;
}

/deep/.my-handle-br {
  bottom: -6px;
  right: -6px;
  cursor: se-resize;
}
</style>
