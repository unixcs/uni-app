<template>
  <a-modal
    :title="title"
    :width="760"
    :visible="visible"
    :maskClosable="false"
    :confirmLoading="isLoading"
    @ok="handleSubmit"
    @cancel="handleCancel"
  >
    <a-spin :spinning="isLoading">
      <a-form v-if="visible" :form="form">
        <a-form-item label="反馈ID" :labelCol="labelCol" :wrapperCol="wrapperCol">
          <span>{{ detail.feedback_id || '--' }}</span>
        </a-form-item>
        <a-form-item label="提交用户" :labelCol="labelCol" :wrapperCol="wrapperCol">
          <span>{{ detail.user ? detail.user.nick_name : '--' }}</span>
          <span v-if="detail.user && detail.user.mobile" class="meta-text">（{{ detail.user.mobile }}）</span>
        </a-form-item>
        <a-form-item label="问题类型" :labelCol="labelCol" :wrapperCol="wrapperCol">
          <a-tag :color="Number(detail.issue_type) === 60 ? 'red' : 'blue'">{{ detail.issue_type_text || '--' }}</a-tag>
        </a-form-item>
        <a-form-item label="提交时间" :labelCol="labelCol" :wrapperCol="wrapperCol">
          <span>{{ detail.create_time || '--' }}</span>
        </a-form-item>
        <a-form-item label="联系方式" :labelCol="labelCol" :wrapperCol="wrapperCol">
          <span>{{ detail.mobile || '--' }}</span>
        </a-form-item>
        <a-form-item label="反馈内容" :labelCol="labelCol" :wrapperCol="wrapperCol">
          <div class="detail-text">{{ detail.content || '--' }}</div>
        </a-form-item>
        <a-form-item label="凭证图片" :labelCol="labelCol" :wrapperCol="wrapperCol">
          <div v-if="detail.images && detail.images.length" class="image-list">
            <img v-for="item in detail.images" :key="item.file_id" :src="item.preview_url" class="preview-image" @click="handlePreview(item.preview_url)">
          </div>
          <div v-else class="empty-text">无</div>
        </a-form-item>
        <a-form-item label="处理状态" :labelCol="labelCol" :wrapperCol="wrapperCol">
          <a-select v-decorator="['status', { rules: [{ required: true, message: '请选择处理状态' }] }]" @change="handleStatusChange">
            <a-select-option v-for="item in availableStatusOptions" :key="item.value" :value="item.value">{{ item.label }}</a-select-option>
          </a-select>
        </a-form-item>
        <a-form-item label="官方回复" :labelCol="labelCol" :wrapperCol="wrapperCol" extra="仅“已回复”状态可编辑官方回复；已回复记录仅允许继续保持已回复或关闭">
          <a-textarea :autoSize="{ minRows: 4, maxRows: 8 }" :disabled="selectedStatus !== 30" :placeholder="selectedStatus === 30 ? '请填写官方回复' : '当前状态不可编辑官方回复'" v-decorator="['reply_content']" />
        </a-form-item>
        <a-form-item label="回复时间" :labelCol="labelCol" :wrapperCol="wrapperCol">
          <span>{{ detail.reply_time || '--' }}</span>
        </a-form-item>
      </a-form>
    </a-spin>
  </a-modal>
</template>

<script>
import * as Api from '@/api/content/feedback'

const statusOptions = [
  { value: 10, label: '待处理' },
  { value: 20, label: '处理中' },
  { value: 30, label: '已回复' },
  { value: 40, label: '已关闭' }
]

export default {
  data () {
    return {
      title: '反馈/投诉处理',
      labelCol: { span: 6 },
      wrapperCol: { span: 15 },
      visible: false,
      isLoading: false,
      form: this.$form.createForm(this),
      record: {},
      detail: {},
      selectedStatus: 10,
      statusOptions
    }
  },
  computed: {
    availableStatusOptions () {
      const hasReplyHistory = !!((this.detail && this.detail.reply_content) || Number(this.detail && this.detail.reply_time || 0) > 0 || Number(this.detail && this.detail.status || 0) === 30)
      if (!hasReplyHistory) {
        return this.statusOptions
      }
      return this.statusOptions.filter(item => item.value === 30 || item.value === 40)
    }
  },
  methods: {
    show (record) {
      this.visible = true
      this.record = record
      this.detail = {}
      this.loadDetail()
    },
    loadDetail () {
      this.isLoading = true
      Api.detail({ feedbackId: this.record.feedback_id })
        .then(result => {
          this.detail = result.data.detail || {}
          this.selectedStatus = Number(this.detail.status || 10)
          this.$nextTick(() => {
            this.form.setFieldsValue({
              status: this.selectedStatus,
              reply_content: this.detail.reply_content || ''
            })
          })
        })
        .finally(() => {
          this.isLoading = false
        })
    },
    handleSubmit (e) {
      e.preventDefault()
      this.form.validateFields((errors, values) => {
        !errors && this.onFormSubmit(values)
      })
    },
    handleCancel () {
      this.visible = false
      this.isLoading = false
      this.record = {}
      this.detail = {}
      this.selectedStatus = 10
      this.form.resetFields()
    },
    handleStatusChange (value) {
      this.selectedStatus = Number(value || 10)
    },
    onFormSubmit (values) {
      this.isLoading = true
      Api.edit({ feedbackId: this.record.feedback_id, form: values })
        .then(result => {
          this.$message.success(result.message, 1.5)
          this.handleCancel()
          this.$emit('handleSubmit', values)
        })
        .finally(() => {
          this.isLoading = false
        })
    },
    handlePreview (url) {
      window.open(url)
    }
  }
}
</script>

<style lang="less" scoped>
.meta-text {
  color: #999;
}
.detail-text {
  white-space: pre-wrap;
  line-height: 1.8;
}
.image-list {
  display: flex;
  flex-wrap: wrap;
}
.preview-image {
  width: 88px;
  height: 88px;
  margin-right: 10px;
  margin-bottom: 10px;
  border-radius: 4px;
  cursor: pointer;
  object-fit: cover;
  border: 1px solid #eee;
}
.empty-text {
  color: #999;
}
</style>
