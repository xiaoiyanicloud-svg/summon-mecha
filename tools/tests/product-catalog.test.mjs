import test from 'node:test'
import assert from 'node:assert/strict'
import {
  isRealProductImage,
  pickBaseImage,
  pickGalleryImages,
  normalizeProductPricing,
  normalizeCatalogImageUrl,
  catalogFallbackImage,
} from '../../utils/product-catalog.js'

test('没有真实划线价时不编造原价', () => {
  const pricing = normalizeProductPricing({
    prices: { regular: { price: '899.0000' }, final: { price: '899.0000' } },
  })
  assert.equal(pricing.price, '899')
  assert.equal(pricing.originalPrice, '')
  assert.equal(pricing.hasDiscount, false)
})

test('真实特价时才返回原价', () => {
  const pricing = normalizeProductPricing({
    prices: { regular: { price: '520.0000' }, final: { price: '500.0000' } },
  })
  assert.equal(pricing.price, '500')
  assert.equal(pricing.originalPrice, '520')
  assert.equal(pricing.hasDiscount, true)
})

test('final 缺失时回退 regular，仍不造原价', () => {
  const pricing = normalizeProductPricing({
    prices: { regular: { price: '1299.0000' } },
  })
  assert.equal(pricing.price, '1299')
  assert.equal(pricing.originalPrice, '')
})

test('min_price 带货币符号与千分位也能解析', () => {
  const pricing = normalizeProductPricing({ min_price: '¥1,299.00' })
  assert.equal(pricing.price, '1299')
  assert.equal(pricing.originalPrice, '')
})

test('负价按 0 处理，不输出负原价', () => {
  const pricing = normalizeProductPricing({ prices: { regular: { price: '-10' }, final: { price: '-1' } } })
  assert.equal(pricing.price, '0')
  assert.equal(pricing.originalPrice, '')
})

test('占位图不算真实图', () => {
  assert.equal(isRealProductImage('http://x/storage/product-placeholder/1.png'), false)
  assert.equal(isRealProductImage(''), false)
  assert.equal(isRealProductImage(null), false)
  assert.equal(isRealProductImage('http://x/storage/cache/medium/1.webp'), true)
})

test('主图优先 medium，跳过占位图并回退到 storage 原图', () => {
  const product = {
    base_image: {
      small_image_url: 'http://x/storage/product-placeholder/1.png',
      medium_image_url: 'http://x/cache/medium/product/1/real.webp',
      large_image_url: 'http://x/cache/large/product/1/real.webp',
    },
  }
  assert.equal(pickBaseImage(product), 'http://x/storage/product/1/real.webp')
})

test('图集过滤占位图并兼容 url 字段', () => {
  const product = {
    images: [
      { medium_image_url: 'http://x/cache/medium/product/1/a.webp' },
      { url: 'http://x/cache/large/product/1/b.webp' },
      { medium_image_url: 'http://x/storage/product-placeholder/1.png' },
      null,
    ],
  }
  assert.deepEqual(pickGalleryImages(product), [
    'http://x/storage/product/1/a.webp',
    'http://x/storage/product/1/b.webp',
  ])
})

test('媒体 URL 同时修复 cache 路由与 localhost 域名', () => {
  assert.equal(
    normalizeCatalogImageUrl('http://localhost/cache/medium/product/5/a.webp', 'http://127.0.0.1:8000'),
    'http://127.0.0.1:8000/storage/product/5/a.webp'
  )
  assert.equal(
    normalizeCatalogImageUrl('/storage/product/5/a.webp', 'http://127.0.0.1:8000'),
    'http://127.0.0.1:8000/storage/product/5/a.webp'
  )
  assert.equal(normalizeCatalogImageUrl(''), '')
})

test('所有缺图兜底统一使用召唤机甲 Logo', () => {
  const expected = '/static/summon-mecha-logo.webp'
  assert.equal(catalogFallbackImage('rent', 8), expected)
  assert.equal(catalogFallbackImage('buy', 2), expected)
  assert.equal(catalogFallbackImage('skill', 21), expected)
  assert.equal(catalogFallbackImage('accessory', 17), expected)
  assert.equal(catalogFallbackImage('scene', 3), expected)
})
