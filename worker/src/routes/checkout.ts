import { Hono } from 'hono'
import type { Env } from '../types'
import { getCartId } from '../lib/session'
import { createCheckoutSession, StripeApiError } from '../lib/stripe'
import { orderToken, sha256Hex } from '../lib/crypto'
import { readJsonObject, requireCartSecret, requireDb } from '../lib/http'
import { normalizeEmail } from '../lib/validate'
import { loadCart } from './cart'

const checkout = new Hono<Env>()

function absoluteImage(siteUrl: string, image: string | null): string[] {
  if (!image) return []
  if (/^https?:\/\//i.test(image)) return [image]
  if (image.startsWith('/')) return [`${siteUrl}${image}`]
  return []
}

/**
 * Contract C: create the Stripe Checkout Session first, then insert the order
 * and its items in one D1 batch keyed by stripe_session_id. The order token is
 * HMAC(CART_SECRET, order_id + session_id) and is returned to the caller.
 */
checkout.post('/', async (c) => {
  const db = requireDb(c)
  if (db instanceof Response) return db
  const secret = requireCartSecret(c)
  if (secret instanceof Response) return secret
  if (!c.env.STRIPE_SECRET_KEY) return c.json({ error: 'stripe_not_configured' }, 503)
  const siteUrl = (c.env.SITE_URL ?? '').replace(/\/+$/, '')
  if (!/^https?:\/\//.test(siteUrl)) return c.json({ error: 'site_url_not_configured' }, 503)

  const body = await readJsonObject(c)
  if (!body) return c.json({ error: 'invalid_json' }, 400)

  let email: string | null = null
  if (body.email !== undefined && body.email !== null && body.email !== '') {
    email = normalizeEmail(body.email)
    if (!email) return c.json({ error: 'invalid_email' }, 400)
  }

  const { id: cartId } = await getCartId(c, secret)
  const items = await loadCart(db, cartId)
  if (items.length === 0) return c.json({ error: 'cart_empty' }, 400)

  const currency = items[0].currency
  if (items.some((i) => i.currency !== currency)) return c.json({ error: 'currency_mismatch' }, 409)

  const short = items.find((i) => i.quantity_available !== -1 && i.quantity_in_cart > i.quantity_available)
  if (short) {
    return c.json(
      { error: 'insufficient_stock', variant_id: short.id, available: Math.max(short.quantity_available, 0) },
      409
    )
  }

  const total = items.reduce((sum, i) => sum + i.price_cents * i.quantity_in_cart, 0)
  const fingerprint = await sha256Hex(
    JSON.stringify([cartId, email, items.map((i) => [i.id, i.quantity_in_cart, i.price_cents])])
  )

  let session: { id: string; url: string | null }
  try {
    session = await createCheckoutSession(c.env.STRIPE_SECRET_KEY, {
      items: items.map((i) => ({
        name: i.title && i.title !== 'Default' ? `${i.product_title} / ${i.title}` : i.product_title,
        images: absoluteImage(siteUrl, i.image),
        currency: i.currency,
        unitAmount: i.price_cents,
        quantity: i.quantity_in_cart,
      })),
      successUrl: `${siteUrl}/thank-you/?session_id={CHECKOUT_SESSION_ID}`,
      cancelUrl: `${siteUrl}/`,
      clientReferenceId: cartId,
      customerEmail: email,
      metadata: { cart_id: cartId, site: c.env.SITE_NAME ?? '' },
      idempotencyKey: `fw-checkout-${fingerprint.slice(0, 48)}`,
    })
  } catch (error) {
    const message = error instanceof StripeApiError ? `${error.status} ${error.message}` : String(error)
    console.error('stripe checkout session failed:', message)
    return c.json({ error: 'stripe_error' }, 502)
  }

  // Stripe idempotency can hand back the same session for a repeated request.
  const existing = await db
    .prepare('SELECT id, order_token FROM orders WHERE stripe_session_id = ?1')
    .bind(session.id)
    .first<{ id: number; order_token: string | null }>()
  if (existing) {
    const token = existing.order_token ?? (await orderToken(secret, existing.id, session.id))
    return c.json({ checkout_url: session.url, order_id: existing.id, order_token: token })
  }

  const statements = [
    db
      .prepare(`INSERT INTO orders (email, status, payment_status, total_cents, currency, stripe_session_id, cart_id)
                VALUES (?1, 'pending', 'unpaid', ?2, ?3, ?4, ?5)`)
      .bind(email, total, currency, session.id, cartId),
    ...items.map((i) =>
      db
        .prepare(`INSERT INTO order_items (order_id, variant_id, quantity, unit_price_cents)
                  SELECT id, ?2, ?3, ?4 FROM orders WHERE stripe_session_id = ?1`)
        .bind(session.id, i.id, i.quantity_in_cart, i.price_cents)
    ),
  ]
  const results = await db.batch(statements)
  const orderId = Number(results[0].meta.last_row_id)
  const token = await orderToken(secret, orderId, session.id)
  await db.prepare('UPDATE orders SET order_token = ?2 WHERE id = ?1').bind(orderId, token).run()

  return c.json({ checkout_url: session.url, order_id: orderId, order_token: token })
})

export default checkout
