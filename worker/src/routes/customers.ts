import { Hono } from 'hono'
import type { Env } from '../types'

const customers = new Hono<Env>()

const encoder = new TextEncoder()

async function hashPassword(password: string, salt?: string): Promise<string> {
  const keyMaterial = await crypto.subtle.importKey('raw', encoder.encode(password), 'PBKDF2', false, ['deriveBits'])
  const saltBytes = salt
    ? Uint8Array.from(atob(salt), (ch) => ch.charCodeAt(0))
    : crypto.getRandomValues(new Uint8Array(16))

  const bits = await crypto.subtle.deriveBits(
    { name: 'PBKDF2', salt: saltBytes, iterations: 100_000, hash: 'SHA-256' },
    keyMaterial,
    256
  )

  return `${btoa(String.fromCharCode(...saltBytes))}:${btoa(String.fromCharCode(...new Uint8Array(bits)))}`
}

async function verifyPassword(password: string, stored: string): Promise<boolean> {
  const [salt] = stored.split(':')
  return (await hashPassword(password, salt)) === stored
}

customers.post('/register', async (c) => {
  if (!c.env.DB) return c.json({ error: 'database_not_configured' }, 503)

  const body = await c.req.json<{ email?: string; name?: string; password?: string }>()
  if (!body.email || !body.password) return c.json({ error: 'email and password required' }, 400)

  const existing = await c.env.DB
    .prepare('SELECT id FROM customers WHERE email = ?1')
    .bind(body.email)
    .first()

  if (existing) return c.json({ error: 'email_taken' }, 409)

  const passwordHash = await hashPassword(body.password)
  await c.env.DB
    .prepare('INSERT INTO customers (email, name, password_hash) VALUES (?1, ?2, ?3)')
    .bind(body.email, body.name ?? null, passwordHash)
    .run()

  return c.json({ ok: true }, 201)
})

export default customers
