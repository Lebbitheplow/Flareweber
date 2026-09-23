import { test } from 'node:test'
import assert from 'node:assert/strict'
import { createHmac } from 'node:crypto'
import * as lib from '../node_modules/.cache/fw-tests/entry.mjs'

const SECRET = 'a'.repeat(64)

test('hmacHex matches node crypto and timingSafeEqual compares correctly', async () => {
  const expected = createHmac('sha256', SECRET).update('hello').digest('hex')
  assert.equal(await lib.hmacHex(SECRET, 'hello'), expected)
  assert.equal(lib.timingSafeEqual('abc', 'abc'), true)
  assert.equal(lib.timingSafeEqual('abc', 'abd'), false)
  assert.equal(lib.timingSafeEqual('abc', 'abcd'), false)
  assert.equal(lib.timingSafeEqual('', ''), true)
  assert.equal(lib.timingSafeEqual('', 'a'), false)
})

test('cart cookie value signs and verifies, rejects tampering and wrong secret', async () => {
  const id = crypto.randomUUID()
  const signed = await lib.signValue(id, SECRET)
  assert.equal(signed.split('.').length, 2)
  assert.equal(await lib.verifySignedValue(signed, SECRET), id)
  assert.equal(await lib.verifySignedValue(signed, 'b'.repeat(64)), null)
  const tampered = `${crypto.randomUUID()}.${signed.split('.')[1]}`
  assert.equal(await lib.verifySignedValue(tampered, SECRET), null)
  assert.equal(await lib.verifySignedValue('nodot', SECRET), null)
  assert.equal(await lib.verifySignedValue('.sigonly', SECRET), null)
  assert.equal(await lib.verifySignedValue(null, SECRET), null)
})

test('readCookie and buildCookie keep the expected flags', async () => {
  const cookie = lib.buildCookie('fw_cart', 'v.sig', 100, true)
  assert.match(cookie, /^fw_cart=v\.sig; Path=\/; HttpOnly; SameSite=Lax; Max-Age=100; Secure$/)
  assert.equal(lib.readCookie('a=1; fw_cart=v%2Esig; b=2', 'fw_cart'), 'v.sig')
  assert.equal(lib.readCookie('a=1', 'fw_cart'), null)
  assert.equal(lib.readCookie(undefined, 'fw_cart'), null)
})

test('customer session payload parsing enforces expiry', () => {
  const now = 1_000_000
  assert.equal(lib.parseCustomerSession(`42:${now + 10}`, now), 42)
  assert.equal(lib.parseCustomerSession(`42:${now - 1}`, now), null)
  assert.equal(lib.parseCustomerSession('abc', now), null)
  assert.equal(lib.parseCustomerSession('0:99999999', now), null)
})

test('order token is 32 hex, deterministic and bound to order id and session', async () => {
  const token = await lib.orderToken(SECRET, 7, 'cs_test_123')
  assert.match(token, /^[0-9a-f]{32}$/)
  assert.equal(await lib.orderToken(SECRET, 7, 'cs_test_123'), token)
  assert.notEqual(await lib.orderToken(SECRET, 8, 'cs_test_123'), token)
  assert.notEqual(await lib.orderToken(SECRET, 7, 'cs_test_124'), token)
  assert.notEqual(await lib.orderToken('other-secret-value', 7, 'cs_test_123'), token)
})

test('stripe signature verification accepts a valid header and rejects bad ones', async () => {
  const secret = 'whsec_test_secret'
  const payload = JSON.stringify({ id: 'evt_1', type: 'checkout.session.completed' })
  const t = 1_700_000_000
  const sig = createHmac('sha256', secret).update(`${t}.${payload}`).digest('hex')

  assert.equal(await lib.verifyStripeSignature(payload, `t=${t},v1=${sig}`, secret, t + 10), true)
  assert.equal(await lib.verifyStripeSignature(payload, `t=${t},v1=deadbeef,v1=${sig}`, secret, t + 10), true)
  assert.equal(await lib.verifyStripeSignature(payload, `t=${t},v1=${sig}`, 'wrong', t + 10), false)
  assert.equal(await lib.verifyStripeSignature(payload + ' ', `t=${t},v1=${sig}`, secret, t + 10), false)
  assert.equal(await lib.verifyStripeSignature(payload, `t=${t},v1=${sig}`, secret, t + 301), false)
  assert.equal(await lib.verifyStripeSignature(payload, `t=${t},v0=${sig}`, secret, t), false)
  assert.equal(await lib.verifyStripeSignature(payload, `v1=${sig}`, secret, t), false)
})

