<template>
  <a-modal
    class="noborder"
    :title="title"
    :width="920"
    :visible="visible"
    :isLoading="isLoading"
    :maskClosable="false"
    @ok="handleSubmit"
    @cancel="handleCancel"
  >
    <div class="table-operator">
      <!-- 搜索板块 -->
      <a-row class="row-item-search">
        <a-form class="search-form" :form="searchForm" layout="inline" @submit="handleSearch">
          <a-form-item label="昵称/手机号">
            <a-input v-decorator="['search']" placeholder="请输入昵称/手机号" />
          </a-form-item>
          <a-form-item v-if="$module('user-grade')" label="会员等级">
            <a-select v-decorator="['gradeId', { initialValue: 0 }]">
              <a-select-option :value="0">全部</a-select-option>
              <a-select-option
                v-for="(item, index) in gradeList"
                :key="index"
                :value="item.grade_id"
              >{{ item.name }}</a-select-option>
            </a-select>
          </a-form-item>
          <a-form-item label="注册时间">
            <a-range-picker format="YYYY-MM-DD" v-decorator="['betweenTime']" />
          </a-form-item>
          <a-form-item class="search-btn">
            <a-button type="primary" icon="search" html-type="submit">搜索</a-button>
          </a-form-item>
        </a-form>
      </a-row>
    </div>
    <s-table
      ref="table"
      :scroll="{ y: '420px', scrollToFirstRowOnChange: true }"
      :rowKey="fieldName"
      :loading="isLoading"
      :columns="columns"
      :data="loadData"
      :rowSelection="rowSelection"
      :pageSize="15"
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
      <!-- 余额/积分 -->
      <span slot="balance" slot-scope="text, item">
        <p>
          <span>余额：</span>
          <span class="c-p">{{ text }}</span>
        </p>
        <p>
          <span>积分：</span>
          <span class="c-p">{{ item.points }}</span>
        </p>
      </span>
      <!-- 实际消费金额 -->
      <span slot="expend_money" slot-scope="text">
        <span class="c-p">{{ text }}</span>
      </span>
    </s-table>
  </a-modal>
</template>

<script>
import PropTypes from 'ant-design-vue/es/_util/vue-types'
import cloneDeep from 'lodash.clonedeep'
import { filterModules } from '@/core/app'
import * as Api from '@/api/user'
import * as GradeApi from '@/api/user/grade'
import { STable } from '@/components/Table'

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
    title: '余额/积分',
    dataIndex: 'balance',
    scopedSlots: { customRender: 'balance' }
  },
  {
    title: '实际消费金额',
    dataIndex: 'expend_money',
    scopedSlots: { customRender: 'expend_money' }
  }
])

export default {
  name: 'UserModal',
  props: {
    // 多选模式, 如果false为单选
    multiple: PropTypes.bool.def(true),
    // 最大选择的数量限制, multiple模式下有效
    maxNum: PropTypes.integer.def(100),
    // 默认选中的列表记录
    defaultList: PropTypes.array.def([]),
  },
  components: {
    STable
  },
  data () {
    return {
      // 对话框标题
      title: '选择会员',
      // modal(对话框)是否可见
      visible: false,
      // loading状态
      isLoading: false,
      // 当前表单元素
      searchForm: this.$form.createForm(this),
      // 查询参数
      queryParam: {},
      // table表头
      columns,
      // 加载数据方法 必须为 Promise 对象
      loadData: param => {
        return Api.list({ ...param, ...this.queryParam })
          .then(response => response.data.list)
      },
      // 主键ID字段名
      fieldName: 'user_id',
      // 已选择的元素
      selectedRowKeys: [],
      // 已选择的列表记录
      selectedItems: [],
      // 会员等级列表
      gradeList: []
    }
  },
  computed: {
    rowSelection () {
      return {
        selectedRowKeys: this.selectedRowKeys,
        onChange: this.onSelectChange,
        type: !this.multiple ? 'radio' : 'checkbox'
      }
    }
  },
  created () {
    // 获取会员等级列表
    this.getGradeList()
  },
  methods: {

    // 显示对话框
    handle () {
      // 显示窗口
      this.visible = true
      this.$nextTick(() => {
        // 刷新列表数据
        // this.handleRefresh(true)
        // 设置默认数据
        this.setDefaultValue()
      })
    },

    // 获取会员等级列表
    getGradeList () {
      GradeApi.all().then(result => {
        this.gradeList = result.data.list
      })
    },

    // 设置默认数据
    setDefaultValue () {
      const { fieldName, defaultList } = this
      if (defaultList.length) {
        this.selectedItems = cloneDeep(defaultList)
        this.selectedRowKeys = defaultList.map(item => item[fieldName])
      }
    },

    // 选中项发生变化时的回调
    onSelectChange (selectedRowKeys, newSelectedItems) {
      const { selectedItems } = this
      this.selectedRowKeys = selectedRowKeys
      this.selectedItems = this.createSelectedItems(selectedRowKeys, selectedItems, newSelectedItems)
    },

    /**
     * 生成已选中的元素列表
     * @param array selectedRowKeys 当前已选中的ID集
     * @param array oldSelectedItems 已选择的列表记录 (change前)
     * @param array newSelectedItems 已选择的列表记录 (change后)
     */
    createSelectedItems (selectedRowKeys, oldSelectedItems, newSelectedItems) {
      const { fieldName } = this
      const selectedItems = []
      oldSelectedItems.forEach(item => {
        if (selectedRowKeys.includes(item[fieldName])) {
          selectedItems.push(item)
        }
      })
      const oldSelectedKeys = oldSelectedItems.map(item => item[fieldName])
      newSelectedItems.forEach(item => {
        if (!oldSelectedKeys.includes(item[fieldName]) && selectedRowKeys.includes(item[fieldName])) {
          selectedItems.push(item)
        }
      })
      return selectedItems
    },

    /**
     * 刷新列表
     * @param Boolean bool 强制刷新到第一页
     */
    handleRefresh (bool = false) {
      this.$refs.table.refresh(true)
    },

    // 确认搜索
    handleSearch (e) {
      e.preventDefault()
      this.searchForm.validateFields((error, values) => {
        if (!error) {
          this.queryParam = { ...this.queryParam, ...values }
          this.handleRefresh(true)
        }
      })
    },

    // 关闭对话框事件
    handleCancel () {
      this.visible = false
      this.queryParam = {}
      this.searchForm.resetFields()
      this.selectedRowKeys = []
      this.selectedItems = []
    },

    // 确认按钮
    handleSubmit (e) {
      e.preventDefault()
      // 通知父端组件提交完成了
      this.$emit('handleSubmit', {
        selectedItems: this.selectedItems,
        selectedRowKeys: this.selectedRowKeys
      })
      // 关闭对话框
      this.handleCancel()
    }

  }
}
</script>

<style lang="less" scoped>
.ant-modal-root {
  background: #ccc;
  /deep/.ant-modal-body {
    padding-bottom: 8px;
  }
  /deep/.ant-modal-footer {
    padding-top: 0;
  }
}
// 搜索表单
.search-form {
  /deep/.ant-form-item-control-wrapper {
    min-width: 180px;
  }
}
</style>
