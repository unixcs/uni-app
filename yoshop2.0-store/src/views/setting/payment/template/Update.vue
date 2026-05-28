<template>
  <a-card :bordered="false">
    <div class="card-title">{{ $route.meta.title }}</div>
    <a-spin :spinning="isLoading">
      <a-form-model
        ref="myForm"
        class="my-form"
        :model="record"
        :label-col="labelCol"
        :wrapperCol="wrapperCol"
      >
        <a-form-model-item
          label="支付模板名称"
          prop="name"
          :rules="[{ required: true, message: '请填写支付模板名称' }]"
        >
          <a-input v-model="record.name" autocomplete="off" />
          <div class="form-item-help">
            <small>仅用于后台管理使用，对前台用户不可见；例如：H5端-支付宝支付；微信小程序端-微信支付</small>
          </div>
        </a-form-model-item>
        <a-form-model-item label="管理员备注" prop="remarks">
          <a-input v-model="record.remarks" autocomplete="off" />
        </a-form-model-item>
        <a-form-model-item
          class="mb-30"
          label="排序"
          prop="sort"
          :rules="[{ required: true, message: '请填写排序数值' }]"
        >
          <a-input-number v-model="record.sort" :min="0" autocomplete="off" />
          <div class="form-item-help">
            <small>数字越小越靠前</small>
          </div>
        </a-form-model-item>
        <a-form-model-item
          label="支付方式"
          prop="method"
          :rules="[{ required: true, message: '请选择支付方式' }]"
        >
          <a-radio-group v-model="record.method" disabled>
            <a-radio :value="PaymentMethodEnum.WECHAT.value">{{ PaymentMethodEnum.WECHAT.name }}</a-radio>
            <a-radio :value="PaymentMethodEnum.ALIPAY.value">{{ PaymentMethodEnum.ALIPAY.name }}</a-radio>
          </a-radio-group>
          <div class="form-item-help">
            <p v-if="record.method === PaymentMethodEnum.WECHAT.value" class="extra">
              微信支付商户平台：
              <a href="https://pay.weixin.qq.com" target="_blank">https://pay.weixin.qq.com</a>
            </p>
            <p v-if="record.method === PaymentMethodEnum.ALIPAY.value" class="extra">
              支付宝开发者平台：
              <a
                href="https://open.alipay.com/dev/workspace"
                target="_blank"
              >https://open.alipay.com/dev/workspace</a>
            </p>
          </div>
        </a-form-model-item>

        <!-- 微信设置 -->
        <div v-if="record.method === PaymentMethodEnum.WECHAT.value" :method="record.method">
          <a-form-model-item
            label="微信支付接口版本"
            prop="config.wechat.version"
            :rules="[{ required: true, message: '请选择微信支付接口版本' }]"
          >
            <a-radio-group v-model="record.config.wechat.version" @change="clearValidate()">
              <a-radio value="v3">
                <span>V3</span>
                <a-tag class="ml-5" color="green">推荐</a-tag>
              </a-radio>
              <a-radio value="v2">V2</a-radio>
            </a-radio-group>
            <div class="form-item-help">
              <small>V2版本较老已经不再支持新出的API接口，强烈建议使用V3</small>
            </div>
          </a-form-model-item>

          <a-form-model-item
            label="微信商户号类型"
            prop="config.wechat.mchType"
            :rules="[{ required: true, message: '请选择微信商户号类型' }]"
          >
            <a-radio-group v-model="record.config.wechat.mchType" @change="clearValidate()">
              <a-radio value="normal">
                <span>普通商户</span>
                <a-tag class="ml-5" color="green">推荐</a-tag>
              </a-radio>
              <a-radio value="provider">子商户 (服务商模式)</a-radio>
            </a-radio-group>
            <div v-if="record.config.wechat.mchType == 'provider'" class="form-item-help">
              <small class="c-red">注：子商户 (服务商模式) 不支持V3商家转账等接口</small>
            </div>
          </a-form-model-item>

          <div
            v-if="record.config.wechat.mchType === 'normal'"
            :mchType="record.config.wechat.mchType"
          >
            <a-form-model-item
              label="应用ID (AppID)"
              prop="config.wechat.normal.appId"
              :rules="[{ required: true, message: '请填写应用ID (AppID)' }]"
            >
              <a-input v-model="record.config.wechat.normal.appId" autocomplete="off" />
              <div class="form-item-help">
                <small>微信小程序端支付填写小程序APPID，APP支付需要填写开放平台的应用APPID</small>
              </div>
            </a-form-model-item>

            <a-form-model-item
              label="微信商户号 (MchId)"
              prop="config.wechat.normal.mchId"
              :rules="[{ required: true, message: '请填写微信商户号 (MchId)' }]"
            >
              <a-input v-model="record.config.wechat.normal.mchId" autocomplete="off" />
              <div class="form-item-help">
                <small>微信支付的商户号，纯数字格式；例如：1600000109</small>
              </div>
            </a-form-model-item>

            <a-form-model-item
              label="支付密钥 (APIKEY)"
              prop="config.wechat.normal.apiKey"
              :rules="[{ required: true, message: '请填写支付密钥 (APIKEY)' }]"
            >
              <a-input
                type="password"
                v-model="record.config.wechat.normal.apiKey"
                autocomplete="off"
              />
              <div class="form-item-help">
                <small>"微信支付商户平台" - "账户中心" - "API安全" - "设置API密钥"</small>
              </div>
            </a-form-model-item>

            <div v-if="record.config.wechat.version == 'v3'">
              <a-form-model-item
                label="验签方式"
                prop="config.wechat.version"
                :rules="[{ required: true, message: '请选择微信支付接口版本' }]"
              >
                <a-radio-group
                  v-model="record.config.wechat.normal.signatureMethod"
                  @change="clearValidate()"
                >
                  <a-radio value="publicKey">
                    <span>微信支付公钥</span>
                  </a-radio>
                  <a-radio value="platformCert">平台证书</a-radio>
                </a-radio-group>
                <div class="form-item-help">
                  <small>"微信支付商户平台" - "账户中心" - "API安全" - "验证微信支付身份"</small>
                </div>
              </a-form-model-item>

              <div v-if="record.config.wechat.normal.signatureMethod == 'publicKey'">
                <a-form-model-item
                  label="微信支付公钥ID"
                  prop="config.wechat.normal.publicKeyId"
                  :rules="[{ required: true, message: '请填写微信支付公钥ID' }]"
                >
                  <a-input v-model="record.config.wechat.normal.publicKeyId" autocomplete="off" />
                  <div class="form-item-help">
                    <small>"微信支付商户平台" - "账户中心" - "API安全" - "微信支付公钥"；例如：PUB_KEY_ID_0116777777772024080100123400000567</small>
                  </div>
                </a-form-model-item>

                <a-form-model-item
                  label="微信支付公钥 (KEY)"
                  prop="config.wechat.normal.publicKey"
                  :rules="[{ required: true, message: '需要上传该文件' }]"
                >
                  <InputFile
                    accept=".pem"
                    v-model="record.config.wechat.normal.publicKey"
                    @change="onChangeInputFile($event, arguments, 'publicKey')"
                  />
                  <div class="form-item-help">
                    <small>请上传 "pub_key.pem" 文件</small>
                  </div>
                </a-form-model-item>
              </div>
            </div>

            <a-form-model-item
              label="商户API证书 (CERT)"
              prop="config.wechat.normal.apiclientCert"
              :rules="[{ required: true, message: '需要上传该文件' }]"
            >
              <InputFile
                accept=".pem"
                v-model="record.config.wechat.normal.apiclientCert"
                @change="onChangeInputFile($event, arguments, 'apiclientCert')"
              />
              <div class="form-item-help">
                <small>请上传 "apiclient_cert.pem" 文件</small>
              </div>
            </a-form-model-item>

            <a-form-model-item
              label="商户API证书 (KEY)"
              prop="config.wechat.normal.apiclientKey"
              :rules="[{ required: true, message: '需要上传该文件' }]"
            >
              <InputFile
                accept=".pem"
                v-model="record.config.wechat.normal.apiclientKey"
                @change="onChangeInputFile($event, arguments, 'apiclientKey')"
              />
              <div class="form-item-help">
                <small>请上传 "apiclient_key.pem" 文件</small>
              </div>
            </a-form-model-item>
          </div>

          <div
            v-if="record.config.wechat.mchType === 'provider'"
            :mchType="record.config.wechat.mchType"
          >
            <a-form-model-item
              label="服务商应用ID (AppID)"
              prop="config.wechat.provider.spAppId"
              :rules="[{ required: true, message: '请填写服务商应用ID (AppID)' }]"
            >
              <a-input v-model="record.config.wechat.provider.spAppId" autocomplete="off" />
              <div class="form-item-help">
                <small>请填写微信支付服务商的AppID</small>
              </div>
            </a-form-model-item>

            <a-form-model-item
              label="服务商户号 (MchId)"
              prop="config.wechat.provider.spMchId"
              :rules="[{ required: true, message: '请填写服务商户号 (MchId)' }]"
            >
              <a-input v-model="record.config.wechat.provider.spMchId" autocomplete="off" />
              <div class="form-item-help">
                <small>微信支付服务商的商户号，纯数字格式；例如：1600000109</small>
              </div>
            </a-form-model-item>

            <a-form-model-item
              label="服务商密钥 (APIKEY)"
              prop="config.wechat.provider.spApiKey"
              :rules="[{ required: true, message: '请填写服务商密钥 (APIKEY)' }]"
            >
              <a-input
                type="password"
                v-model="record.config.wechat.provider.spApiKey"
                autocomplete="off"
              />
              <div class="form-item-help">
                <small>"微信支付合作伙伴平台" - "账户中心" - "账户设置" - "API安全" - "设置API密钥"</small>
              </div>
            </a-form-model-item>

            <a-form-model-item
              label="子商户应用ID (AppID)"
              prop="config.wechat.provider.subAppId"
              :rules="[{ required: true, message: '请填写子商户应用ID (AppID)' }]"
            >
              <a-input v-model="record.config.wechat.provider.subAppId" autocomplete="off" />
              <div class="form-item-help">
                <small>微信小程序或者微信公众号的APPID，需要在哪个客户端支付就填写哪个，APP支付需要填写开放平台的应用APPID</small>
              </div>
            </a-form-model-item>

            <a-form-model-item
              label="子商户号 (MchId)"
              prop="config.wechat.provider.subMchId"
              :rules="[{ required: true, message: '请填写子商户号 (MchId)' }]"
            >
              <a-input v-model="record.config.wechat.provider.subMchId" autocomplete="off" />
              <div class="form-item-help">
                <small>微信支付的商户号，纯数字格式；例如：1600000109</small>
              </div>
            </a-form-model-item>

            <div v-if="record.config.wechat.version == 'v3'">
              <a-form-model-item
                label="验签方式"
                prop="config.wechat.version"
                :rules="[{ required: true, message: '请选择微信支付接口版本' }]"
              >
                <a-radio-group
                  v-model="record.config.wechat.provider.spSignatureMethod"
                  @change="clearValidate()"
                >
                  <a-radio value="publicKey">
                    <span>微信支付公钥</span>
                  </a-radio>
                  <a-radio value="platformCert">平台证书</a-radio>
                </a-radio-group>
                <div class="form-item-help">
                  <small>"微信支付商户平台" - "账户中心" - "API安全" - "验证微信支付身份"</small>
                </div>
              </a-form-model-item>

              <div v-if="record.config.wechat.provider.spSignatureMethod == 'publicKey'">
                <a-form-model-item
                  label="服务商微信支付公钥ID"
                  prop="config.wechat.provider.spPublicKeyId"
                  :rules="[{ required: true, message: '请填写微信支付公钥ID' }]"
                >
                  <a-input
                    v-model="record.config.wechat.provider.spPublicKeyId"
                    autocomplete="off"
                  />
                  <div class="form-item-help">
                    <small>"微信支付合作伙伴平台" - "账户中心" - "账户设置" - "API安全" - "微信支付公钥"；例如：PUB_KEY_ID_0116777777772024080100123400000567</small>
                  </div>
                </a-form-model-item>

                <a-form-model-item
                  label="服务商微信支付公钥 (KEY)"
                  prop="config.wechat.provider.spPublicKey"
                  :rules="[{ required: true, message: '需要上传该文件' }]"
                >
                  <InputFile
                    accept=".pem"
                    v-model="record.config.wechat.provider.spPublicKey"
                    @change="onChangeInputFile($event, arguments, 'spPublicKey')"
                  />
                  <div class="form-item-help">
                    <small>请上传 "pub_key.pem" 文件</small>
                  </div>
                </a-form-model-item>
              </div>
            </div>

            <a-form-model-item
              label="服务商API证书 (CERT)"
              prop="config.wechat.provider.spApiclientCert"
              :rules="[{ required: true, message: '需要上传该文件' }]"
            >
              <InputFile
                accept=".pem"
                v-model="record.config.wechat.provider.spApiclientCert"
                @change="onChangeInputFile($event, arguments, 'spApiclientCert')"
              />
              <div class="form-item-help">
                <small>请上传 "apiclient_cert.pem" 文件</small>
              </div>
            </a-form-model-item>

            <a-form-model-item
              label="服务商API证书 (KEY)"
              prop="config.wechat.provider.spApiclientKey"
              :rules="[{ required: true, message: '需要上传该文件' }]"
            >
              <InputFile
                accept=".pem"
                v-model="record.config.wechat.provider.spApiclientKey"
                @change="onChangeInputFile($event, arguments, 'spApiclientKey')"
              />
              <div class="form-item-help">
                <small>请上传 "apiclient_key.pem" 文件</small>
              </div>
            </a-form-model-item>
          </div>
        </div>

        <!-- 支付宝设置 -->
        <div v-if="record.method === PaymentMethodEnum.ALIPAY.value" :method="record.method">
          <a-form-model-item
            label="支付宝应用 (AppID)"
            prop="config.alipay.appId"
            :rules="[{ required: true, message: '请填写支付宝应用 (AppID)' }]"
          >
            <a-input v-model="record.config.alipay.appId" autocomplete="off" />
            <div class="form-item-help">
              <small>支付宝分配给开发者的应用ID；例如：2021072300007148</small>
            </div>
          </a-form-model-item>

          <a-form-model-item
            label="签名算法 (signType)"
            prop="config.alipay.signType"
            :rules="[{ required: true, message: '请选择签名算法 (signType)' }]"
          >
            <a-radio-group v-model="record.config.alipay.signType">
              <a-radio value="RSA2">RSA2</a-radio>
              <a-radio value="RSA" :disabled="true">RSA</a-radio>
            </a-radio-group>
          </a-form-model-item>

          <a-form-model-item
            label="加签模式"
            prop="config.alipay.signMode"
            :rules="[{ required: true, message: '请选择加签模式' }]"
          >
            <a-radio-group v-model="record.config.alipay.signMode">
              <a-radio :value="10">
                <span>公钥证书</span>
                <a-tag class="ml-5" color="green">推荐</a-tag>
              </a-radio>
              <a-radio :value="20">公钥</a-radio>
            </a-radio-group>
            <div class="form-item-help">
              <small>如需使用资金支出类的接口，则必须使用公钥证书模式</small>
            </div>
          </a-form-model-item>

          <div v-if="record.config.alipay.signMode === 20" :method="record.method">
            <a-form-model-item
              label="支付宝公钥 (alipayPublicKey)"
              prop="config.alipay.alipayPublicKey"
              :rules="[{ required: true, message: '请填写支付宝公钥 (alipayPublicKey)' }]"
            >
              <a-textarea
                v-model="record.config.alipay.alipayPublicKey"
                :autoSize="{ minRows: 4, maxRows: 6 }"
                autocomplete="off"
              />
              <div class="form-item-help">
                <small>可在 "支付宝开放平台" - "应用信息" - "接口加签方式" - "支付宝公钥" 中复制</small>
              </div>
            </a-form-model-item>
          </div>

          <div v-if="record.config.alipay.signMode ===10" :method="record.method">
            <a-form-model-item
              label="应用公钥证书"
              prop="config.alipay.appCertPublicKey"
              :rules="[{ required: true, message: '需要上传该文件' }]"
            >
              <InputFile
                accept=".crt"
                v-model="record.config.alipay.appCertPublicKey"
                @change="onChangeInputFile($event, arguments, 'appCertPublicKey')"
              />
              <div class="form-item-help">
                <small>请上传 "appCertPublicKey_xxxxxxxx.crt" 文件</small>
              </div>
            </a-form-model-item>

            <a-form-model-item
              label="支付宝公钥证书"
              prop="config.alipay.alipayCertPublicKey"
              :rules="[{ required: true, message: '需要上传该文件' }]"
            >
              <InputFile
                accept=".crt"
                v-model="record.config.alipay.alipayCertPublicKey"
                @change="onChangeInputFile($event, arguments, 'alipayCertPublicKey')"
              />
              <div class="form-item-help">
                <small>请上传 "alipayCertPublicKey_RSA2.crt" 文件</small>
              </div>
            </a-form-model-item>

            <a-form-model-item
              label="支付宝根证书"
              prop="config.alipay.alipayRootCert"
              :rules="[{ required: true, message: '需要上传该文件' }]"
            >
              <InputFile
                accept=".crt"
                v-model="record.config.alipay.alipayRootCert"
                @change="onChangeInputFile($event, arguments, 'alipayRootCert')"
              />
              <div class="form-item-help">
                <small>请上传 "alipayRootCert.crt" 文件</small>
              </div>
            </a-form-model-item>
          </div>

          <a-form-model-item
            label="应用私钥 (privateKey)"
            prop="config.alipay.merchantPrivateKey"
            :rules="[{ required: true, message: '请填写应用私钥 (privateKey)' }]"
          >
            <a-textarea
              v-model="record.config.alipay.merchantPrivateKey"
              :autoSize="{ minRows: 4, maxRows: 6 }"
              autocomplete="off"
            />
            <div class="form-item-help">
              <small>查看 "应用私钥RSA2048-敏感数据，请妥善保管.txt" 文件，将全部内容复制到此处</small>
            </div>
          </a-form-model-item>
        </div>

        <a-form-model-item :wrapperCol="{ offset: labelCol.span }">
          <a-button type="primary" :loading="isBtnLoading" @click="handleSubmit">保存</a-button>
        </a-form-model-item>
      </a-form-model>
    </a-spin>
  </a-card>