test('checkout session body contains contract fields', () => {
  const body = lib.buildCheckoutSessionBody({
    items: [{ name: 'Hat', images: ['https://x.test/media/hat.jpg'], currency: 'USD', unitAmount: 1250, quantity: 2 }],
    successUrl: 'https://x.test/thank-you/?session_id={CHECKOUT_SESSION_ID}',
    cancelUrl: 'https://x.test/',
    clientReferenceId: 'cart-1',
    customerEmail: 'a@b.co',
    metadata: { cart_id: 'cart-1' },
    idempotencyKey: 'k',
  })
  assert.equal(body.get('mode'), 'payment')
  assert.equal(body.get('allow_promotion_codes'), 'true')
  assert.equal(body.get('line_items[0][price_data][currency]'), 'usd')
  assert.equal(body.get('line_items[0][price_data][unit_amount]'), '1250')
  assert.equal(body.get('line_items[0][price_data][product_data][images][0]'), 'https://x.test/media/hat.jpg')
  assert.equal(body.get('line_items[0][quantity]'), '2')
  assert.equal(body.get('customer_email'), 'a@b.co')
  assert.equal(body.get('metadata[cart_id]'), 'cart-1')
  assert.equal(body.get('success_url'), 'https://x.test/thank-you/?session_id={CHECKOUT_SESSION_ID}')
})

test('media key mapping follows contract A and rejects traversal', () => {
  assert.equal(lib.mediaKeyFromPath('/media/default/hero.jpg'), 'media/default/hero.jpg')
  assert.equal(lib.mediaKeyFromPath('/media/default/my%20photo.jpg'), 'media/default/my photo.jpg')
  assert.equal(lib.mediaKeyFromPath('/media/default/100%.jpg'), 'media/default/100%.jpg')
  assert.equal(lib.mediaKeyFromPath('/media/default/a%2525b.jpg'), 'media/default/a%25b.jpg')
  assert.equal(lib.mediaKeyFromPath('/media/'), null)
  assert.equal(lib.mediaKeyFromPath('/media'), null)
  assert.equal(lib.mediaKeyFromPath('/media/../secret'), null)
  assert.equal(lib.mediaKeyFromPath('/media/%2e%2e/secret'), null)
  assert.equal(lib.mediaKeyFromPath('/media/a//b.jpg'), null)
  assert.equal(lib.mediaKeyFromPath('/other/x.jpg'), null)
})

test('range parsing and resolution', () => {
  assert.deepEqual(lib.parseRange('bytes=0-99'), { offset: 0, length: 100 })
  assert.deepEqual(lib.parseRange('bytes=100-'), { offset: 100 })
  assert.deepEqual(lib.parseRange('bytes=-50'), { suffix: 50 })
  assert.equal(lib.parseRange('bytes=5-2'), null)
  assert.equal(lib.parseRange('bytes=0-1,5-9'), null)
  assert.equal(lib.parseRange('items=0-1'), null)
  assert.equal(lib.parseRange(undefined), null)
  assert.deepEqual(lib.resolveRange({ offset: 0, length: 100 }, 50), { start: 0, end: 49 })
  assert.deepEqual(lib.resolveRange({ suffix: 10 }, 50), { start: 40, end: 49 })
  assert.equal(lib.resolveRange({ offset: 50 }, 50), null)
})

test('etag matching and content types', () => {
  assert.equal(lib.etagMatches('"abc"', '"abc"'), true)
  assert.equal(lib.etagMatches('W/"abc"', '"abc"'), true)
  assert.equal(lib.etagMatches('"x", "abc"', '"abc"'), true)
  assert.equal(lib.etagMatches('*', '"abc"'), true)
  assert.equal(lib.etagMatches('"abd"', '"abc"'), false)
  assert.equal(lib.contentTypeForKey('media/a.webp'), 'image/webp')
  assert.equal(lib.contentTypeForKey('media/a.unknown'), 'application/octet-stream')
})

test('form submission validation', () => {
  const ok = lib.validateFormSubmission({ form: 'contact', data: { name: 'A', _hp: '' } })
  assert.deepEqual(ok, { ok: true, form: 'contact', data: { name: 'A' } })
  assert.equal(lib.validateFormSubmission({ form: 'contact', data: { name: 'A', _hp: 'bot' } }).error, 'rejected')
  assert.equal(lib.validateFormSubmission({ form: 'bad name', data: { a: 'b' } }).error, 'invalid_form')
  assert.equal(lib.validateFormSubmission({ form: 'c', data: { a: 1 } }).error, 'invalid_field')
  assert.equal(lib.validateFormSubmission({ form: 'c', data: [] }).error, 'invalid_data')
  assert.equal(lib.validateFormSubmission({ form: 'c', data: {} }).error, 'empty_data')
  assert.equal(lib.validateFormSubmission({ form: 'c', data: { a: 'x'.repeat(10_001) } }).error, 'field_too_long')
  assert.equal(lib.normalizeEmail('  A@B.CO '), 'a@b.co')
  assert.equal(lib.normalizeEmail('nope'), null)
  assert.equal(lib.isPositiveInt(3), true)
  assert.equal(lib.isPositiveInt(3.5), false)
  assert.equal(lib.isPositiveInt('3'), false)
})
