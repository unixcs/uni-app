<template>
  <a-card :bordered="false">
    <div class="card-title">主题风格</div>
    <a-spin :spinning="isLoading">
      <div class="container">
        <!-- 表单内容 -->
        <div class="form-box">
          <a-form-model ref="myForm" class="my-form" :labelCol="labelCol" :wrapperCol="wrapperCol">
            <a-form-model-item label="主题色系">
              <a-radio-group v-model="record.mode">
                <a-radio :value="10">系统推荐</a-radio>
                <a-radio :value="20">自定义</a-radio>
              </a-radio-group>
            </a-form-model-item>

            <a-form-model-item v-if="record.mode == 10" label="选择色系">
              <div class="color-radio-group">
                <div
                  class="color-radio-item"
                  v-for="(item, index) in themeTemplate"
                  :key="index"
                  :style="{ backgroundColor: item.mainBg }"
                  @click="handleColorRadio(index, item)"
                >
                  <a-icon v-if="record.themeTemplateIdx === index" type="check" />
                </div>
              </div>
            </a-form-model-item>

            <div v-if="record.mode == 20">
              <a-form-model-item label="按钮颜色渐变">
                <a-radio-group v-model="record.data.gradualChange" @change="onGradualChangeData()">
                  <a-radio :value="1">开启</a-radio>
                  <a-radio :value="0">关闭</a-radio>
                </a-radio-group>
              </a-form-model-item>
              <a-form-model-item label="主背景">
                <div class="color-picker-row">
                  <MyColorPicker v-model="record.data.mainBg" horizontal="left" />
                  <MyColorPicker
                    v-if="record.data.gradualChange"
                    v-model="record.data.mainBg2"
                    horizontal="left"
                  />
                </div>
              </a-form-model-item>
              <a-form-model-item label="主文字">
                <div class="color-picker-row">
                  <MyColorPicker v-model="record.data.mainText" horizontal="left" />
                </div>
              </a-form-model-item>
              <a-form-model-item label="副背景">
                <div class="color-picker-row">
                  <MyColorPicker v-model="record.data.viceBg" horizontal="left" />
                  <MyColorPicker
                    v-if="record.data.gradualChange"
                    v-model="record.data.viceBg2"
                    horizontal="left"
                  />
                </div>
              </a-form-model-item>
              <a-form-model-item label="副文字">
                <div class="color-picker-row">
                  <MyColorPicker v-model="record.data.viceText" horizontal="left" />
                </div>
              </a-form-model-item>
            </div>
            <a-form-model-item :wrapperCol="{ offset: labelCol.span }">
              <a-button type="primary" :loading="confirmLoading" @click="handleSubmit">保存</a-button>
            </a-form-model-item>
          </a-form-model>
        </div>
        <!-- 模板预览 -->
        <div class="preview" :style="appThemeStyle">
          <div class="example-item">
            <div class="example-item-scale">
              <img class="bg-image" src="static/img/theme/10.png" />
              <div class="content">
                <span class="goods-price">
                  <span class="unit">￥</span>
                  <span class="value">4969.00</span>
                </span>
                <div class="btn-wrapper">
                  <div class="btn-item btn-item-deputy">
                    <span>加入购物车</span>
                  </div>
                  <div class="btn-item btn-item-main">
                    <span>立即购买</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
          <div class="example-item">
            <div class="example-item-scale">
              <img class="bg-image" src="static/img/theme/20.png" />
              <div class="content">
                <span class="goods-price">
                  <span class="unit">￥</span>
                  <span class="value">4969.00</span>
                </span>
                <div class="item-content">
                  <span>墨影灰</span>
                </div>
                <div class="btn-wrapper">
                  <div class="btn-item btn-item-main">
                    <span>立即购买</span>
                  </div>
                </div>
              </div>
            </div>
          </div>
          <div class="example-item">
            <div class="example-item-scale">
              <img class="bg-image" src="static/img/theme/30.png" />
              <div class="content">
                <div class="swiper-tab">
                  <div class="swiper-tab-item">快递配送</div>
                </div>
                <div class="flow-cont1">￥4969.00</div>
                <div class="flow-cont2">￥4969.00</div>
                <div class="flow-cont3">+￥0.00</div>
                <div class="flow-cont4">￥4969.00</div>
                <div class="flow-btn">提交订单</div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </a-spin>
  </a-card>
</template>

<script>
import Vue from 'vue'
import { cloneDeep } from 'lodash'
import * as Api from '@/api/setting/store'
import { SettingEnum } from '@/common/enum/store'

