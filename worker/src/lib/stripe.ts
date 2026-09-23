import { hmacHex, timingSafeEqual } from './crypto'

export const STRIPE_API_VERSION = '2025-03-31.basil'

export type CheckoutLineItem = {
  name: string
  description?: string | null
  images?: string[]
  currency: string
  unitAmount: number
  quantity: number
}

export type CheckoutSessionParams = {
  items: CheckoutLineItem[]
  successUrl: string
  cancelUrl: string
  clientReferenceId: string
  customerEmail?: string | null
  metadata?: Record<string, string>
  idempotencyKey: string
}

export class StripeApiError extends Error {
  status: number
  constructor(status: number, message: string) {
    super(message)
    this.name = 'StripeApiError'
    this.status = status
  }
}

/** Pure builder for the form-encoded Checkout Session body (testable). */
export function buildCheckoutSessionBody(params: CheckoutSessionParams): URLSearchParams {
  const body = new URLSearchParams()
  params.items.forEach((item, i) => {
    const prefix = `line_items[${i}]`
    body.set(`${prefix}[price_data][currency]`, item.currency.toLowerCase())
    body.set(`${prefix}[price_data][unit_amount]`, String(item.unitAmount))
    body.set(`${prefix}[price_data][product_data][name]`, item.name.slice(0, 250))
    if (item.description) {
      body.set(`${prefix}[price_data][product_data][description]`, item.description.slice(0, 500))
    }
    ;(item.images ?? []).slice(0, 8).forEach((url, j) => {
      body.set(`${prefix}[price_data][product_data][images][${j}]`, url)
    })
    body.set(`${prefix}[quantity]`, String(item.quantity))
  })
  body.set('mode', 'payment')
  body.set('success_url', params.successUrl)
  body.set('cancel_url', params.cancelUrl)
  body.set('client_reference_id', params.clientReferenceId)
  body.set('allow_promotion_codes', 'true')
  if (params.customerEmail) body.set('customer_email', params.customerEmail)
  for (const [key, value] of Object.entries(params.metadata ?? {})) {
    body.set(`metadata[${key}]`, value)
  }
  return body
}

export async function createCheckoutSession(
  secretKey: string,
  params: CheckoutSessionParams
): Promise<{ id: string; url: string | null }> {
  const response = await fetch('https://api.stripe.com/v1/checkout/sessions', {
    method: 'POST',
    headers: {
      Authorization: `Bearer ${secretKey}`,
      'Content-Type': 'application/x-www-form-urlencoded',
      'Stripe-Version': STRIPE_API_VERSION,
      'Idempotency-Key': params.idempotencyKey,
    },
    body: buildCheckoutSessionBody(params),
  })

  if (!response.ok) {
    let message = `HTTP ${response.status}`
    try {
      const json = await response.json() as { error?: { message?: string } }
      message = json.error?.message ?? message
    } catch {
      // keep the HTTP status message
    }
    throw new StripeApiError(response.status, message)
  }

  const json = await response.json() as { id: string; url: string | null }
  return { id: json.id, url: json.url }
}

/**
 * Verifies a `Stripe-Signature` header (t=...,v1=...) against the raw payload.
 * `nowSeconds` is injectable for tests.
 */
export async function verifyStripeSignature(
  payload: string,
  header: string,
  secret: string,
  nowSeconds: number = Math.floor(Date.now() / 1000),
  toleranceSeconds = 300
): Promise<boolean> {
  let timestamp: string | null = null
  const signatures: string[] = []

  for (const part of header.split(',')) {
    const eq = part.indexOf('=')
    if (eq === -1) continue
    const key = part.slice(0, eq).trim()
    const value = part.slice(eq + 1).trim()
    if (key === 't' && value) timestamp = value
    else if (key === 'v1' && value) signatures.push(value.toLowerCase())
  }

  if (!timestamp || !/^\d+$/.test(timestamp) || signatures.length === 0) return false
  if (Math.abs(nowSeconds - Number(timestamp)) > toleranceSeconds) return false

  const expected = await hmacHex(secret, `${timestamp}.${payload}`)
  let ok = false
  for (const signature of signatures) {
    if (timingSafeEqual(signature, expected)) ok = true
  }
  return ok
}
