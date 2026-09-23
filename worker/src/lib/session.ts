import type { Context } from 'hono'
import type { Env } from '../types'
import { hmacHex, timingSafeEqual } from './crypto'

export const CART_COOKIE = 'fw_cart'
export const CUSTOMER_COOKIE = 'fw_customer'
const CART_MAX_AGE = 60 * 60 * 24 * 30
const CUSTOMER_MAX_AGE = 60 * 60 * 24 * 14
const UUID_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/

export function readCookie(header: string | undefined | null, name: string): string | null {
  if (!header) return null
  for (const part of header.split(';')) {
    const eq = part.indexOf('=')
    if (eq === -1) continue
    if (part.slice(0, eq).trim() !== name) continue
    try {
      return decodeURIComponent(part.slice(eq + 1).trim())
    } catch {
      return null
    }
  }
  return null
}

/** `${value}.${hmacHex}`; the value itself must not be empty. */
export async function signValue(value: string, secret: string): Promise<string> {
  return `${value}.${await hmacHex(secret, value)}`
}

/** Returns the embedded value when the signature verifies (constant-time). */
export async function verifySignedValue(signed: string | null, secret: string): Promise<string | null> {
  if (!signed) return null
  const dot = signed.lastIndexOf('.')
  if (dot <= 0 || dot === signed.length - 1) return null
  const value = signed.slice(0, dot)
  const signature = signed.slice(dot + 1)
  const expected = await hmacHex(secret, value)
  return timingSafeEqual(signature, expected) ? value : null
}

export function buildCookie(name: string, value: string, maxAge: number, secure: boolean): string {
  const flags = `Path=/; HttpOnly; SameSite=Lax; Max-Age=${maxAge}${secure ? '; Secure' : ''}`
  return `${name}=${encodeURIComponent(value)}; ${flags}`
}

export function isSecureRequest(c: Context<Env>): boolean {
  return c.req.url.startsWith('https:')
}

/**
 * Resolves the signed cart id from the cookie or mints a new one. Callers must
 * have checked that CART_SECRET is configured (see requireCartSecret).
 */
export async function getCartId(
  c: Context<Env>,
  secret: string
): Promise<{ id: string; setCookie: string | null }> {
  const existing = await verifySignedValue(readCookie(c.req.header('cookie'), CART_COOKIE), secret)
  if (existing && UUID_RE.test(existing)) {
    return { id: existing, setCookie: null }
  }

  const id = crypto.randomUUID()
  const signed = await signValue(id, secret)
  return { id, setCookie: buildCookie(CART_COOKIE, signed, CART_MAX_AGE, isSecureRequest(c)) }
}

/** Customer session payload is `${customerId}:${expiresAtSeconds}`. */
export function parseCustomerSession(value: string | null, nowSeconds: number): number | null {
  if (!value) return null
  const match = /^(\d{1,12}):(\d{1,12})$/.exec(value)
  if (!match) return null
  const id = Number(match[1])
  const expiresAt = Number(match[2])
  if (!Number.isSafeInteger(id) || id <= 0 || expiresAt <= nowSeconds) return null
  return id
}

export async function customerSessionCookie(
  c: Context<Env>,
  secret: string,
  customerId: number
): Promise<string> {
  const expiresAt = Math.floor(Date.now() / 1000) + CUSTOMER_MAX_AGE
  const signed = await signValue(`${customerId}:${expiresAt}`, secret)
  return buildCookie(CUSTOMER_COOKIE, signed, CUSTOMER_MAX_AGE, isSecureRequest(c))
}

export function clearCustomerCookie(c: Context<Env>): string {
  return buildCookie(CUSTOMER_COOKIE, '', 0, isSecureRequest(c))
}

export async function customerIdFromRequest(c: Context<Env>, secret: string): Promise<number | null> {
  const value = await verifySignedValue(readCookie(c.req.header('cookie'), CUSTOMER_COOKIE), secret)
  return parseCustomerSession(value, Math.floor(Date.now() / 1000))
}
