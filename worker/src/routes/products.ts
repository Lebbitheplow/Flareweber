import { Hono } from 'hono'
import type { Env, Product, Variant } from '../types'

const products = new Hono<Env>()

products.get('/', async (c) => {
  if (!c.env.DB) return c.json({ error: 'database_not_configured' }, 503)

  const { results } = await c.env.DB
    .prepare('SELECT id, slug, title, description, image FROM products WHERE published = 1 ORDER BY id DESC')
    .all<Product>()

  return c.json({ products: results })
})

products.get('/:slug', async (c) => {
  if (!c.env.DB) return c.json({ error: 'database_not_configured' }, 503)

  const product = await c.env.DB
    .prepare('SELECT id, slug, title, description, image FROM products WHERE slug = ?1 AND published = 1')
    .bind(c.req.param('slug'))
    .first<Product>()

  if (!product) return c.json({ error: 'not_found' }, 404)

  const { results } = await c.env.DB
    .prepare(`SELECT v.id, v.product_id, v.sku, v.title, v.price_cents, v.currency,
                      COALESCE(i.quantity, 0) AS quantity
               FROM product_variants v
               LEFT JOIN inventory i ON i.variant_id = v.id
               WHERE v.product_id = ?1`)
    .bind(product.id)
    .all<Variant>()

  return c.json({ product, variants: results })
})

export default products