</template>

<script>
import { cloneDeep } from 'lodash'
import { assignFormData } from '@/utils/util'
import InputFile from '@/components/InputFile'
import * as Api from '@/api/setting/payment/template'
import { PaymentMethodEnum } from '@/common/enum/payment'

// 默认表单数据
const defaultData = {
  name: '',
  method: PaymentMethodEnum.WECHAT.value,
  sort: 100,
  remarks: '',
  config: {
    [PaymentMethodEnum.WECHAT.value]: {
      // 微信商户号类型（normal普通商户 provider子商户）
      mchType: 'normal',
      // 微信支付API版本（v2和v3）
      version: 'v3',
      normal: {
        appId: '',
        mchId: '',
        apiKey: '',
        signatureMethod: 'publicKey',     // 验签方式（platformCert平台证书 publicKey微信支付公钥）
        publicKeyId: '',
        publicKey: '',
        apiclientCert: '',
        apiclientKey: '',
      },
      provider: {
        spAppId: '',
        spMchId: '',
        spApiKey: '',
        subAppId: '',
        subMchId: '',
        spSignatureMethod: 'publicKey',     // 验签方式（platformCert平台证书 publicKey微信支付公钥）
        spPublicKeyId: '',
        spPublicKey: '',
        spApiclientCert: '',
        spApiclientKey: ''
      }
    },
    [PaymentMethodEnum.ALIPAY.value]: {
      appId: '',
      signType: 'RSA2',
      signMode: 10,
      alipayPublicKey: '',
      appCertPublicKey: '',
      alipayCertPublicKey: '',
      alipayRootCert: '',
      merchantPrivateKey: ''
    }
  }
}

