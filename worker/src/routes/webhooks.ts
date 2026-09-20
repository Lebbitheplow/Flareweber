import { Hono } from 'hono'
import type { Env } from '../types'

const webhooks = new Hono<Env>()

const encoder = new TextEncoder()

async function verifyStripeSignature(payload: string, signatureHeader: string, secret: string): Promise<boolean> {
  const parts = Object.fromEntries(
    signatureHeader.split(',').map((part) => part.trim().split('=') as [string, string])
  )
  const timestamp = parts['t']
  const signatures = signatureHeader.split(',').filter((p) => p.startsWith('v1=')).map((p) => p.slice(3))

  if (!timestamp || !signatures.length) return false
  if (Math.abs(Date.now() / 1000 - Number(timestamp)) > 300) return false

  const key = await crypto.subtle.importKey('raw', encoder.encode(secret), { name: 'HMAC', hash: 'SHA-256' }, false, ['verify'])
  const expected = await crypto.subtle.sign('HMAC', key, encoder.encode(`${timestamp}.${payload}`))
  const expectedB64 = btoa(String.fromCharCode(...new Uint8Array(expected)))

  return signatures.includes(expectedB64)
}

webhooks.post('/stripe', async (c) => {
  if (!c.env.STRIPE_WEBHOOK_SECRET) return c.json({ error: 'webhook_not_configured' }, 503)
  if (!c.env.DB) return c.json({ error: 'database_not_configured' }, 503)

  const signature = c.req.header('stripe-signature')
  const payload = await c.req.text()

  if (!signature || !await verifyStripeSignature(payload, signature, c.env.STRIPE_WEBHOOK_SECRET)) {
    return c.json({ error: 'invalid_signature' }, 400)
  }

  const event = JSON.parse(payload) as {
    type: string
    data: { object: Record<string, unknown> }
  }

  if (event.type === 'checkout.session.completed') {
    const session = event.data.object as {
      client_reference_id?: string
      payment_status?: string
    }
    const orderId = Number(session.client_reference_id)

    if (orderId) {
      const db = c.env.DB
      const items = await db
        .prepare('SELECT variant_id, quantity FROM order_items WHERE order_id = ?1')
        .bind(orderId)
        .all<{ variant_id: number; quantity: number }>()

      const batch = [
        db.prepare(`UPDATE orders SET payment_status = 'paid', status = 'processing' WHERE id = ?1`).bind(orderId),
        ...(items.results ?? []).map((item) =>
          db.prepare(
            'UPDATE inventory SET quantity = MAX(quantity - ?2, 0) WHERE variant_id = ?1'
          ).bind(item.variant_id, item.quantity)
        ),
      ]
      await db.batch(batch)
    }
  }

  return c.json({ received: true })
})

export default webhooks
