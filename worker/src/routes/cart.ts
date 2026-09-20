import { Hono } from 'hono'
import type { Env, CartItem } from '../types'
import { getCartId } from '../lib/session'

const cart = new Hono<Env>()

async function loadCart(db: D1Database, cartId: string): Promise<CartItem[]> {
  const { results } = await db
    .prepare(`SELECT v.id, v.product_id, v.sku, v.title, v.price_cents, v.currency,
                      ci.quantity AS quantity_in_cart
               FROM cart_items ci
               JOIN product_variants v ON v.id = ci.variant_id
               WHERE ci.cart_id = ?1`)
    .bind(cartId)
    .all<CartItem>()
  return results ?? []
}

cart.get('/', async (c) => {
  if (!c.env.DB) return c.json({ error: 'database_not_configured' }, 503)

  const { id, setCookie } = await getCartId(c)
  const items = await loadCart(c.env.DB, id)
  const total = items.reduce((sum, i) => sum + i.price_cents * i.quantity_in_cart, 0)

  if (setCookie) c.header('Set-Cookie', setCookie)
  return c.json({ cart_id: id, items, total_cents: total })
})

cart.post('/items', async (c) => {
  if (!c.env.DB) return c.json({ error: 'database_not_configured' }, 503)

  const body = await c.req.json<{ variant_id: number; quantity?: number }>()
  if (typeof body.variant_id !== 'number') return c.json({ error: 'variant_id required' }, 400)

  const quantity = Math.max(1, Math.trunc(body.quantity ?? 1))
  const { id, setCookie } = await getCartId(c)

  await c.env.DB.batch([
    c.env.DB.prepare('INSERT OR IGNORE INTO carts (id) VALUES (?1)').bind(id),
    c.env.DB.prepare(`INSERT INTO cart_items (cart_id, variant_id, quantity) VALUES (?1, ?2, ?3)
      ON CONFLICT(cart_id, variant_id) DO UPDATE SET quantity = quantity + ?3`)
      .bind(id, body.variant_id, quantity),
    c.env.DB.prepare('UPDATE carts SET updated_at = datetime(\'now\') WHERE id = ?1').bind(id),
  ])

  if (setCookie) c.header('Set-Cookie', setCookie)
  return c.json({ items: await loadCart(c.env.DB, id) }, 201)
})

cart.delete('/items/:variantId', async (c) => {
  if (!c.env.DB) return c.json({ error: 'database_not_configured' }, 503)

  const { id } = await getCartId(c)
  await c.env.DB
    .prepare('DELETE FROM cart_items WHERE cart_id = ?1 AND variant_id = ?2')
    .bind(id, Number(c.req.param('variantId')))
    .run()

  return c.json({ items: await loadCart(c.env.DB, id) })
})

export default cart
