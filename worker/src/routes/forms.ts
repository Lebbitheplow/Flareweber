import { Hono } from 'hono'
import type { Env } from '../types'
import { sha256Hex } from '../lib/crypto'
import { clientIp, readJsonObject, requireDb } from '../lib/http'
import { validateFormSubmission } from '../lib/validate'

const forms = new Hono<Env>()

const MAX_BODY_BYTES = 64 * 1024
const RATE_WINDOW_SECONDS = 600
const RATE_LIMIT = 10

/**
 * Soft per-IP rate limit backed by the D1 `rate_limits` table: at most
 * RATE_LIMIT submissions per RATE_WINDOW_SECONDS per hashed IP. It is best
 * effort: D1 has no strict atomicity across colos and if the table is missing
 * or the query fails the submission is allowed through.
 */
async function overLimit(db: D1Database, key: string): Promise<boolean> {
  const now = Math.floor(Date.now() / 1000)
  try {
    const row = await db
      .prepare(`INSERT INTO rate_limits (key, count, window_start) VALUES (?1, 1, ?2)
                ON CONFLICT(key) DO UPDATE SET
                  count = CASE WHEN rate_limits.window_start <= ?2 - ?3 THEN 1 ELSE rate_limits.count + 1 END,
                  window_start = CASE WHEN rate_limits.window_start <= ?2 - ?3 THEN ?2 ELSE rate_limits.window_start END
                RETURNING count`)
      .bind(key, now, RATE_WINDOW_SECONDS)
      .first<{ count: number }>()
    if (Math.random() < 0.02) {
      await db.prepare('DELETE FROM rate_limits WHERE window_start < ?1').bind(now - RATE_WINDOW_SECONDS * 2).run()
    }
    return (row?.count ?? 0) > RATE_LIMIT
  } catch (error) {
    console.error('rate limit check failed:', error instanceof Error ? error.message : String(error))
    return false
  }
}

forms.post('/', async (c) => {
  const db = requireDb(c)
  if (db instanceof Response) return db

  const length = Number(c.req.header('content-length') ?? 0)
  if (length > MAX_BODY_BYTES) return c.json({ error: 'payload_too_large' }, 413)

  const body = await readJsonObject(c)
  if (!body) return c.json({ error: 'invalid_json' }, 400)

  const validated = validateFormSubmission(body)
  if (!validated.ok) return c.json({ error: validated.error }, 400)

  // Store a salted hash of the IP, never the raw address.
  const salt = c.env.CART_SECRET ?? c.env.SITE_URL ?? ''
  const ipHash = (await sha256Hex(`${salt}|${clientIp(c)}`)).slice(0, 32)

  if (await overLimit(db, `forms:${ipHash}`)) return c.json({ error: 'rate_limited' }, 429)

  const userAgent = (c.req.header('user-agent') ?? '').slice(0, 255) || null
  await db
    .prepare('INSERT INTO form_entries (form, data_json, ip_hash, user_agent) VALUES (?1, ?2, ?3, ?4)')
    .bind(validated.form, JSON.stringify(validated.data), ipHash, userAgent)
    .run()

  return c.json({ ok: true }, 201)
})

export default forms