// 默认数据
const defaultData = {
  mode: 10,
  themeTemplateIdx: -1,
  data: {
    gradualChange: 0,
    mainBg: '#fa2209',
    mainBg2: '#ff6335',
    mainText: '#ffffff',
    viceBg: '#ffb100',
    viceBg2: '#ffb900',
    viceText: '#ffffff',
  }
}

// 系统推荐主题
const themeTemplate = [
  {
    mainBg: '#fa2209',
    mainBg2: '#ff6335',
    mainText: '#ffffff',
    viceBg: '#ffb100',
    viceBg2: '#ffb900',
    viceText: '#ffffff',
    gradualChange: 1,
  },
  {
    mainBg: '#ff547b',
    mainBg2: '#ff547b',
    mainText: '#ffffff',
    viceBg: '#FFE6E8',
    viceBg2: '#FFE6E8',
    viceText: '#ff547b',
    gradualChange: 0,
  },
  {
    mainBg: '#63be72',
    mainBg2: '#63be72',
    mainText: '#ffffff',
    viceBg: '#E1F4E3',
    viceBg2: '#E1F4E3',
    viceText: '#50be58',
    gradualChange: 0,
  },
  {
    mainBg: '#c3a769',
    mainBg2: '#c3a769',
    mainText: '#ffffff',
    viceBg: '#F3EEE1',
    viceBg2: '#F3EEE1',
    viceText: '#C3A769',
    gradualChange: 0,
  },
  {
    mainBg: '#2f2f34',
    mainBg2: '#2f2f34',
    mainText: '#ffffff',
    viceBg: '#EBECF2',
    viceBg2: '#EBECF2',
    viceText: '#2F2F34',
    gradualChange: 0,
  },
  {
    mainBg: '#884cff',
    mainBg2: '#884cff',
    mainText: '#ffffff',
    viceBg: '#EFE6FF',
    viceBg2: '#EFE6FF',
    viceText: '#884cff',
    gradualChange: 0,
  },
  {
    mainBg: '#65c4aa',
    mainBg2: '#65c4aa',
    mainText: '#ffffff',
    viceBg: '#D9F6EF',
    viceBg2: '#D9F6EF',
    viceText: '#65c4aa',
    gradualChange: 0,
  },
  {
    mainBg: '#FCC600',
    mainBg2: '#FCC600',
    mainText: '#ffffff',
    viceBg: '#1D262E',
    viceBg2: '#1D262E',
    viceText: '#ffffff',
    gradualChange: 0,
  },
  {
    mainBg: '#4a90e2',
    mainBg2: '#4a90e2',
    mainText: '#ffffff',
    viceBg: '#D6E9FC',
    viceBg2: '#D6E9FC',
    viceText: '#0080FF',
    gradualChange: 0,
  }
]

// 字符串驼峰转中划线
const formatToLine = value => {
  return value.replace(/([A-Z])/g, '-$1').toLowerCase()
}

export default {
  components: {},
  data () {
    return {
      // 当前设置项的key
      key: SettingEnum.APP_THEME.value,
      // 标签布局属性
      labelCol: { span: 2 },
      // 输入框布局属性
      wrapperCol: { span: 22 },
      // 加载状态
      isLoading: false,
      confirmLoading: false,
      // 系统推荐主题
      themeTemplate,
      // 当前记录详情
      record: cloneDeep(defaultData),
    }
  },
  computed: {
    appThemeStyle () {
      const appTheme = this.record.data
      const styleObj = {}
      for (const index in appTheme) {
        const name = formatToLine(index)
        styleObj[`--${name}`] = appTheme[index]
      }
      return styleObj
    }
  },
  // 初始化数据
  created () {
    // 获取当前详情记录
    this.getDetail()
  },
  methods: {

    // 获取当前详情记录
    getDetail () {
      this.isLoading = true
      Api.detail(this.key)
        .then(result => this.record = result.data.values)
        .finally(() => this.isLoading = false)
    },

    // 点击切换主题
    handleColorRadio (index, item) {
      this.record.themeTemplateIdx = index
      this.record.data = cloneDeep(item)
    },

    // 刷新渐变色
    onGradualChangeData () {
      if (!this.record.data.gradualChange) {
        this.record.data.mainBg2 = this.record.data.mainBg
        this.record.data.viceBg2 = this.record.data.viceBg
      }
    },

    // 确认按钮
    handleSubmit (e) {
      this.confirmLoading = true
      Api.update(this.key, { form: this.record })
        .then(result => this.$message.success(result.message, 1.5))
        .finally(result => this.confirmLoading = false)
    },

  }
}
</script>
<style lang="less" scoped>
/deep/.ant-form-item-control {
  padding-left: 10px;
  .ant-form-item-control {
    padding-left: 0;
  }
}