export default {
  components: {
    InputFile
  },
  data () {
    return {
      // 正在加载
      isLoading: false,
      isBtnLoading: false,
      // 标签布局属性
      labelCol: { span: 4 },
      // 输入框布局属性
      wrapperCol: { span: 10 },
      // 枚举类
      PaymentMethodEnum,
      // 表单记录
      record: cloneDeep(defaultData),
      // 要上传的文件域
      uploadFiles: {
        apiclientCert: null,
        apiclientKey: null,
        spApiclientCert: null,
        spApiclientKey: null,
        appCertPublicKey: null,
        alipayCertPublicKey: null,
        alipayRootCert: null,
      },
      // 当前模板ID
      templateId: null,
    }
  },
  watch: {
    '$route.query.templateId': {
      // 首次加载的时候执行函数
      immediate: true,
      handler (val) {
        // 记录当前模板ID
        this.templateId = val
        // 获取当前记录
        this.getDetail()
      }
    }
  },
  created () { },
  methods: {

    // 获取当前记录
    getDetail () {
      this.isLoading = true
      Api.detail(this.templateId)
        .then(result => {
          // 当前记录
          this.record = assignFormData(result.data.detail, defaultData)
        })
        .finally(() => this.isLoading = false)
    },

    // 移除表单项的校验结果
    clearValidate () {
      this.$refs.myForm.clearValidate()
    },

    // 点击表单提交
    handleSubmit (e) {
      e.preventDefault()
      this.$refs.myForm.validate(valid => {
        if (valid) {
          this.onSubmitForm()
        } else {
          console.log('validate error')
          return false
        }
      });
    },

    // 上传文件后记录到 uploadFiles中
    onChangeInputFile (name, args, key) {
      const { uploadFiles } = this
      if (args[1] !== null) {
        uploadFiles[key] = args[1]
      }
    },

    // 表单数据提交到后端
    onSubmitForm () {
      this.isLoading = true
      this.isBtnLoading = true
      const formData = this.buildFormData()
      Api.edit(formData)
        .then(result => {
          // 显示成功
          this.$message.success(result.message, 1.5)
          // 跳转到列表页
          setTimeout(() => {
            this.$router.push('./index')
          }, 1500)
        })
        .catch(() => this.isBtnLoading = false)
        .finally(() => this.isLoading = false)
    },

    // 生成表单数据 FormData格式
    buildFormData () {
      const { record, uploadFiles, templateId } = this
      const formData = new FormData()
      formData.append('templateId', templateId)
      formData.append('name', record.name)
      formData.append('sort', record.sort)
      formData.append('method', record.method)
      formData.append('remarks', record.remarks)
      formData.append('config', JSON.stringify(record.config))
      for (const index in uploadFiles) {
        if (uploadFiles[index] != null) {
          formData.append(index, uploadFiles[index])
        }
      }
      return formData
    }

  }
}
</script>

<style lang="less" scoped>
.ant-form-item {
  margin-bottom: 15px;
}
/deep/.ant-form-item-control {
  padding-left: 10px;

  .ant-form-item-control {
    padding-left: 0;
  }
}
</style>