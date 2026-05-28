<template>
  <a-card :bordered="false">
    <content-header title="商城列表"></content-header>
    <div class="table-operator">
      <a-button type="primary" icon="plus" @click="$refs.createModal.show()">新增</a-button>
    </div>
    <s-table
      ref="table"
      size="default"
      rowKey="store_id"
      :columns="columns"
      :data="loadData"
      showPagination="auto"
      :pageSize="15"
    >
      <!-- 账户名 -->
      <template slot="user_name" slot-scope="item">
        <span>{{ item.superUser.user_name }}</span>
      </template>
      <!-- 备注信息 -->
      <template slot="remark" slot-scope="text">
        <a-tooltip>
          <template v-if="text" slot="title">{{ text ? text : '--' }}</template>
          <p class="twoline-hide" style="width: 150px;">{{ text ? text : '--' }}</p>
        </a-tooltip>
      </template>
      <!-- 操作菜单 -->
      <span class="actions" slot="action" slot-scope="text, item">
        <a @click="handleInto(item)">进入商城</a>
        <a-dropdown>
          <a-menu slot="overlay">
            <!-- <a-menu-item>
              <a @click="handleModule(item)">功能模块</a>
            </a-menu-item>-->
            <a-menu-item>
              <a @click="handleAccount(item)">修改账户</a>
            </a-menu-item>
            <!-- <a-menu-item>
              <a @click="handleH5Domain(item)">H5域名绑定</a>
            </a-menu-item>-->
            <a-menu-item>
              <a @click="handleDelete(item)">删除</a>
            </a-menu-item>
          </a-menu>
          <a>
            <span>更多</span>
            <a-icon type="down" />
          </a>
        </a-dropdown>
      </span>
    </s-table>
    <create-form ref="createModal" @handleSubmit="handleRefresh" />
    <AccountForm ref="AccountForm" @handleSubmit="handleRefresh" />
  </a-card>
</template>

<script>
import * as Api from '@/api/store'
import { ContentHeader, STable } from '@/components'
import CreateForm from './modules/CreateForm'
import AccountForm from './modules/AccountForm'
import { urlEncode } from '@/utils/util'

export default {
  components: {
    ContentHeader,
    STable,
    CreateForm,
    AccountForm
  },
  data () {
    return {
      // 查询参数
      queryParam: {},
      // 表头
      columns: [
        {
          title: '商城ID',
          dataIndex: 'store_id',
          width: '120px'
        },
        {
          title: '商城名称',
          dataIndex: 'store_name',
          width: '200px'
        },
        {
          title: '账户名',
          // dataIndex: 'user_name',
          scopedSlots: { customRender: 'user_name' },
          width: '180px'
        },
        {
          title: '备注信息',
          dataIndex: 'remark',
          scopedSlots: { customRender: 'remark' }
        },
        {
          title: '排序',
          dataIndex: 'sort'
        },
        {
          title: '添加时间',
          dataIndex: 'create_time'
        },
        {
          title: '操作',
          dataIndex: 'action',
          width: '200px',
          scopedSlots: { customRender: 'action' }
        }
      ],
      // 加载数据方法 必须为 Promise 对象
      loadData: param => Api.list(Object.assign(param, this.queryParam)).then(response => response.data.list)
    }
  },
  created () { },
  methods: {

    // 进入商城
    handleInto (item) {
      if (process.env.NODE_ENV !== 'production') {
        this.$message.error('很抱歉，开发环境中不支持')
        return false
      }
      const storeUrl = window.serverConfig.STORE_URL
      const url = `${storeUrl}/#/passport/login`
      window.open(url, '_blank')
    },

    // 删除记录
    handleDelete (item) {
      const app = this
      app.$confirm({
        title: '您确定要删除该商城吗?',
        content: '确认后将移入回收站',
        // okType: 'danger',
        onOk () {
          // 确认删除
          return app.onSubmitDelete(item)
        }
      })
    },

    // 确认删除
    onSubmitDelete (item) {
      return Api.recovery({ storeId: item['store_id'] })
        .then(result => {
          this.$message.success(result.message)
          this.handleRefresh()
        })
    },

    // // 设置功能模块
    // handleModule (item) {
    //   this.$refs.ModuleForm.show(item)
    // },

    // 修改商家账户
    handleAccount (item) {
      this.$refs.AccountForm.show(item)
    },

    // 刷新列表
    handleRefresh () {
      this.$refs.table.refresh()
    }

  }
}
</script>
