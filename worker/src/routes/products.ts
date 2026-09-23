import { Hono } from 'hono'
import type { Category, Env, ProductRow, Variant } from '../types'
import { requireDb } from '../lib/http'

const products = new Hono<Env>()

const PRODUCT_COLUMNS =
  'p.id, p.slug, p.title, p.description, p.price_cents, p.currency, p.sku, p.image, p.images_json'

function parseImages(json: string | null, fallback: string | null): string[] {
  if (json) {
    try {
      const parsed: unknown = JSON.parse(json)
      if (Array.isArray(parsed)) return parsed.filter((v): v is string => typeof v === 'string')
    } catch {
      // fall through to the single image
    }
  }
  return fallback ? [fallback] : []
}

function placeholders(count: number): string {
  return Array.from({ length: count }, (_, i) => `?${i + 1}`).join(', ')
}

async function categoriesFor(db: D1Database, ids: number[]): Promise<Map<number, Category[]>> {
  const map = new Map<number, Category[]>()
  if (ids.length === 0) return map
  const { results } = await db
    .prepare(`SELECT pc.product_id, c.id, c.name, c.slug
              FROM product_categories pc
              JOIN categories c ON c.id = pc.category_id
              WHERE pc.product_id IN (${placeholders(ids.length)}) AND pc.active = 1
              ORDER BY c.name`)
    .bind(...ids)
    .all<Category & { product_id: number }>()
  for (const row of results) {
    const list = map.get(row.product_id) ?? []
    list.push({ id: row.id, name: row.name, slug: row.slug })
    map.set(row.product_id, list)
  }
  return map
}

/** Product quantity = sum of variant stock; -1 (unlimited) wins. */
async function quantitiesFor(db: D1Database, ids: number[]): Promise<Map<number, number>> {
  const map = new Map<number, number>()
  if (ids.length === 0) return map
  const { results } = await db
    .prepare(`SELECT v.product_id, COALESCE(i.quantity, 0) AS quantity
              FROM product_variants v
              LEFT JOIN inventory i ON i.variant_id = v.id
              WHERE v.product_id IN (${placeholders(ids.length)})`)
    .bind(...ids)
    .all<{ product_id: number; quantity: number }>()
  for (const row of results) {
    const current = map.get(row.product_id)
    if (current === -1 || row.quantity === -1) map.set(row.product_id, -1)
    else map.set(row.product_id, (current ?? 0) + row.quantity)
  }
  return map
}

function serialize(row: ProductRow, quantity: number, categories: Category[]) {
  return {
    id: row.id,
    slug: row.slug,
    title: row.title,
    description: row.description,
    price_cents: row.price_cents,
    currency: row.currency,
    sku: row.sku,
    image: row.image,
    images: parseImages(row.images_json, row.image),
    quantity,
    categories,
  }
}

products.get('/', async (c) => {
  const db = requireDb(c)
  if (db instanceof Response) return db

  const limit = Math.min(Math.max(Number(c.req.query('limit')) || 50, 1), 100)
  const offset = Math.max(Number(c.req.query('offset')) || 0, 0)

  const { results } = await db
    .prepare(`SELECT ${PRODUCT_COLUMNS} FROM products p WHERE p.is_active = 1
              ORDER BY p.id DESC LIMIT ?1 OFFSET ?2`)
    .bind(limit, offset)
    .all<ProductRow>()

  const ids = results.map((r) => r.id)
  const [quantities, categories] = await Promise.all([quantitiesFor(db, ids), categoriesFor(db, ids)])

  return c.json({
    products: results.map((row) => serialize(row, quantities.get(row.id) ?? 0, categories.get(row.id) ?? [])),
    limit,
    offset,
  })
})

products.get('/:slug', async (c) => {
  const db = requireDb(c)
  if (db instanceof Response) return db

  const slug = c.req.param('slug')
  if (!slug || slug.length > 255) return c.json({ error: 'not_found' }, 404)

  const product = await db
    .prepare(`SELECT ${PRODUCT_COLUMNS} FROM products p WHERE p.slug = ?1 AND p.is_active = 1`)
    .bind(slug)
    .first<ProductRow>()
  if (!product) return c.json({ error: 'not_found' }, 404)

  const [{ results: variants }, quantities, categories] = await Promise.all([
    db
      .prepare(`SELECT v.id, v.product_id, v.sku, v.title, v.price_cents, v.currency,
                       COALESCE(i.quantity, 0) AS quantity
                FROM product_variants v
                LEFT JOIN inventory i ON i.variant_id = v.id
                WHERE v.product_id = ?1 ORDER BY v.id`)
      .bind(product.id)
      .all<Variant>(),
    quantitiesFor(db, [product.id]),
    categoriesFor(db, [product.id]),
  ])

  return c.json({
    product: serialize(product, quantities.get(product.id) ?? 0, categories.get(product.id) ?? []),
    variants,
  })
})

export default products
