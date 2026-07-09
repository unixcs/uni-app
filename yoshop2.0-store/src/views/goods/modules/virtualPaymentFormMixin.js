import { buildVirtualPaymentConfig, priceToFen, isVirtualPaymentConfigMatched } from './virtualPayment'

const FORCE_UPDATE_DELAY = 20

export default {
  data () {
    return {
      vpAutoSync: true
    }
  },
  methods: {
    isVirtualPaymentEnabled () {
      return Number(this.form.getFieldValue('vp_enabled') || 0) === 1
    },

    getVirtualPaymentModeText () {
      return this.vpAutoSync ? '自动联动中' : '已切到手动检查'
    },

    runAfterVirtualPaymentFieldsRendered (callback) {
      this.$nextTick(() => {
        setTimeout(() => {
          this.$nextTick(() => {
            callback()
          })
        }, FORCE_UPDATE_DELAY)
      })
    },

    initVirtualPaymentState () {
      if (!this.form || !this.isVirtualPaymentEnabled()) {
        this.vpAutoSync = true
        return
      }
      const goodsPrice = this.form.getFieldValue('goods_price')
      const productId = String(this.form.getFieldValue('vp_product_id') || '').trim()
      const productName = String(this.form.getFieldValue('vp_product_name') || '').trim()
      const config = buildVirtualPaymentConfig(goodsPrice)
      const shouldAutoSync = (productId === '' && productName === '') ||
        isVirtualPaymentConfigMatched(goodsPrice, productId, productName) ||
        (config.ok && productId === config.productId && productName === '')

      this.vpAutoSync = shouldAutoSync
      const nextValues = {
        vp_price_snapshot: priceToFen(goodsPrice)
      }
      if (shouldAutoSync && config.ok) {
        nextValues.vp_product_id = config.productId
        nextValues.vp_product_name = config.productName
      }
      this.form.setFieldsValue(nextValues)
      this.revalidateVirtualPaymentFields()
    },

    syncVirtualPaymentFields ({ force = false } = {}) {
      if (!this.form || !this.isVirtualPaymentEnabled()) {
        return
      }
      const goodsPrice = this.form.getFieldValue('goods_price')
      const config = buildVirtualPaymentConfig(goodsPrice)
      const nextValues = {
        vp_price_snapshot: priceToFen(goodsPrice)
      }
      if (force || this.vpAutoSync) {
        nextValues.vp_product_id = config.ok ? config.productId : ''
        nextValues.vp_product_name = config.ok ? config.productName : ''
      }
      this.form.setFieldsValue(nextValues)
      this.revalidateVirtualPaymentFields()
    },

    onGoodsPriceChange () {
      this.$nextTick(() => {
        this.syncVirtualPaymentFields()
      })
    },

    onVirtualPaymentEnabledChange (e) {
      this.onForceUpdate(true)
      const enabled = Number(e && e.target ? e.target.value : e || 0)
      if (enabled === 1) {
        this.vpAutoSync = true
        this.runAfterVirtualPaymentFieldsRendered(() => {
          this.syncVirtualPaymentFields({ force: true })
        })
        return
      }
      this.vpAutoSync = true
      this.$nextTick(() => {
        this.form.setFieldsValue({
          vp_product_id: '',
          vp_product_name: '',
          vp_price_snapshot: 0
        })
        this.form.validateFields(['goods_price'], { force: true }, () => {})
      })
    },

    onVirtualPaymentManualChange () {
      this.$nextTick(() => {
        const goodsPrice = this.form.getFieldValue('goods_price')
        const productId = this.form.getFieldValue('vp_product_id')
        const productName = this.form.getFieldValue('vp_product_name')
        this.vpAutoSync = isVirtualPaymentConfigMatched(goodsPrice, productId, productName)
        this.revalidateVirtualPaymentFields()
      })
    },

    onRegenerateVirtualPaymentConfig () {
      this.vpAutoSync = true
      this.syncVirtualPaymentFields({ force: true })
    },

    revalidateVirtualPaymentFields () {
      if (!this.form || !this.isVirtualPaymentEnabled()) {
        return
      }
      this.$nextTick(() => {
        this.form.validateFields(['goods_price', 'vp_product_id', 'vp_product_name', 'vp_price_snapshot'], { force: true }, () => {})
      })
    },

    validateVirtualPaymentGoodsPrice (rule, value, callback) {
      if (!this.isVirtualPaymentEnabled()) {
        callback()
        return
      }
      const config = buildVirtualPaymentConfig(value)
      if (!config.ok) {
        callback(config.message)
        return
      }
      callback()
    },

    validateVirtualPaymentProductId (rule, value, callback) {
      if (!this.isVirtualPaymentEnabled()) {
        callback()
        return
      }
      const config = buildVirtualPaymentConfig(this.form.getFieldValue('goods_price'))
      if (!config.ok) {
        callback()
        return
      }
      if (String(value || '').trim() === '') {
        callback(new Error('请填写虚拟支付 productId'))
        return
      }
      if (String(value).trim() !== config.productId) {
        callback(new Error(`当前价格应生成 ${config.productId}`))
        return
      }
      callback()
    },

    validateVirtualPaymentProductName (rule, value, callback) {
      if (!this.isVirtualPaymentEnabled()) {
        callback()
        return
      }
      const config = buildVirtualPaymentConfig(this.form.getFieldValue('goods_price'))
      if (!config.ok) {
        callback()
        return
      }
      if (String(value || '').trim() === '') {
        callback(new Error('请填写虚拟支付道具名称'))
        return
      }
      if (String(value).trim() !== config.productName) {
        callback(new Error(`当前价格应生成 ${config.productName}`))
        return
      }
      callback()
    },

    validateVirtualPaymentPriceSnapshot (rule, value, callback) {
      if (!this.isVirtualPaymentEnabled()) {
        callback()
        return
      }
      const config = buildVirtualPaymentConfig(this.form.getFieldValue('goods_price'))
      if (!config.ok) {
        callback()
        return
      }
      if (Number(value || 0) !== Number(config.priceSnapshot)) {
        callback(new Error(`价格快照会自动锁定为 ${config.priceSnapshot}`))
        return
      }
      callback()
    }
  }
}
