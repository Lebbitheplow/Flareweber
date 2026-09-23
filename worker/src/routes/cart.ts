import { Hono } from 'hono'
import type { Env, CartItem } from '../types'
import { getCartId } from '../lib/session'
import { readJsonObject, requireCartSecret, requireDb } from '../lib/http'
import { isPositiveInt } from '../lib/validate'

const cart = new Hono<Env>()

const MAX_QUANTITY = 999

export async function loadCart(db: D1Database, cartId: string): Promise<CartItem[]> {
  const { results } = await db
    .prepare(`SELECT v.id, v.product_id, v.sku, v.title, v.price_cents, v.currency,
                     p.title AS product_title, p.slug, p.image,
                     ci.quantity AS quantity_in_cart,
                     COALESCE(i.quantity, 0) AS quantity_available
              FROM cart_items ci
              JOIN product_variants v ON v.id = ci.variant_id
              JOIN products p ON p.id = v.product_id
              LEFT JOIN inventory i ON i.variant_id = v.id
              WHERE ci.cart_id = ?1
              ORDER BY ci.rowid`)
    .bind(cartId)
    .all<CartItem>()
  return results ?? []
}

function summary(cartId: string, items: CartItem[]) {
  const total = items.reduce((sum, i) => sum + i.price_cents * i.quantity_in_cart, 0)
  return { cart_id: cartId, items, total_cents: total, currency: items[0]?.currency ?? null }
}

type Guard = { db: D1Database; secret: string } | Response

function guards(c: Parameters<typeof requireDb>[0]): Guard {
  const db = requireDb(c)
  if (db instanceof Response) return db
  const secret = requireCartSecret(c)
  if (secret instanceof Response) return secret
  return { db, secret }
}

function parseQuantity(value: unknown, fallback?: number): number | null {
  if (value === undefined && fallback !== undefined) return fallback
  if (!isPositiveInt(value) || value > MAX_QUANTITY) return null
  return value
}

function parseVariantParam(raw: string): number | null {
  if (!/^\d{1,15}$/.test(raw)) return null
  const id = Number(raw)
  return isPositiveInt(id) ? id : null
}

type StockRow = { id: number; currency: string; stock: number }

async function loadVariant(db: D1Database, variantId: number): Promise<StockRow | null> {
  return db
    .prepare(`SELECT v.id, v.currency, COALESCE(i.quantity, 0) AS stock
              FROM product_variants v
              JOIN products p ON p.id = v.product_id AND p.is_active = 1
              LEFT JOIN inventory i ON i.variant_id = v.id
              WHERE v.id = ?1`)
    .bind(variantId)
    .first<StockRow>()
}

cart.get('/', async (c) => {
  const g = guards(c)
  if (g instanceof Response) return g

  const { id, setCookie } = await getCartId(c, g.secret)
  const items = await loadCart(g.db, id)
  if (setCookie) c.header('Set-Cookie', setCookie)
  return c.json(summary(id, items))
})

cart.post('/items', async (c) => {
  const g = guards(c)
  if (g instanceof Response) return g

  const body = await readJsonObject(c)
  if (!body) return c.json({ error: 'invalid_json' }, 400)

  const variantId = body.variant_id
  if (!isPositiveInt(variantId)) return c.json({ error: 'invalid_variant_id' }, 400)
  const quantity = parseQuantity(body.quantity, 1)
  if (quantity === null) return c.json({ error: 'invalid_quantity' }, 400)

  const { id: cartId, setCookie } = await getCartId(c, g.secret)
  const variant = await loadVariant(g.db, variantId)
  if (!variant) return c.json({ error: 'variant_not_found' }, 404)

  const existing = await loadCart(g.db, cartId)
  if (existing.some((i) => i.currency !== variant.currency)) {
    return c.json({ error: 'currency_mismatch' }, 409)
  }

  const inCart = existing.find((i) => i.id === variantId)?.quantity_in_cart ?? 0
  const wanted = inCart + quantity
  if (variant.stock !== -1 && wanted > variant.stock) {
    return c.json({ error: 'insufficient_stock', available: Math.max(variant.stock - inCart, 0) }, 409)
  }

  await g.db.batch([
    g.db.prepare('INSERT OR IGNORE INTO carts (id, currency) VALUES (?1, ?2)').bind(cartId, variant.currency),
    g.db
      .prepare(`INSERT INTO cart_items (cart_id, variant_id, quantity) VALUES (?1, ?2, ?3)
                ON CONFLICT(cart_id, variant_id) DO UPDATE SET quantity = excluded.quantity`)
      .bind(cartId, variantId, wanted),
    g.db
      .prepare(`UPDATE carts SET updated_at = datetime('now'), currency = ?2 WHERE id = ?1`)
      .bind(cartId, variant.currency),
  ])

  if (setCookie) c.header('Set-Cookie', setCookie)
  return c.json(summary(cartId, await loadCart(g.db, cartId)), 201)
})

cart.patch('/items/:id', async (c) => {
  const g = guards(c)
  if (g instanceof Response) return g

  const variantId = parseVariantParam(c.req.param('id'))
  if (variantId === null) return c.json({ error: 'invalid_variant_id' }, 400)

  const body = await readJsonObject(c)
  if (!body) return c.json({ error: 'invalid_json' }, 400)
  const quantity = parseQuantity(body.quantity)
  if (quantity === null) return c.json({ error: 'invalid_quantity' }, 400)

  const { id: cartId } = await getCartId(c, g.secret)
  const items = await loadCart(g.db, cartId)
  const item = items.find((i) => i.id === variantId)
  if (!item) return c.json({ error: 'not_in_cart' }, 404)

  if (item.quantity_available !== -1 && quantity > item.quantity_available) {
    return c.json({ error: 'insufficient_stock', available: item.quantity_available }, 409)
  }

  await g.db.batch([
    g.db
      .prepare('UPDATE cart_items SET quantity = ?3 WHERE cart_id = ?1 AND variant_id = ?2')
      .bind(cartId, variantId, quantity),
    g.db.prepare(`UPDATE carts SET updated_at = datetime('now') WHERE id = ?1`).bind(cartId),
  ])

  return c.json(summary(cartId, await loadCart(g.db, cartId)))
})

cart.delete('/items/:id', async (c) => {
  const g = guards(c)
  if (g instanceof Response) return g

  const variantId = parseVariantParam(c.req.param('id'))
  if (variantId === null) return c.json({ error: 'invalid_variant_id' }, 400)

  const { id: cartId } = await getCartId(c, g.secret)
  await g.db
    .prepare('DELETE FROM cart_items WHERE cart_id = ?1 AND variant_id = ?2')
    .bind(cartId, variantId)
    .run()

  return c.json(summary(cartId, await loadCart(g.db, cartId)))
})

cart.delete('/', async (c) => {
  const g = guards(c)
  if (g instanceof Response) return g

  const { id: cartId } = await getCartId(c, g.secret)
  await g.db.prepare('DELETE FROM cart_items WHERE cart_id = ?1').bind(cartId).run()
  return c.json(summary(cartId, []))
})

export default cart
