const VP_PRODUCT_PREFIX = 'vip'

const normalizePriceNumber = value => {
  const number = Number(value)
  return Number.isFinite(number) ? number : 0
}

export const priceToFen = value => {
  const price = normalizePriceNumber(value)
  if (price <= 0) {
    return 0
  }
  return Math.round(price * 100)
}

export const buildVirtualPaymentConfig = value => {
  const price = normalizePriceNumber(value)
  const priceSnapshot = priceToFen(price)
  if (priceSnapshot <= 0) {
    return {
      ok: false,
      message: '价格必须大于 0 元',
      priceSnapshot: 0,
      productId: '',
      productName: ''
    }
  }
  if (priceSnapshot >= 100 && priceSnapshot % 100 !== 0) {
    return {
      ok: false,
      message: '1 元及以上只能填整数，比如 9、88、158',
      priceSnapshot,
      productId: '',
      productName: ''
    }
  }
  const suffix = priceSnapshot >= 100
    ? String(priceSnapshot / 100)
    : String(priceSnapshot).padStart(3, '0')
  const productId = `${VP_PRODUCT_PREFIX}${suffix}`
  return {
    ok: true,
    message: '',
    priceSnapshot,
    productId,
    productName: productId
  }
}

export const isVirtualPaymentConfigMatched = (value, productId, productName) => {
  const config = buildVirtualPaymentConfig(value)
  if (!config.ok) {
    return false
  }
  return String(productId || '').trim() === config.productId &&
    String(productName || '').trim() === config.productName
}
