<template>
  <a-card :bordered="false">
    <div class="card-title">{{ $route.meta.title }}</div>
    <div class="table-operator">
      <a-row>
        <a-col :span="5">
          <a-button
            v-if="$auth('/setting/payment/template/create')"
            type="primary"
            icon="plus"
            @click="handleAdd()"
          >新增</a-button>
        </a-col>
      </a-row>
    </div>
    <s-table
      ref="table"
      rowKey="template_id"
      :loading="isLoading"
      :columns="columns"
      :data="loadData"
      :pageSize="15"
    >
      <!-- 支付方式 -->
      <span slot="method" slot-scope="text">
        <a-tag>{{ PaymentMethodEnum[text].name }}</a-tag>
      </span>
      <!-- 备注信息 -->
      <span slot="remarks" slot-scope="text">
        <a-tooltip>
          <template v-if="text" slot="title">{{ text ? text : '--' }}</template>
          <p class="twoline-hide" style="width: 150px;">{{ text ? text : '--' }}</p>
        </a-tooltip>
      </span>
      <!-- 操作 -->
      <div class="actions" slot="action" slot-scope="text, item">
        <a v-if="$auth('/setting/payment/template/update')" @click="handleEdit(item)">编辑</a>
        <a v-action:delete @click="handleDelete(item)">删除</a>
      </div>
    </s-table>
  </a-card>
</template>

<script>
import { PaymentMethodEnum } from '@/common/enum/payment'
import * as Api from '@/api/setting/payment/template'
import { STable } from '@/components'

export default {
  name: 'Index',
  components: {
    STable
  },
  data () {
    return {
      PaymentMethodEnum,
      // 查询参数
      queryParam: {},
      // 正在加载
      isLoading: false,
      // 表头
      columns: [
        {
          title: '模板ID',
          dataIndex: 'template_id'
        },
        {
          title: '模板名称',
          dataIndex: 'name'
        },
        {
          title: '支付方式',
          dataIndex: 'method',
          scopedSlots: { customRender: 'method' }
        },
        {
          title: '备注信息',
          dataIndex: 'remarks',
          scopedSlots: { customRender: 'remarks' }
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
          title: '更新时间',
          dataIndex: 'update_time'
        },
        {
          title: '操作',
          dataIndex: 'action',
          width: '180px',
          scopedSlots: { customRender: 'action' }
        }
      ],
      // 加载数据方法 必须为 Promise 对象
      loadData: param => {
        return Api.list({ ...param, ...this.queryParam })
          .then(response => response.data.list)
      }
    }
  },
  created () {

  },
  methods: {

    // 新增记录
    handleAdd () {
      this.$router.push({ path: './create' })
    },

    // 编辑记录
    handleEdit (item) {
      this.$router.push({ path: './update', query: { templateId: item.template_id } })
    },

    // 删除记录
    handleDelete (item) {
      const app = this
      const modal = this.$confirm({
        title: '您确定要删除该记录吗?',
        content: '删除后不可恢复',
        onOk () {
          return Api.deleted({ templateId: item.template_id })
            .then(result => {
              app.$message.success(result.message, 1.5)
              app.handleRefresh()
            })
            .finally(result => modal.destroy())
        }
      })
    },

    /**
     * 刷新列表
     * @param Boolean bool 强制刷新到第一页
     */
    handleRefresh (bool = false) {
      this.$refs.table.refresh(bool)
    },

    // 检索查询
    onSearch () {
      this.handleRefresh(true)
    }

  }
}
</script>
