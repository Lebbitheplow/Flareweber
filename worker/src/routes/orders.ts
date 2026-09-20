import { Hono } from 'hono'
import type { Env } from '../types'

const orders = new Hono<Env>()

type OrderRow = {
  id: number
  status: string
  payment_status: string
  total_cents: number
  currency: string
  created_at: string
}

orders.get('/:id', async (c) => {
  if (!c.env.DB) return c.json({ error: 'database_not_configured' }, 503)

  const email = c.req.query('email')
  if (!email) return c.json({ error: 'email required for order lookup' }, 400)

  const order = await c.env.DB
    .prepare('SELECT id, status, payment_status, total_cents, currency, created_at FROM orders WHERE id = ?1 AND email = ?2')
    .bind(Number(c.req.param('id')), email)
    .first<OrderRow>()

  if (!order) return c.json({ error: 'not_found' }, 404)

  const { results } = await c.env.DB
    .prepare(`SELECT oi.variant_id, oi.quantity, oi.unit_price_cents, v.title
              FROM order_items oi
              JOIN product_variants v ON v.id = oi.variant_id
              WHERE oi.order_id = ?1`)
    .bind(order.id)
    .all()

  return c.json({ order, items: results })
})

export default orders
