import type { Context } from 'hono'
import type { Env } from '../types'
import { isPlainObject } from './validate'

/**
 * Reads the request body as a JSON object. Returns `{}` for an empty body and
 * null when the body is not valid JSON or not a plain object.
 */
export async function readJsonObject(c: Context<Env>): Promise<Record<string, unknown> | null> {
  const text = await c.req.text()
  if (text.trim().length === 0) return {}
  try {
    const value: unknown = JSON.parse(text)
    return isPlainObject(value) ? value : null
  } catch {
    return null
  }
}

export function clientIp(c: Context<Env>): string {
  const cf = c.req.header('cf-connecting-ip')
  if (cf) return cf.trim()
  const forwarded = c.req.header('x-forwarded-for')
  if (forwarded) return forwarded.split(',')[0].trim()
  return 'unknown'
}

export function requireDb(c: Context<Env>): D1Database | Response {
  return c.env.DB ?? c.json({ error: 'database_not_configured' }, 503)
}

export function requireCartSecret(c: Context<Env>): string | Response {
  const secret = c.env.CART_SECRET
  if (!secret || secret.length < 16) return c.json({ error: 'cart_not_configured' }, 503)
  return secret
}

export function isUniqueViolation(error: unknown): boolean {
  return error instanceof Error && /UNIQUE constraint failed/i.test(error.message)
}
