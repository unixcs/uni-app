<template>
  <view class="container">
    <view v-if="isLoading" class="loading">
      <u-loading mode="circle"></u-loading>
    </view>
    <view v-else class="field-body" @click="handleSelect()">
      <view class="field-value oneline-hide">{{ valueText ? valueText: placeholder }}</view>
    </view>
    <u-select v-model="show" mode="mutil-column-auto" :list="options" :default-value="defaultValue" @confirm="onConfirm"></u-select>
  </view>
</template>

<script>
  import Emitter from '@/uni_modules/vk-uview-ui/libs/util/emitter'
  import { isEmpty } from '@/utils/util'
  import RegionModel from '@/common/model/Region'

  // 根据选中的value集获取索引集keys
  // 用于设置默认选中
  const findOptionsKey = (data, searchValue, deep = 1, keys = []) => {
    const index = data.findIndex(item => item.value === searchValue[deep - 1])
    if (index > -1) {
      keys.push(index)
      if (data[index].children) {
        findOptionsKey(data[index].children, searchValue, ++deep, keys)
      }
    }
    return keys
  }

  export default {
    name: 'SelectRegion',
    mixins: [Emitter],
    emits: ['update:modelValue'],
    props: {
      // 当前选中的值
      modelValue: {
        type: Array,
        default: () => {
          return []
        }
      },
      // 未选中时的提示文字
      placeholder: {
        type: String,
        default: '请选择省/市/区'
      }
    },
    data() {
      return {
        // 正在加载
        isLoading: true,
        // 是否显示
        show: false,
        // 默认选中的值
        defaultValue: [],
        // 选中项内容(文本展示)
        valueText: '',
        // 级联选择器数据
        options: []
      }
    },
    watch: {
      // 监听当前选中的值
      modelValue(val) {
        // 设置默认选中的值
        this.valueText = val.map(item => item.label).join('/')
        this.setDefaultValue(val)
        // 将当前的值发送到 u-form-item 进行校验
        // this.dispatch('u-form-item', 'on-form-change', val)
      },
    },
    created() {
      // 获取地区数据 (同步)
      this.getTreeDataSync()
    },

    methods: {

      // 打开选择器
      handleSelect() {
        this.show = true
      },

      // // 获取地区数据 (异步)
      // getTreeData() {
      //   const app = this
      //   app.isLoading = true
      //   RegionModel.getTreeData()
      //     .then(regions => {
      //       // 格式化级联选择器数据
      //       app.options = app.getOptions(regions)
      //       app.setDefaultValue(app.modelValue)
      //     })
      //     .finally(() => app.isLoading = false)
      // },

      // 获取地区数据 (同步)
      getTreeDataSync() {
        const app = this
        const regions = RegionModel.getTreeDataSync()
        app.options = app.getOptions(regions)
        app.setDefaultValue(app.modelValue)
        app.isLoading = false
      },

      // 确认选择后的回调
      onConfirm(value) {
        // 记录选中的值
        this.$emit('update:modelValue', value)
      },

      /**
       * 设置默认选中的值
       * 该操作是为了每次打开选择器时聚焦到上次选择
       * @param {Object} value
       */
      setDefaultValue(value) {
        const options = this.options
        const values = value.map(item => item.value)
        this.defaultValue = options.length ? findOptionsKey(options, values) : []
      },

      /**
       * 格式化级联选择器数据
       * @param {*} regions 地区数据
       */
      getOptions(regions) {
        const { getOptions, getChildren } = this
        const options = []
        for (const index in regions) {
          const item = regions[index]
          const children = getChildren(item)
          const optionItem = {
            value: item.id,
            label: item.name
          }
          if (children !== false) {
            optionItem.children = getOptions(children)
          }
          options.push(optionItem)
        }
        return options
      },

      // 获取子集地区
      getChildren(item) {
        if (item.city) {
          return item.city
        }
        if (item.region) {
          return item.region
        }
        return false
      },

      // 根据省市区名称获取optionItem
      getOptionItemByNames({ provinceName, cityName, countyName }) {
        const app = this
        const data = []
        app.options.forEach(provinceItem => {
          if (provinceItem.label == provinceName || `${provinceItem.label}市` == provinceName) {
            data.push({ label: provinceItem.label, value: provinceItem.value })
            provinceItem.children.forEach(cityItem => {
              if (cityItem.label == cityName) {
                data.push({ label: cityItem.label, value: cityItem.value })
                cityItem.children.forEach(countyItem => {
                  if (countyItem.label == countyName) {
                    data.push({ label: countyItem.label, value: countyItem.value })
                  }
                })
              }
            })
          }
        })
        return data
      },

    }
  }
</script>

<style lang="scss" scoped>
  .container {
    width: 100%;
  }

  .loading {
    padding-left: 10rpx;
    // text-align: center;
  }
</style>