import { Hono } from 'hono'
import type { Env } from '../types'
import { timingSafeEqual } from '../lib/crypto'
import { clearCustomerCookie, customerIdFromRequest, customerSessionCookie } from '../lib/session'
import { isUniqueViolation, readJsonObject, requireCartSecret, requireDb } from '../lib/http'
import { normalizeEmail } from '../lib/validate'

const customers = new Hono<Env>()

const encoder = new TextEncoder()
const PBKDF2_ITERATIONS = 100_000
const DUMMY_SALT = btoa('flareweber-dummy')

type CustomerRow = { id: number; email: string; name: string | null; password_hash: string | null }

function b64(bytes: Uint8Array): string {
  return btoa(String.fromCharCode(...bytes))
}

async function hashPassword(password: string, salt?: string): Promise<string> {
  const keyMaterial = await crypto.subtle.importKey('raw', encoder.encode(password), 'PBKDF2', false, ['deriveBits'])
  const saltBytes = salt
    ? Uint8Array.from(atob(salt), (ch) => ch.charCodeAt(0))
    : crypto.getRandomValues(new Uint8Array(16))
  const bits = await crypto.subtle.deriveBits(
    { name: 'PBKDF2', salt: saltBytes, iterations: PBKDF2_ITERATIONS, hash: 'SHA-256' },
    keyMaterial,
    256
  )
  return `${b64(saltBytes)}:${b64(new Uint8Array(bits))}`
}

async function verifyPassword(password: string, stored: string | null): Promise<boolean> {
  // Always derive a hash so unknown emails cost the same as wrong passwords.
  const [salt] = (stored ?? `${DUMMY_SALT}:`).split(':')
  const computed = await hashPassword(password, salt)
  return stored !== null && timingSafeEqual(computed, stored)
}

function parsePassword(value: unknown): string | null {
  if (typeof value !== 'string' || value.length < 8 || value.length > 200) return null
  return value
}

function publicCustomer(row: { id: number; email: string; name: string | null }) {
  return { id: row.id, email: row.email, name: row.name }
}

type Guard = { db: D1Database; secret: string } | Response

function guards(c: Parameters<typeof requireDb>[0]): Guard {
  const db = requireDb(c)
  if (db instanceof Response) return db
  const secret = requireCartSecret(c)
  if (secret instanceof Response) return secret
  return { db, secret }
}

customers.post('/register', async (c) => {
  const g = guards(c)
  if (g instanceof Response) return g

  const body = await readJsonObject(c)
  if (!body) return c.json({ error: 'invalid_json' }, 400)

  const email = normalizeEmail(body.email)
  if (!email) return c.json({ error: 'invalid_email' }, 400)
  const password = parsePassword(body.password)
  if (!password) return c.json({ error: 'invalid_password' }, 400)
  const name = typeof body.name === 'string' ? body.name.trim().slice(0, 100) || null : null

  const passwordHash = await hashPassword(password)
  let id: number
  try {
    const result = await g.db
      .prepare('INSERT INTO customers (email, name, password_hash) VALUES (?1, ?2, ?3)')
      .bind(email, name, passwordHash)
      .run()
    id = Number(result.meta.last_row_id)
  } catch (error) {
    if (isUniqueViolation(error)) return c.json({ error: 'email_taken' }, 409)
    throw error
  }

  c.header('Set-Cookie', await customerSessionCookie(c, g.secret, id))
  return c.json(publicCustomer({ id, email, name }), 201)
})

customers.post('/login', async (c) => {
  const g = guards(c)
  if (g instanceof Response) return g

  const body = await readJsonObject(c)
  if (!body) return c.json({ error: 'invalid_json' }, 400)

  const email = normalizeEmail(body.email)
  const password = typeof body.password === 'string' ? body.password : ''
  if (!email || !password) return c.json({ error: 'invalid_credentials' }, 401)

  const row = await g.db
    .prepare('SELECT id, email, name, password_hash FROM customers WHERE email = ?1')
    .bind(email)
    .first<CustomerRow>()

  const ok = await verifyPassword(password, row?.password_hash ?? null)
  if (!row || !ok) return c.json({ error: 'invalid_credentials' }, 401)

  c.header('Set-Cookie', await customerSessionCookie(c, g.secret, row.id))
  return c.json(publicCustomer(row))
})

customers.post('/logout', async (c) => {
  c.header('Set-Cookie', clearCustomerCookie(c))
  return c.json({ ok: true })
})

customers.get('/me', async (c) => {
  const g = guards(c)
  if (g instanceof Response) return g

  const id = await customerIdFromRequest(c, g.secret)
  if (!id) return c.json({ error: 'unauthenticated' }, 401)

  const row = await g.db
    .prepare('SELECT id, email, name FROM customers WHERE id = ?1')
    .bind(id)
    .first<{ id: number; email: string; name: string | null }>()
  if (!row) {
    c.header('Set-Cookie', clearCustomerCookie(c))
    return c.json({ error: 'unauthenticated' }, 401)
  }

  return c.json(publicCustomer(row))
})

export default customers
