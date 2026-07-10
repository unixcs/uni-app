<template>
  <a-card :bordered="false">
    <div class="card-title">{{ $route.meta.title }}</div>
    <div class="table-operator">
      <a-row class="row-item-search">
        <a-form class="search-form" :form="searchForm" layout="inline" @submit="handleSearch">
          <a-form-item label="问题类型">
            <a-select v-decorator="['issueType', { initialValue: -1 }]" style="width: 140px">
              <a-select-option :value="-1">全部</a-select-option>
              <a-select-option v-for="item in issueTypeOptions" :key="item.value" :value="item.value">{{ item.label }}</a-select-option>
            </a-select>
          </a-form-item>
          <a-form-item label="处理状态">
            <a-select v-decorator="['status', { initialValue: -1 }]" style="width: 140px">
              <a-select-option :value="-1">全部</a-select-option>
              <a-select-option v-for="item in statusOptions" :key="item.value" :value="item.value">{{ item.label }}</a-select-option>
            </a-select>
          </a-form-item>
          <a-form-item label="联系方式">
            <a-input v-decorator="['mobile']" style="width: 180px" placeholder="请输入联系方式" />
          </a-form-item>
          <a-form-item label="关键词">
            <a-input v-decorator="['search']" style="width: 240px" placeholder="反馈ID/内容/昵称/手机号" />
          </a-form-item>
          <a-form-item class="search-btn">
            <a-button type="primary" icon="search" html-type="submit">搜索</a-button>
          </a-form-item>
          <a-form-item class="search-btn">
            <a-button @click="handleReset">重置</a-button>
          </a-form-item>
        </a-form>
      </a-row>
    </div>
    <s-table
      ref="table"
      rowKey="feedback_id"
      :loading="isLoading"
      :columns="columns"
      :data="loadData"
      :pageSize="15"
    >
      <span slot="issueType" slot-scope="text, item">
        <a-tag :color="renderIssueTypeColor(item.issue_type)">{{ item.issue_type_text }}</a-tag>
      </span>
      <span slot="content" slot-scope="text">
        <p class="twoline-hide content-cell">{{ text || '--' }}</p>
      </span>
      <span slot="user" slot-scope="text, item">
        <UserItem :user="item.user" />
      </span>
      <span slot="status" slot-scope="text, item">
        <a-tag :color="renderStatusColor(item.status)">{{ item.status_text }}</a-tag>
      </span>
      <span slot="replyTime" slot-scope="text">
        {{ text || '--' }}
      </span>
      <span slot="action" slot-scope="text, item">
        <a v-action:edit @click="handleEdit(item)">查看/处理</a>
      </span>
    </s-table>
    <EditForm ref="EditForm" @handleSubmit="handleRefresh" />
  </a-card>
</template>

<script>
import * as Api from '@/api/content/feedback'
import { STable } from '@/components'
import { UserItem } from '@/components/Table'
import { EditForm } from './modules'

const issueTypeOptions = [
  { value: 10, label: '功能建议' },
  { value: 20, label: '功能异常' },
  { value: 30, label: '体验问题' },
  { value: 40, label: '订单问题' },
  { value: 50, label: '其他问题' },
  { value: 60, label: '投诉反馈' }
]

const statusOptions = [
  { value: 10, label: '待处理' },
  { value: 20, label: '处理中' },
  { value: 30, label: '已回复' },
  { value: 40, label: '已关闭' }
]

export default {
  name: 'Index',
  components: {
    STable,
    UserItem,
    EditForm
  },
  data () {
    return {
      searchForm: this.$form.createForm(this),
      queryParam: {},
      isLoading: false,
      issueTypeOptions,
      statusOptions,
      columns: [
        {
          title: '反馈ID',
          dataIndex: 'feedback_id',
          width: '90px'
        },
        {
          title: '问题类型',
          dataIndex: 'issue_type',
          width: '120px',
          scopedSlots: { customRender: 'issueType' }
        },
        {
          title: '反馈内容',
          dataIndex: 'content',
          scopedSlots: { customRender: 'content' }
        },
        {
          title: '联系方式',
          dataIndex: 'mobile',
          width: '140px'
        },
        {
          title: '提交用户',
          dataIndex: 'user',
          width: '170px',
          scopedSlots: { customRender: 'user' }
        },
        {
          title: '处理状态',
          dataIndex: 'status',
          width: '120px',
          scopedSlots: { customRender: 'status' }
        },
        {
          title: '提交时间',
          dataIndex: 'create_time',
          width: '170px'
        },
        {
          title: '回复时间',
          dataIndex: 'reply_time',
          width: '170px',
          scopedSlots: { customRender: 'replyTime' }
        },
        {
          title: '操作',
          dataIndex: 'action',
          width: '110px',
          scopedSlots: { customRender: 'action' }
        }
      ],
      loadData: param => {
        return Api.list({ ...param, ...this.queryParam })
          .then(response => response.data.list)
      }
    }
  },
  methods: {
    handleEdit (item) {
      this.$refs.EditForm.show(item)
    },
    handleRefresh (bool = false) {
      this.$refs.table.refresh(bool)
    },
    handleSearch (e) {
      e.preventDefault()
      this.searchForm.validateFields((error, values) => {
        if (!error) {
          this.queryParam = { ...values }
          this.handleRefresh(true)
        }
      })
    },
    handleReset () {
      this.searchForm.resetFields()
      this.queryParam = {}
      this.handleRefresh(true)
    },
    renderIssueTypeColor (issueType) {
      return Number(issueType) === 60 ? 'red' : 'blue'
    },
    renderStatusColor (status) {
      const colorMap = {
        10: '',
        20: 'orange',
        30: 'green',
        40: 'default'
      }
      return colorMap[status] || ''
    }
  }
}
</script>

<style lang="less" scoped>
.content-cell {
  width: 320px;
}
</style>
