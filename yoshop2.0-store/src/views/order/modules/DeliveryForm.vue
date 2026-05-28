<template>
  <a-modal
    :title="title"
    :width="width"
    :visible="visible"
    :isLoading="isLoading"
    :confirmLoading="isLoading"
    :maskClosable="false"
    @ok="handleSubmit"
    @cancel="handleCancel"
  >
    <a-spin :spinning="isLoading">
      <a-form-model
        ref="ruleForm"
        :model="formData"
        :rules="rules"
        :label-col="labelCol"
        :wrapperCol="wrapperCol"
      >
        <!-- 实物订单 -->
        <a-tabs
          v-if="record.order_type == OrderTypeEnum.PHYSICAL.value"
          class="physical"
          v-model="tabKey"
        >
          <a-tab-pane :key="1" tab="未发货的商品">
            <a-table
              rowKey="order_goods_id"
              :columns="columns1"
              :dataSource="notDeliveredList"
              :row-selection="rowSelection"
              :pagination="false"
              :scroll="{ y: '320px', scrollToFirstRowOnChange: true }"
            >
              <!-- 商品信息 -->
              <template slot="goods" slot-scope="goods">
                <GoodsItem
                  :data="{
                    image: goods.goods_image,
                    imageAlt: '商品图片',
                    title: goods.goods_name,
                    goodsProps: goods.goods_props,
                    titleWidth: 170
                  }"
                  :subTitleColor="true"
                />
              </template>
              <!-- 可发货数量 -->
              <span slot="quantity" slot-scope="text, item">{{ item.total_num - item.delivery_num }}</span>
              <!-- 发货数量 -->
              <template slot="input" slot-scope="text, item, index">
                <a-input-number
                  :min="1"
                  :max="item.total_num - item.delivery_num"
                  :precision="0"
                  v-model="packGoodsData[index].deliveryNum"
                />
              </template>
            </a-table>
            <a-form-model-item label="发货方式" prop="deliveryMethod" required>
              <a-radio-group v-model="formData.deliveryMethod">
                <a-radio :value="10">物流信息</a-radio>
                <a-radio :value="20">无需物流</a-radio>
              </a-radio-group>
            </a-form-model-item>
            <div v-if="formData.deliveryMethod == 10">
              <a-form-model-item label="物流公司" prop="expressId">
                <a-select v-model="formData.expressId" placeholder="请选择物流公司">
                  <a-select-option
                    v-for="(item, index) in expressList"
                    :key="index"
                    :value="item.express_id"
                  >{{ item.express_name }}</a-select-option>
                </a-select>
                <div class="form-item-help">
                  <router-link
                    target="_blank"
                    :to="{ path: '/setting/delivery/express/index' }"
                  >物流公司管理</router-link>
                </div>
              </a-form-model-item>
              <a-form-model-item label="物流单号" prop="expressNo" extra="请手动录入物流单号或快递单号">
                <a-input v-model="formData.expressNo" />
              </a-form-model-item>
            </div>

            <div
              v-if="isEnableShipping && record.pay_method === PaymentMethodEnum.WECHAT.value && record.platform === ClientEnum.MP_WEIXIN.value"
            >
              <a-form-model-item label="同步微信平台" prop="syncMpWeixinShipping" required>
                <a-radio-group v-model="formData.syncMpWeixinShipping">
                  <a-radio :value="1">同步</a-radio>
                  <a-radio :value="0">不同步</a-radio>
                </a-radio-group>
                <div class="form-item-help">
                  <p class="extra">同步至微信小程序平台的《发货信息管理》</p>
                  <p class="extra">仅全部发货或最后一次发货时同步</p>
                </div>
              </a-form-model-item>
            </div>
          </a-tab-pane>
          <a-tab-pane :key="2" tab="已发货的商品">
            <a-table
              :columns="columns2"
              rowKey="order_goods_id"
              :dataSource="deliveredList"
              :pagination="false"
              :scroll="{ y: '320px', scrollToFirstRowOnChange: true }"
            >
              <!-- 商品信息 -->
              <template slot="goods" slot-scope="goods">
                <GoodsItem
                  :data="{
                    image: goods.goods_image,
                    imageAlt: '商品图片',
                    title: goods.goods_name,
                    goodsProps: goods.goods_props,
                    titleWidth: 170
                  }"
                  :subTitleColor="true"
                />
              </template>
              <!-- 购买数量 -->
              <span slot="total_num" slot-scope="text">{{ text }}</span>
              <!-- 已发货数量 -->
              <span slot="quantity" slot-scope="text, item">{{ item.delivery_num }}</span>
            </a-table>
          </a-tab-pane>
        </a-tabs>
      </a-form-model>
    </a-spin>
  </a-modal>
</template>

<script>
import { assignment } from '@/utils/util'
import * as Api from '@/api/order/delivery'
import * as ExpressApi from '@/api/setting/express'
import WxappSettingModel from '@/common/model/client/wxapp/setting'
import ClientEnum from '@/common/enum/Client'
import { PaymentMethodEnum } from '@/common/enum/payment'
import { DeliveryStatusEnum, OrderTypeEnum } from '@/common/enum/order'
import { GoodsItem } from '@/components/Table'

const columns1 = [
  {
    title: '商品信息',
    scopedSlots: { customRender: 'goods' },
    width: '50%'
  },
  {
    title: '可发货的数量',
    scopedSlots: { customRender: 'quantity' },
    width: '20%',
  },
  {
    title: '发货数量',
    scopedSlots: { customRender: 'input' },
    width: '30%',
  }
]

