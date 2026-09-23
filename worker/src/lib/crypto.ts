const encoder = new TextEncoder()

export function bytesToHex(bytes: Uint8Array): string {
  let out = ''
  for (const b of bytes) out += b.toString(16).padStart(2, '0')
  return out
}

export function hexToBytes(hex: string): Uint8Array | null {
  if (hex.length === 0 || hex.length % 2 !== 0 || !/^[0-9a-fA-F]+$/.test(hex)) return null
  const bytes = new Uint8Array(hex.length / 2)
  for (let i = 0; i < bytes.length; i++) {
    bytes[i] = Number.parseInt(hex.slice(i * 2, i * 2 + 2), 16)
  }
  return bytes
}

export function randomHex(byteLength: number): string {
  return bytesToHex(crypto.getRandomValues(new Uint8Array(byteLength)))
}

export async function hmacBytes(secret: string, message: string): Promise<Uint8Array> {
  const key = await crypto.subtle.importKey(
    'raw',
    encoder.encode(secret),
    { name: 'HMAC', hash: 'SHA-256' },
    false,
    ['sign']
  )
  const sig = await crypto.subtle.sign('HMAC', key, encoder.encode(message))
  return new Uint8Array(sig)
}

export async function hmacHex(secret: string, message: string): Promise<string> {
  return bytesToHex(await hmacBytes(secret, message))
}

export async function sha256Hex(message: string): Promise<string> {
  const digest = await crypto.subtle.digest('SHA-256', encoder.encode(message))
  return bytesToHex(new Uint8Array(digest))
}

/**
 * Constant-time comparison of two strings (compared as UTF-8 bytes). Always
 * walks the longer of the two inputs so the runtime does not depend on where
 * the first mismatch is.
 */
export function timingSafeEqual(a: string, b: string): boolean {
  return timingSafeEqualBytes(encoder.encode(a), encoder.encode(b))
}

export function timingSafeEqualBytes(a: Uint8Array, b: Uint8Array): boolean {
  let diff = a.length ^ b.length
  const length = Math.max(a.length, b.length)
  for (let i = 0; i < length; i++) {
    diff |= (a[i] ?? 0) ^ (b[i] ?? 0)
  }
  return diff === 0
}

/**
 * Order token per contract C: 32 hex chars derived from
 * HMAC(CART_SECRET, order_id + session_id). Deterministic, so the token can be
 * re-derived for verification without trusting the stored column.
 */
export async function orderToken(secret: string, orderId: number, sessionId: string): Promise<string> {
  const mac = await hmacHex(secret, `order:${orderId}:${sessionId}`)
  return mac.slice(0, 32)
}
