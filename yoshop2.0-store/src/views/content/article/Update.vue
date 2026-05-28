<template>
  <a-card :bordered="false">
    <div class="card-title">{{ $route.meta.title }}</div>
    <a-spin :spinning="isLoading">
      <a-form :form="form" @submit="handleSubmit" :labelCol="labelCol" :wrapperCol="wrapperCol">
        <a-form-item label="文章标题">
          <a-input
            v-decorator="['title', { rules: [{ required: true, min: 2, message: '请输入至少2个字符' }] }]"
          />
        </a-form-item>
        <a-form-item label="文章分类">
          <a-select
            style="width: 300px"
            v-decorator="['category_id', { initialValue: 0, rules: [{ required: true, message: '请选择文章分类' }] }]"
          >
            <a-select-option :value="0">无分类</a-select-option>
            <a-select-option
              v-for="(item, index) in categoryList"
              :key="index"
              :value="item.category_id"
            >{{ item.name }}</a-select-option>
          </a-select>
        </a-form-item>
        <a-form-item label="列表显示方式">
          <a-radio-group
            v-decorator="['show_type', { initialValue: 10, rules: [{ required: true }] }]"
          >
            <a-radio :value="10">小图模式</a-radio>
            <a-radio :value="20">大图模式</a-radio>
          </a-radio-group>
          <div class="form-item-help">
            <p class="extra">小图模式建议封面图尺寸：300 * 188</p>
            <p class="extra">大图模式建议封面图尺寸：750 * 455</p>
          </div>
        </a-form-item>
        <a-form-item label="封面图">
          <SelectImage
            :defaultList="record.image ? [record.image] : []"
            v-decorator="['image_id', { rules: [{ required: true, message: '请选择1个封面图' }] }]"
          />
        </a-form-item>
        <a-form-item label="文章类型">
          <a-radio-group
            v-decorator="['article_type', { initialValue: 10, rules: [{ required: true }] }]"
          >
            <a-radio :value="10">普通文章</a-radio>
            <a-radio :value="20">跳转公众号文章</a-radio>
          </a-radio-group>
        </a-form-item>
        <a-form-item v-show="form.getFieldValue('article_type') == 10" label="文章内容">
          <Ueditor v-decorator="['content']" />
        </a-form-item>
        <a-form-item v-show="form.getFieldValue('article_type') == 20" label="公众号文章链接">
          <a-input v-decorator="['wxofficial_article_url'] " />
        </a-form-item>
        <a-form-item
          label="虚拟阅读量"
          :labelCol="labelCol"
          :wrapperCol="wrapperCol"
          extra="用户看到的阅读量 = 实际阅读量 + 虚拟阅读量"
        >
          <a-input-number :min="0" v-decorator="['virtual_views', { initialValue: 100 }]" />
        </a-form-item>
        <a-form-item label="状态" extra="用户端是否展示">
          <a-radio-group v-decorator="['status', { initialValue: 1, rules: [{ required: true }] }]">
            <a-radio :value="1">显示</a-radio>
            <a-radio :value="0">隐藏</a-radio>
          </a-radio-group>
        </a-form-item>
        <a-form-item label="排序" extra="数字越小越靠前">
          <a-input-number
            :min="0"
            v-decorator="['sort', { initialValue: 100, rules: [{ required: true, message: '请输入至少1个数字' }] }]"
          />
        </a-form-item>
        <a-form-item class="mt-20" :wrapperCol="{ span: wrapperCol.span, offset: labelCol.span }">
          <a-button type="primary" html-type="submit" :loading="isBtnLoading">提交</a-button>
        </a-form-item>
      </a-form>
    </a-spin>
  </a-card>
</template>

<script>
import pick from 'lodash.pick'
import { SelectImage, Ueditor } from '@/components'
import * as Api from '@/api/content/article'
import * as CategoryApi from '@/api/content/article/category'

export default {
  components: {
    SelectImage,
    Ueditor
  },
  data () {
    return {
      // 正在加载
      isLoading: false,
      isBtnLoading: false,
      // 标签布局属性
      labelCol: { span: 4 },
      // 输入框布局属性
      wrapperCol: { span: 13 },
      // 当前表单元素
      form: this.$form.createForm(this),
      // 分类列表
      categoryList: [],
      // 当前文章ID
      articleId: null,
      // 当前记录详情
      record: {}
    }
  },
  async created () {
    // 记录文章ID
    this.articleId = this.$route.query.articleId
    // 获取文章分类
    await this.getCategoryList()
    // 获取当前记录
    await this.getDetail()
  },
  methods: {

    // 获取当前详情记录
    async getDetail () {
      this.isLoading = true
      await Api.detail(this.articleId)
        .then(result => {
          // 当前记录
          this.record = result.data.detail
          // 设置默认值
          this.setFieldsValue()
        })
        .finally(result => this.isLoading = false)
    },

    // 获取分类列表
    async getCategoryList () {
      this.isLoading = true
      await CategoryApi.list()
        .then(result => this.categoryList = result.data.list)
        .finally(() => this.isLoading = false)
    },

    // 设置默认值
    setFieldsValue () {
      const { form: { setFieldsValue } } = this
      this.$nextTick(() => {
        setFieldsValue(pick(this.record, [
          'title', 'show_type', 'category_id', 'image_id', 'article_type', 'content',
          'wxofficial_article_url', 'sort', 'status', 'virtual_views'
        ]))
      })
    },

    // 确认按钮
    handleSubmit (e) {
      e.preventDefault()
      // 表单验证
      const { form: { validateFields }, onFormSubmit } = this
      validateFields((errors, values) => {
        !errors && onFormSubmit(values)
      })
    },

    // 提交到后端api
    onFormSubmit (values) {
      this.isLoading = true
      this.isBtnLoading = true
      Api.edit(this.articleId, { form: values })
        .then(result => {
          // 显示提示信息
          this.$message.success(result.message, 1.5)
          // 跳转到列表页
          setTimeout(() => this.$router.push('./index'), 1500)
        })
        .catch(() => this.isBtnLoading = false)
        .finally(() => this.isLoading = false)
    }

  }
}
</script>
<style lang="less" scoped>
.ant-form-item {
  .ant-form-item {
    margin-bottom: 0;
  }
}

/deep/.ant-form-item-control {
  padding-left: 10px;
  .ant-form-item-control {
    padding-left: 0;
  }
}
</style>