const columns2 = [
  {
    title: '商品信息',
    scopedSlots: { customRender: 'goods' },
    width: '50%'
  },
  {
    title: '购买数量',
    dataIndex: 'total_num',
    width: '20%',
  },
  {
    title: '已发货的数量',
    scopedSlots: { customRender: 'quantity' },
    width: '20%',
  }
]

const rules = {
  expressId: [
    { required: true, message: '请选择物流公司', trigger: 'blur' }
  ],
  expressNo: [
    { required: true, message: '请填写物流单号', trigger: 'blur' }
  ],
}

export default {
  components: {
    GoodsItem
  },
  data () {
    return {
      // 对话框标题
      title: '订单发货',
      // 标签布局属性
      labelCol: { span: 7 },
      // 输入框布局属性
      wrapperCol: { span: 13 },
      // modal(对话框)是否可见
      visible: false,
      // modal(对话框)确定按钮 loading
      isLoading: false,
      // 对话框宽度
      width: 680,
      // 商品表格字段
      columns1,
      columns2,
      // 验证规则
      rules,
      // 当前tab索引
      tabKey: 1,
      // 物流公司列表
      expressList: [],
      // 当前记录
      record: {},
      // 发货商品数量
      packGoodsData: [],
      // form记录
      formData: {
        // 发货方式 (10手动录入 20无需物流)
        deliveryMethod: 10,
        // 分包发货的商品
        packGoodsData: [],
        // 物流公司ID
        expressId: undefined,
        // 物流单号
        expressNo: '',
        // 同步至微信小程序《发货信息管理》
        syncMpWeixinShipping: 1,
      },
      // 选择的商品
      selectedRowKeys: [],
      // 是否开启发货信息管理
      isEnableShipping: false
    }
  },
  computed: {
    rowSelection () {
      return {
        onChange: (selectedRowKeys, selectedRows) => {
          this.selectedRowKeys = selectedRowKeys
        },
        selectedRowKeys: this.selectedRowKeys
      }
    },
    // 未发货的商品列表
    notDeliveredList () {
      const { record } = this
      if (record && record.goods) {
        const list = []
        record.goods.forEach(item => {
          if (item.delivery_status != DeliveryStatusEnum.DELIVERED.value) {
            list.push(item)
          }
        })
        this.selectedRowKeys = list.map(item => item.order_goods_id)
        return list
      }
    },
    // 已发货的商品列表
    deliveredList () {
      const { record } = this
      if (record && record.goods) {
        const list = []
        record.goods.forEach(item => {
          if (item.delivery_status != DeliveryStatusEnum.NOT_DELIVERED.value) {
            list.push(item)
          }
        })
        return list
      }
    },
  },
  beforeCreate () {
    // 批量给当前实例赋值
    assignment(this, { ClientEnum, PaymentMethodEnum, OrderTypeEnum })
  },
  created () {
    // 获取物流公司列表
    this.getExpressList()
  },
  methods: {

    // 显示对话框
    async show (record) {
      // 当前记录
      this.record = record
      // 是否开启发货信息管理
      this.isEnableShipping = await WxappSettingModel.isEnableShipping()
      // 显示窗口
      this.visible = true
      this.tabKey = 1
      // 判断订单类型
      if (record.order_type == OrderTypeEnum.PHYSICAL.value) {
        this.showModalByPhysical()
      }
    },

    // 对话框：实物订单
    showModalByPhysical () {
      this.width = 680
      this.initPackGoodsData()
    },

    // 未发货的model记录
    initPackGoodsData () {
      const { notDeliveredList } = this
      if (notDeliveredList && notDeliveredList.length) {
        this.packGoodsData = notDeliveredList.map(item => {
          return {
            orderGoodsId: item.order_goods_id,
            deliveryNum: item.total_num - item.delivery_num
          }
        })
      }
    },

    // 获取物流公司列表
    getExpressList () {
      this.isLoading = true
      ExpressApi.all()
        .then(result => this.expressList = result.data.list)
        .finally(() => this.isLoading = false)
    },

    // 确认按钮
    handleSubmit (e) {
      e.preventDefault()
      const app = this
      app.tabKey = 1
      // 实物订单
      if (app.record.order_type == OrderTypeEnum.PHYSICAL.value) {
        // 验证是否选择了商品
        if (!app.selectedRowKeys.length) {
          app.$message.error('您还没有选择要发货的商品')
          return false
        }
        // 生成分包商品数据
        app.formData.packGoodsData = app.packGoodsData.filter(item => app.selectedRowKeys.includes(item.orderGoodsId))
      }
      // 表单验证
      app.$refs.ruleForm.validate(valid => {
        // 提交到后端api
        valid && app.onFormSubmit()
      })
    },

    // 关闭对话框事件
    handleCancel () {
      this.visible = false
      if (this.$refs.ruleForm) {
        this.$refs.ruleForm.resetFields()
      }
    },

    // 提交到后端api
    onFormSubmit () {
      this.isLoading = true
      Api.delivery({ orderId: this.record.order_id, form: this.formData })
        .then(result => {
          // 显示成功
          this.$message.success(result.message, 1.5)
          // 关闭对话框事件
          this.handleCancel()
          // 通知父端组件提交完成了
          this.$emit('handleSubmit', true)
        })
        .finally(() => this.isLoading = false)
    }

  }
}
</script>
<style lang="less" scoped>
/deep/.ant-modal-header {
  border-bottom: none;
}
/deep/.ant-modal-footer {
  border-top: none;
}
/deep/.ant-modal-body {
  padding: 0px 24px;
}
/deep/.ant-tabs-bar {
  margin-bottom: 22px;
}
.ant-table-wrapper {
  margin-bottom: 30px;
}
.ant-form-item {
  margin-bottom: 15px;
  &:last-child {
    margin-bottom: 5px;
  }
}
</style>