<template>
  <div>
    <a-button @click="handleSelectUser">选择会员</a-button>
    <a-table
      v-show="selectedItems.length"
      class="table-userList"
      rowKey="user_id"
      :columns="columns"
      :dataSource="selectedItems"
      :pagination="false"
    >
      <!-- 会员头像 -->
      <span slot="avatar_url" slot-scope="text">
        <div class="avatar">
          <img v-if="text" width="45" height="45" :src="text" alt="用户头像" />
          <img v-else width="45" height="45" src="~@/assets/img/default-avatar.png" alt="用户头像" />
        </div>
      </span>
      <span slot="main_info" slot-scope="item">
        <p>{{ item.nick_name }}</p>
        <p class="c-p">{{ item.mobile }}</p>
      </span>
      <!-- 会员等级 -->
      <span slot="grade" slot-scope="text">
        <a-tag v-if="text">{{ text.name }}</a-tag>
        <span v-else>--</span>
      </span>
      <!-- 操作项 -->
      <span slot="action" slot-scope="text, item, index">
        <a v-action:delete @click="handleDeleteItem(index)">删除</a>
      </span>
    </a-table>
    <UserModal
      ref="UserModal"
      :multiple="multiple"
      :maxNum="maxNum"
      :defaultList="selectedItems"
      @handleSubmit="handleSelectUserSubmit"
    />
  </div>
</template>

<script>
import PropTypes from 'ant-design-vue/es/_util/vue-types'
import cloneDeep from 'lodash.clonedeep'
import { filterModules } from '@/core/app'
import { UserModal } from '@/components/Modal'

// table表头
const columns = filterModules([
  {
    title: '会员ID',
    dataIndex: 'user_id'
  },
  {
    title: '会员头像',
    dataIndex: 'avatar_url',
    scopedSlots: { customRender: 'avatar_url' }
  },
  {
    title: '昵称/手机号',
    scopedSlots: { customRender: 'main_info' }
  },
  {
    title: '会员等级',
    moduleKey: 'user-grade',
    dataIndex: 'grade',
    scopedSlots: { customRender: 'grade' }
  },
  {
    title: '操作',
    width: '180px',
    dataIndex: 'action',
    scopedSlots: { customRender: 'action' }
  }
])

// 会员选择器组件
export default {
  name: 'SelectUser',
  components: {
    UserModal
  },
  model: {
    prop: 'value',
    event: 'change'
  },
  props: {
    // 多选模式, 如果false为单选
    multiple: PropTypes.bool.def(true),
    // 最大选择的数量限制, multiple模式下有效
    maxNum: PropTypes.integer.def(100),
    // 默认选中的会员
    defaultList: PropTypes.array.def([])
  },
  data () {
    return {
      // table表头
      columns,
      // 已选择的会员列表
      selectedItems: [],
      // 已选择的会员ID集
      selectedUserIds: []
    }
  },
  watch: {
    // 设置默认数据
    defaultList: {
      // 首次加载的时候执行函数
      immediate: true,
      handler (val) {
        const { selectedItems } = this
        if (val.length && !selectedItems.length) {
          this.onUpdate(cloneDeep(val))
        }
      }
    }
  },
  created () {
  },
  methods: {

    // 更新数据
    onUpdate (selectedItems) {
      if (this.multiple || !selectedItems.length) {
        // 多选模式
        this.selectedItems = selectedItems
        this.selectedUserIds = selectedItems.map(item => item.user_id)
      } else {
        // 单选模式
        const single = selectedItems[selectedItems.length - 1]
        this.selectedItems = [single]
        this.selectedUserIds = [single.user_id]
      }
      this.onChange()
    },

    // 打开会员选择器
    handleSelectUser () {
      this.$refs.UserModal.handle()
    },

    // 会员库modal确认回调
    handleSelectUserSubmit (result) {
      const { selectedItems } = result
      this.onUpdate(cloneDeep(selectedItems))
    },

    // 删除会员
    handleDeleteItem (index) {
      const { selectedItems } = this
      selectedItems.splice(index, 1)
      this.onUpdate(selectedItems)
    },

    // 触发change事件
    onChange () {
      const { multiple, selectedUserIds } = this
      const sUserIds = multiple ? selectedUserIds : (selectedUserIds.length ? selectedUserIds[0] : undefined)
      return this.$emit('change', sUserIds)
    }

  }
}
</script>

<style lang="less" scoped>
.table-userList {
  margin-top: 10px;
  min-width: 620px;
}
</style>
