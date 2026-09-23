import { Hono } from 'hono'
import type { Env } from '../types'
import { orderToken, timingSafeEqual } from '../lib/crypto'
import { requireCartSecret, requireDb } from '../lib/http'

const orders = new Hono<Env>()

type OrderRow = {
  id: number
  status: string
  payment_status: string
  total_cents: number
  currency: string
  email: string | null
  stripe_session_id: string | null
  created_at: string
}

type ItemRow = {
  variant_id: number
  quantity: number
  unit_price_cents: number
  title: string | null
  product_title: string
}

const ORDER_COLUMNS = 'id, status, payment_status, total_cents, currency, email, stripe_session_id, created_at'

async function serialize(db: D1Database, order: OrderRow) {
  const { results } = await db
    .prepare(`SELECT oi.variant_id, oi.quantity, oi.unit_price_cents, v.title, p.title AS product_title
              FROM order_items oi
              JOIN product_variants v ON v.id = oi.variant_id
              JOIN products p ON p.id = v.product_id
              WHERE oi.order_id = ?1 ORDER BY oi.id`)
    .bind(order.id)
    .all<ItemRow>()

  return {
    id: order.id,
    status: order.status,
    payment_status: order.payment_status,
    total_cents: order.total_cents,
    currency: order.currency,
    email: order.email,
    created_at: order.created_at,
    items: results.map((i) => ({
      variant_id: i.variant_id,
      title: i.title && i.title !== 'Default' ? `${i.product_title} / ${i.title}` : i.product_title,
      quantity: i.quantity,
      unit_price_cents: i.unit_price_cents,
    })),
  }
}

/** Thank-you page lookup: the Stripe session id is unguessable. */
orders.get('/by-session/:sessionId', async (c) => {
  const db = requireDb(c)
  if (db instanceof Response) return db

  const sessionId = c.req.param('sessionId')
  if (!/^cs_[A-Za-z0-9_]{8,200}$/.test(sessionId)) return c.json({ error: 'not_found' }, 404)

  const order = await db
    .prepare(`SELECT ${ORDER_COLUMNS} FROM orders WHERE stripe_session_id = ?1`)
    .bind(sessionId)
    .first<OrderRow>()
  if (!order) return c.json({ error: 'not_found' }, 404)

  return c.json(await serialize(db, order))
})

/** Order lookup by id requires the order token (constant-time compare). */
orders.get('/:id', async (c) => {
  const db = requireDb(c)
  if (db instanceof Response) return db
  const secret = requireCartSecret(c)
  if (secret instanceof Response) return secret

  const rawId = c.req.param('id')
  const token = c.req.query('token') ?? ''
  if (!/^\d{1,12}$/.test(rawId)) return c.json({ error: 'not_found' }, 404)
  if (!/^[0-9a-f]{32}$/.test(token)) return c.json({ error: 'token_required' }, 401)

  const order = await db
    .prepare(`SELECT ${ORDER_COLUMNS} FROM orders WHERE id = ?1`)
    .bind(Number(rawId))
    .first<OrderRow>()
  if (!order || !order.stripe_session_id) return c.json({ error: 'not_found' }, 404)

  const expected = await orderToken(secret, order.id, order.stripe_session_id)
  if (!timingSafeEqual(token, expected)) return c.json({ error: 'not_found' }, 404)

  return c.json(await serialize(db, order))
})

export default orders
