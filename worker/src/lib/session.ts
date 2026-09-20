import type { Context } from 'hono'
import type { Env } from '../types'

const COOKIE = 'fw_cart'
const encoder = new TextEncoder()

async function sign(value: string, secret: string): Promise<string> {
  const key = await crypto.subtle.importKey('raw', encoder.encode(secret), {
    name: 'HMAC',
    hash: 'SHA-256',
  }, false, ['sign'])
  const sig = await crypto.subtle.sign('HMAC', key, encoder.encode(value))
  return btoa(String.fromCharCode(...new Uint8Array(sig)))
}

function readCookie(header: string | undefined | null, name: string): string | null {
  if (!header) return null
  for (const part of header.split(';')) {
    const [k, v] = part.trim().split('=')
    if (k === name) return decodeURIComponent(v ?? '')
  }
  return null
}

export async function getCartId(c: Context<Env>): Promise<{ id: string; setCookie: string | null }> {
  const secret = c.env.CART_SECRET ?? 'dev-secret'
  const raw = readCookie(c.req.header('cookie'), COOKIE)

  if (raw) {
    const [id, sig] = raw.split('.')
    if (id && sig === await sign(id, secret)) {
      return { id, setCookie: null }
    }
  }

  const id = crypto.randomUUID()
  const value = `${id}.${await sign(id, secret)}`
  return {
    id,
    setCookie: `${COOKIE}=${encodeURIComponent(value)}; Path=/; HttpOnly; SameSite=Lax; Max-Age=2592000`,
  }
}

export async function withCart<T>(
  c: Context<Env>,
  handler: (cartId: string) => Promise<T>
): Promise<T> {
  const { id } = await getCartId(c)
  return handler(id)
}