// 内容区
.container {
  width: 1300px;
  padding-left: 80px;

  .preview {
    display: flex;
    transform-origin: 50% 0;

    .example-item {
      margin-right: 30px;
      position: relative;
      width: 275px;

      .example-item-scale {
        width: 375px;
        transform: scale(0.74);
        transform-origin: 0 0;
      }

      .bg-image {
        display: block;
        width: 100%;
        box-shadow: 0 3px 10px #dcdcdc;
      }

      &:nth-child(1) {
        .goods-price {
          position: absolute;
          bottom: 167px;
          left: 13px;
          color: var(--main-bg);
          .unit {
            font-size: 13px;
          }
          .value {
            font-size: 21px;
          }
        }
        .btn-wrapper {
          position: absolute;
          bottom: 7px;
          right: 0px;
          display: flex;
          align-items: center;

          .btn-item {
            width: 113px;
            font-size: 14px;
            height: 36px;
            margin-right: 8px;
            color: #fff;
            border-radius: 25px;
            display: flex;
            justify-content: center;
            align-items: center;

            &.btn-item-deputy {
              background: linear-gradient(to right, var(--vice-bg), var(--vice-bg2));
              color: var(--vice-text);
            }

            &.btn-item-main {
              background: linear-gradient(to right, var(--main-bg), var(--main-bg2));
              color: var(--main-text);
            }
          }
        }
      }

      &:nth-child(2) {
        .goods-price {
          position: absolute;
          bottom: 250px;
          left: 111px;
          color: var(--main-bg);
          .unit {
            font-size: 14px;
          }
          .value {
            font-size: 24px;
          }
        }
        .item-content {
          position: absolute;
          bottom: 128px;
          left: 12px;
          padding: 4px 16px;
          font-size: 12px;
          border-radius: 5px;
          border: 1px solid #f4f4f4;
          border-color: var(--main-bg);
          color: var(--main-bg);
          // TODO: 需要计算一下
          background-color: #ffffff;
        }
        .btn-wrapper {
          position: absolute;
          bottom: 10px;
          right: 0px;
          padding: 0 20px;
          width: 100%;

          .btn-item {
            margin: 0 auto;
            width: 100%;
            max-width: 600px;
            height: 40px;
            border-radius: 19px;
            color: #fff;
            font-weight: 500;
            font-size: 14px;
            background: #fe560a;
            display: flex;
            justify-content: center;
            align-items: center;

            &.btn-item-main {
              background: linear-gradient(to right, var(--main-bg), var(--main-bg2));
              color: var(--main-text);
            }
          }
        }
      }

      &:nth-child(3) {
        .swiper-tab {
          position: absolute;
          top: 76px;
          left: 28px;
          width: 132px;
          height: 39px;
          font-size: 14px;
          display: flex;
          justify-content: center;
          align-items: center;
          color: var(--main-bg);
          border-bottom: 2px solid var(--main-bg);
        }

        .flow-cont1,
        .flow-cont2,
        .flow-cont3,
        .flow-cont4 {
          position: absolute;
          color: var(--main-bg);
        }

        .flow-cont1 {
          top: 279px;
          right: 14px;
          font-size: 14px;
        }

        .flow-cont2 {
          top: 374px;
          right: 12px;
        }

        .flow-cont3 {
          top: 452px;
          right: 12px;
        }

        .flow-cont4 {
          bottom: 13px;
          left: 64px;
        }

        .flow-btn {
          bottom: 0;
          right: 0;
          position: absolute;
          width: 121px;
          height: 46px;
          text-align: center;
          font-size: 14px;
          line-height: 46px;
          background: linear-gradient(to right, var(--main-bg), var(--main-bg2));
          color: var(--main-text);
        }
      }
    }
  }

  .form-box {
    margin-bottom: 40px;
    margin-left: -42px;
  }
}

// 主题选择器
.color-radio-group {
  height: 39.9999px;
  display: flex;
  align-items: center;
  flex-wrap: wrap;

  .color-radio-item {
    width: 28px;
    height: 28px;
    border-radius: 4px;
    margin-right: 12px;
    margin-bottom: 12px;
    cursor: pointer;
    color: #fff;
    font-size: 16px;
    display: flex;
    align-items: center;
    justify-content: center;
  }
}

// 颜色选择组件
.color-picker-row {
  margin-top: 5px;
  display: flex;
  align-items: center;
  .color-picker {
    margin-right: 10px;
  }
}
</style>
