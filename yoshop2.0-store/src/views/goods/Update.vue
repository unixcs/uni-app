<template>
  <a-card :bordered="false">
    <div class="card-title">{{ $route.meta.title }}</div>
    <a-spin :spinning="isLoading">
      <a-form :form="form" @submit="handleSubmit" :selfUpdate="true">
        <a-tabs :activeKey="tabKey" :tabBarStyle="{ marginBottom: '30px' }" @change="handleTabs">
          <a-tab-pane :key="0" tab="基本信息"></a-tab-pane>
          <a-tab-pane :key="1" tab="规格/库存"></a-tab-pane>
          <a-tab-pane :key="2" tab="服务详情"></a-tab-pane>
          <a-tab-pane :key="3" tab="更多设置"></a-tab-pane>
        </a-tabs>
        <div class="tabs-content">
          <!-- 基本信息 -->
          <div class="tab-pane" v-show="tabKey == 0">
            <a-form-item label="服务类型" :labelCol="labelCol" :wrapperCol="wrapperCol">
              <GoodsType
                :onlyShowChecked="true"
                v-decorator="['goods_type', { rules: [{ required: true }] }]"
                @change="onForceUpdate(true)"
              />
            </a-form-item>
            <a-form-item label="服务名称" :labelCol="labelCol" :wrapperCol="wrapperCol">
              <a-input
                placeholder="请输入服务名称"
                v-decorator="['goods_name', { rules: [{ required: true, min: 2, message: '请输入至少2个字符' }] }]"
              />
            </a-form-item>
            <a-form-item label="服务分类" :labelCol="labelCol" :wrapperCol="wrapperCol">
              <a-tree-select
                placeholder="请选择服务分类"
                :dropdownStyle="{ maxHeight: '500px', overflow: 'auto' }"
                :treeData="formData.categoryList"
                treeCheckable
                treeCheckStrictly
                allowClear
                v-decorator="['categorys', { rules: [{ required: true, message: '请至少选择1个服务分类' }] }]"
              ></a-tree-select>
              <div class="form-item-help">
                <router-link target="_blank" :to="{ path: '/goods/category/index' }">去新增</router-link>
                <a href="javascript:;" @click="onReloadCategoryList">刷新</a>
              </div>
            </a-form-item>
            <a-form-item
              label="服务封面"
              :labelCol="labelCol"
              :wrapperCol="wrapperCol"
              extra="建议尺寸：750*750像素, 最多上传10张, 可拖拽图片调整顺序, 第1张将作为封面图"
            >
              <SelectImage
                multiple
                :maxNum="10"
                :defaultList="formData.goods.goods_images"
                v-decorator="['imagesIds', { rules: [{ required: true, message: '请至少上传1张服务封面' }] }]"
              />
            </a-form-item>
            <a-form-item label="服务编码" :labelCol="labelCol" :wrapperCol="wrapperCol">
              <a-input placeholder="请输入服务编码" v-decorator="['goods_no']" />
            </a-form-item>
            <a-form-item label="服务状态" :labelCol="labelCol" :wrapperCol="wrapperCol">
              <a-radio-group
                v-decorator="['status', { initialValue: 10, rules: [{ required: true }] }]"
              >
                <a-radio :value="10">上架</a-radio>
                <a-radio :value="20">下架</a-radio>
              </a-radio-group>
            </a-form-item>
            <a-form-item label="服务排序" :labelCol="labelCol" :wrapperCol="wrapperCol" extra="数字越小越靠前">
              <a-input-number
                :min="0"
                v-decorator="['sort', { initialValue: 100, rules: [{ required: true }] }]"
              />
            </a-form-item>
          </div>

          <!-- 规格/库存 -->
          <div class="tab-pane" v-show="tabKey == 1">
            <a-form-item label="规格类型" :labelCol="labelCol" :wrapperCol="wrapperCol">
              <a-radio-group
                v-decorator="['spec_type', { initialValue: 10, rules: [{ required: true }] }]"
                @change="onForceUpdate()"
              >
                <a-radio :value="10" :disabled="formData.goods.isSpecLocked">单规格</a-radio>
                <a-radio :value="20" :disabled="formData.goods.isSpecLocked">多规格</a-radio>
              </a-radio-group>
              <p v-if="formData.goods.isSpecLocked" class="form-item-help">
                <small class="c-red">注：该服务套餐当前正在参与其他活动，套餐规格不允许更改</small>
              </p>
            </a-form-item>
            <!-- 多规格的表单内容 -->
            <div v-if="form.getFieldValue('spec_type') == 20">
              <MultiSpec
                ref="MultiSpec"
                :isSpecLocked="formData.goods.isSpecLocked"
                :defaultSpecList="formData.goods.specList"
                :defaultSkuList="formData.goods.skuList"
              />
            </div>
            <!-- 单规格的表单内容 -->
            <div v-if="form.getFieldValue('spec_type') == 10">
              <a-form-item
                label="服务价格"
                :labelCol="labelCol"
                :wrapperCol="wrapperCol"
                extra="服务的实际购买金额，最低0.01"
              >
                <a-input-number
                  :min="0.01"
                  :precision="2"
                  @change="onGoodsPriceChange"
                  v-decorator="['goods_price', { rules: [{ required: true, message: '请输入服务价格' }, { validator: validateVirtualPaymentGoodsPrice }] }]"
                />
                <span class="ml-10">元</span>
              </a-form-item>
              <a-form-item
                label="划线价"
                :labelCol="labelCol"
                :wrapperCol="wrapperCol"
                extra="划线价仅用于服务页展示"
              >
                <a-input-number :min="0" :precision="2" v-decorator="['line_price']" />
                <span class="ml-10">元</span>
              </a-form-item>
              <a-form-item
                label="当前库存数量"
                :labelCol="labelCol"
                :wrapperCol="wrapperCol"
                extra="服务的实际库存数量，为0时用户无法下单"
              >
                <a-input-number
                  :min="0"
                  :precision="0"
                  v-decorator="['stock_num', { initialValue: 100, rules: [{ required: true, message: '请输入库存数量' }] }]"
                />
                <span class="ml-10">件</span>
              </a-form-item>
            </div>
            <a-form-item label="库存计算方式" :labelCol="labelCol" :wrapperCol="wrapperCol">
              <a-radio-group
                v-decorator="['deduct_stock_type', { initialValue: 10, rules: [{ required: true }] }]"
              >
                <a-radio :value="10">下单减库存</a-radio>
                <a-radio :value="20">付款减库存</a-radio>
              </a-radio-group>
            </a-form-item>
            <a-form-item
              label="服务限购"
              :labelCol="labelCol"
              :wrapperCol="wrapperCol"
              extra="用于限制每人购买该服务套餐的数量"
            >
              <a-radio-group
                v-decorator="['is_restrict', { initialValue: 0, rules: [{ required: true }] }]"
                @change="onForceUpdate()"
              >
                <a-radio :value="0">关闭</a-radio>
                <a-radio :value="1">开启</a-radio>
              </a-radio-group>
              <div class="mt-10" v-if="form.getFieldValue('is_restrict')">
                <a-form-item>
                  <span class="mr-10">总限购</span>
                  <a-input-number
                    :min="1"
                    :precision="0"
                    v-decorator="['restrict_total', { rules: [{ required: true, message: '请输入总限购数量' }] }]"
                  />
                  <span class="ml-10">件/人</span>
                </a-form-item>
                <a-form-item>
                  <span class="mr-10">每单限购</span>
                  <a-input-number
                    :min="1"
                    :precision="0"
                    v-decorator="['restrict_single', { rules: [{ required: true, message: '请输入每单限购数量' }] }]"
                  />
                  <span class="ml-10">件/人</span>
                </a-form-item>
              </div>
            </a-form-item>
          </div>
          <!-- 服务详情 -->
          <div class="tab-pane" v-show="tabKey == 2">
            <a-form-item label="服务详情" :labelCol="labelCol" :wrapperCol="{span: 16}">
              <Ueditor
                v-decorator="['content', { rules: [{ required: true, message: '服务详情不能为空' }] }]"
              />
            </a-form-item>
          </div>

          <!-- 更多设置 -->
          <div class="tab-pane" v-show="tabKey == 3">
            <a-form-item
              label="主图视频"
              :labelCol="labelCol"
              :wrapperCol="wrapperCol"
              extra="建议视频宽高比16:9，建议时长8-45秒"
            >
              <SelectVideo
                :multiple="false"
                :defaultList="formData.goods.video ? [formData.goods.video] : []"
                v-decorator="['video_id']"
              />
            </a-form-item>
            <a-form-item
              label="视频封面"
              :labelCol="labelCol"
              :wrapperCol="wrapperCol"
              extra="建议尺寸：750像素*750像素"
            >
              <SelectImage
                :multiple="false"
                :defaultList="formData.goods.videoCover ? [formData.goods.videoCover] : []"
                v-decorator="['video_cover_id']"
              />
            </a-form-item>
            <a-form-item
              label="服务卖点"
              :labelCol="labelCol"
              :wrapperCol="wrapperCol"
              extra="一句话简述，例如：此服务体验便捷 周期灵活 不容错过"
            >
              <a-input placeholder="请输入服务卖点" v-decorator="['selling_point']" />
            </a-form-item>
            <a-form-item label="服务与承诺" :labelCol="labelCol" :wrapperCol="wrapperCol">
              <a-select
                v-if="formData.serviceList"
                mode="multiple"
                v-decorator="['serviceIds']"
                placeholder="请选择服务与承诺"
              >
                <a-select-option
                  v-for="(item, index) in formData.serviceList"
                  :key="index"
                  :value="item.service_id"
                >{{ item.name }}</a-select-option>
              </a-select>
              <div class="form-item-help">
                <router-link target="_blank" :to="{ path: '/goods/service/index' }">去新增</router-link>
                <a href="javascript:;" @click="onReloadServiceList">刷新</a>
              </div>
            </a-form-item>
            <a-form-item
              label="初始销量"
              :labelCol="labelCol"
              :wrapperCol="wrapperCol"
              extra="用户端展示的销量 = 初始销量 + 实际销量"
            >
              <a-input-number v-decorator="['sales_initial', { initialValue: 0}]" />
            </a-form-item>

            <div v-show="$module('market-points')">
              <a-divider orientation="left">积分设置</a-divider>
              <a-form-item
                label="积分赠送"
                :labelCol="labelCol"
                :wrapperCol="wrapperCol"
                extra="开启后用户购买此服务套餐将获得积分"
              >
                <a-radio-group
                  v-decorator="['is_points_gift', { initialValue: 1, rules: [{ required: true }] }]"
                >
                  <a-radio :value="1">开启</a-radio>
                  <a-radio :value="0">关闭</a-radio>
                </a-radio-group>
              </a-form-item>
              <a-form-item
                label="积分抵扣"
                :labelCol="labelCol"
                :wrapperCol="wrapperCol"
                extra="开启后用户购买此服务套餐可以使用积分进行抵扣"
              >
                <a-radio-group
                  v-decorator="['is_points_discount', { initialValue: 1, rules: [{ required: true }] }]"
                >
                  <a-radio :value="1">开启</a-radio>
                  <a-radio :value="0">关闭</a-radio>
                </a-radio-group>
              </a-form-item>
            </div>

            <div v-show="$module('user-grade')">
              <a-divider orientation="left">会员折扣设置</a-divider>
              <a-form-item
                label="会员折扣"
                :labelCol="labelCol"
                :wrapperCol="wrapperCol"
                extra="开启后会员折扣，会员购买此服务套餐可以享受会员等级折扣价"
              >
                <a-radio-group
                  v-decorator="['is_enable_grade', { initialValue: 1, rules: [{ required: true }] }]"
                  @change="onForceUpdate(true)"
                >
                  <a-radio :value="1">开启</a-radio>
                  <a-radio :value="0">关闭</a-radio>
                </a-radio-group>
              </a-form-item>
              <a-form-item
                v-show="form.getFieldValue('is_enable_grade')"
                label="会员折扣设置"
                :labelCol="labelCol"
                :wrapperCol="wrapperCol"
              >
                <a-radio-group
                  v-decorator="['is_alone_grade', { initialValue: 0, rules: [{ required: true }] }]"
                  @change="onForceUpdate(true)"
                >
                  <a-radio :value="0">默认等级折扣</a-radio>
                  <a-radio :value="1">单独设置折扣</a-radio>
                </a-radio-group>
                <!-- 会员等级列表 -->
                <div v-show="form.getFieldValue('is_alone_grade')">
                  <a-form-item v-for="item in formData.userGradeList" :key="item.grade_id">
                    <InputNumberGroup
                      :addonBefore="item.name"
                      addonAfter="折"
                      :inputProps="{ min: 0, max: 9.9 }"
                      v-decorator="[`alone_grade_equity[grade_id:${item.grade_id}]`, {
                        initialValue: formData.defaultUserGradeValue[item.grade_id], rules: [{ required: true, message: '折扣率不能为空'}]
                      }]"
                    />
                  </a-form-item>
                </div>
                <div class="form-item-help">
                  <p class="extra" v-if="form.getFieldValue('is_alone_grade')">
                    <span v-if="formData.userGradeList.length">单独折扣：折扣率范围0.0-9.9，例如: 9.8代表98折，0代表不折扣</span>
                    <span v-else class="c-red">当前没有会员等级，请先到 [会员管理] - [会员等级] 中设置</span>
                  </p>
                  <p class="extra" v-else>默认折扣：默认为用户所属会员等级的折扣率</p>
                </div>
              </a-form-item>
            </div>

            <a-divider orientation="left">虚拟支付映射</a-divider>
            <a-form-item
              label="启用虚拟支付"
              :labelCol="labelCol"
              :wrapperCol="wrapperCol"
              extra="仅服务商品、单规格、无需配送商品可启用"
            >
              <a-radio-group
                v-decorator="['vp_enabled', { initialValue: 0, rules: [{ required: true }] }]"
                @change="onVirtualPaymentEnabledChange"
              >
                <a-radio :value="0">关闭</a-radio>
                <a-radio :value="1">开启</a-radio>
              </a-radio-group>
            </a-form-item>
            <template v-if="form.getFieldValue('vp_enabled')">
              <a-form-item
                label="虚拟支付 productId"
                :labelCol="labelCol"
                :wrapperCol="wrapperCol"
                extra="填写微信虚拟支付后台已发布的道具ID；最终以后端按商品价格推导结果为准"
              >
                <a-input
                  placeholder="例如：vip99"
                  @change="onVirtualPaymentManualChange"
                  v-decorator="['vp_product_id', { rules: [{ required: true, message: '请输入虚拟支付 productId' }, { validator: validateVirtualPaymentProductId }] }]"
                />
              </a-form-item>
              <a-form-item
                label="虚拟支付道具名称"
                :labelCol="labelCol"
                :wrapperCol="wrapperCol"
                extra="镜像字段，仅用于后台回显和运营识别，不进入支付核心逻辑"
              >
                <a-input
                  placeholder="例如：vip99"
                  @change="onVirtualPaymentManualChange"
                  v-decorator="['vp_product_name', { rules: [{ required: true, message: '请输入虚拟支付道具名称' }, { validator: validateVirtualPaymentProductName }] }]"
                />
              </a-form-item>
              <a-form-item
                label="平台价格快照"
                :labelCol="labelCol"
                :wrapperCol="wrapperCol"
                extra="单位为分，只读展示，始终与商品价格联动"
              >
                <a-input-number
                  :min="0"
                  :precision="0"
                  disabled
                  v-decorator="['vp_price_snapshot', { rules: [{ required: true, message: '请输入平台价格快照' }, { validator: validateVirtualPaymentPriceSnapshot }] }]"
                />
                <span class="ml-10 form-note">{{ getVirtualPaymentModeText() }}</span>
                <a-button class="ml-10" size="small" @click="onRegenerateVirtualPaymentConfig">按价格重算</a-button>
              </a-form-item>
            </template>
          </div>
        </div>
        <a-form-item class="mt-20" :wrapperCol="{ span: wrapperCol.span, offset: labelCol.span }">
          <a-button type="primary" html-type="submit" :loading="isBtnLoading">提交</a-button>
        </a-form-item>
      </a-form>
    </a-spin>
  </a-card>
