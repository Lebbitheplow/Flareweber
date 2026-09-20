import { Hono } from 'hono'
import type { Env, Variant } from '../types'
import { getCartId } from '../lib/session'
import { createCheckoutSession } from '../lib/stripe'

const checkout = new Hono<Env>()

checkout.post('/', async (c) => {
  if (!c.env.DB) return c.json({ error: 'database_not_configured' }, 503)
  if (!c.env.STRIPE_SECRET_KEY) return c.json({ error: 'stripe_not_configured' }, 503)

  const body = await c.req.json<{ email?: string }>().catch(() => ({} as { email?: string }))
  const { id: cartId } = await getCartId(c)

  const { results } = await c.env.DB
    .prepare(`SELECT v.id, v.title, v.price_cents, v.currency, ci.quantity
              FROM cart_items ci
              JOIN product_variants v ON v.id = ci.variant_id
              WHERE ci.cart_id = ?1`)
    .bind(cartId)
    .all<Variant & { quantity: number }>()

  if (!results.length) return c.json({ error: 'cart_empty' }, 400)

  const total = results.reduce((sum, r) => sum + r.price_cents * r.quantity, 0)
  const currency = results[0].currency

  const order = await c.env.DB
    .prepare('INSERT INTO orders (email, status, total_cents, currency) VALUES (?1, ?2, ?3, ?4)')
    .bind(body.email ?? null, 'pending', total, currency)
    .run()

  const orderId = Number(order.meta.last_row_id)

  await c.env.DB.batch(results.map((r) =>
    c.env.DB!.prepare(
      'INSERT INTO order_items (order_id, variant_id, quantity, unit_price_cents) VALUES (?1, ?2, ?3, ?4)'
    ).bind(orderId, r.id, r.quantity, r.price_cents)
  ))

  const session = await createCheckoutSession(c.env.STRIPE_SECRET_KEY, {
    items: results.map((r) => ({
      price_data: {
        currency: r.currency.toLowerCase(),
        product_data: { name: r.title ?? `Variant ${r.id}` },
        unit_amount: r.price_cents,
      },
      quantity: r.quantity,
    })),
    successUrl: `${c.req.url.replace(/\/api\/checkout$/, '')}/thanks?order=${orderId}`,
    cancelUrl: `${c.req.url.replace(/\/api\/checkout$/, '')}/cart`,
    clientReferenceId: String(orderId),
    currency,
    customerEmail: body.email,
  })

  await c.env.DB
    .prepare('UPDATE orders SET stripe_session_id = ?1 WHERE id = ?2')
    .bind(session.id, orderId)
    .run()

  return c.json({ order_id: orderId, checkout_url: session.url })
})

export default checkout