</template>

<script>
import * as GoodsApi from '@/api/goods'
import { SelectImage, SelectVideo, Ueditor, InputNumberGroup } from '@/components'
import GoodsModel from '@/common/model/goods/Index'
import { GoodsType, MultiSpec } from './modules'
import virtualPaymentFormMixin from './modules/virtualPaymentFormMixin'
import { isEmptyObject } from '@/utils/util'

export default {
  mixins: [virtualPaymentFormMixin],
  components: {
    GoodsType,
    SelectImage,
    SelectVideo,
    Ueditor,
    InputNumberGroup,
    MultiSpec
  },
  data () {
    return {
      // 默认的标签索引
      tabKey: 0,
      // 标签布局属性
      labelCol: { span: 3 },
      // 输入框布局属性
      wrapperCol: { span: 10 },
      // loading状态
      isLoading: false,
      isBtnLoading: false,
      // 当前表单元素
      form: this.$form.createForm(this),
      // 服务套餐ID
      goodsId: null,
      // 表单数据
      formData: GoodsModel.formData
    }
  },
  created () {
    // 初始化数据
    this.initData()
  },
  beforeDestroy () {
    // 销毁服务套餐详情
    GoodsModel.formData.goods = {}
  },
  methods: {

    // 初始化数据
    initData () {
      // 记录服务套餐ID
      this.goodsId = this.$route.query.goodsId
      // 获取form所需的数据
      this.isLoading = true
      GoodsModel.getFromData(this.goodsId)
        .then(() => {
          // 服务套餐表单数据
          if (!isEmptyObject(this.form.getFieldsValue())) {
            // 第一次赋值
            this.form.setFieldsValue(GoodsModel.getFieldsValue())
            // 第二次赋值 (适用于动态渲染的form-item)
            this.$nextTick(() => {
              this.form.setFieldsValue(GoodsModel.getFieldsValue2())
              this.onForceUpdate()
              this.$nextTick(() => {
                this.initVirtualPaymentState()
              })
            })
          }
          this.isLoading = false
        })
    },

    // 手动强制更新页面
    onForceUpdate (bool = false) {
      this.$forceUpdate()
      // bool为true时再执行一次 $forceUpdate, 特殊情况下需执行两次，原因如下：
      // 第一次执行 $forceUpdate时, 新元素绑定v-decorator无法获取到form.getFieldValue
      bool && setTimeout(() => {
        this.$forceUpdate()
      }, 10)
    },

    // 切换tab选项卡
    handleTabs (key) {
      this.tabKey = key
    },

    // 刷新分类列表
    onReloadCategoryList () {
      this.isLoading = true
      GoodsModel.getCategoryList().then(() => {
        this.isLoading = false
      })
    },

    // 刷新服务与承诺列表
    onReloadServiceList () {
      this.isLoading = true
      GoodsModel.getServiceList().then(() => {
        this.isLoading = false
      })
    },

    // 确认按钮
    handleSubmit (e) {
      e.preventDefault()
      // 表单验证
      const { form: { validateFields } } = this
      validateFields((errors, values) => {
        // 定位到错误的tab选项卡
        if (errors) {
          this.onTargetTabError(errors)
          return false
        }
        // 验证多规格
        if (values.spec_type === 20) {
          const MultiSpec = this.$refs.MultiSpec
          if (!MultiSpec.verifyForm()) {
            this.tabKey = 1
            return false
          }
          // 记录多规格数据
          values.specData = MultiSpec.getFromSpecData()
        }
        // 整理服务分类ID集
        values.categoryIds = values.categorys.map(item => item.value)
        delete values.categorys
        // 提交到后端api
        this.onFormSubmit(values)
        return true
      })
    },

    // 定位到错误的tab选项卡
    onTargetTabError (errors) {
      // 表单字段与tabKey对应关系
      // 只需要必填字段就可
      const tabsFieldsMap = [
        ['goods_type', 'goods_name', 'categorys', 'imagesIds', 'goods_no', 'status', 'sort'],
        ['spec_type', 'goods_price', 'stock_num', 'is_restrict', 'restrict_total', 'restrict_single'],
        ['content'],
        ['alone_grade_equity', 'first_money', 'second_money', 'third_money', 'vp_product_id', 'vp_product_name', 'vp_price_snapshot']
      ]
      const field = Object.keys(errors).shift()
      for (const key in tabsFieldsMap) {
        if (tabsFieldsMap[key].indexOf(field) > -1) {
          this.tabKey = parseInt(key)
          break
        }
      }
    },

    // 提交到后端api
    onFormSubmit (values) {
      this.isLoading = true
      this.isBtnLoading = true
      GoodsApi.edit({ goodsId: this.goodsId, form: values })
        .then(result => {
          // 显示提示信息
          this.$message.success(result.message, 1.5)
          // 跳转到列表页
          setTimeout(() => {
            this.$router.push('./index')
          }, 1500)
        })
        .catch(() => {
          this.isBtnLoading = false
        })
        .finally(() => { this.isLoading = false })
    }

  }
}

</script>
<style lang="less" scoped>
@import './style.less';
</style>
