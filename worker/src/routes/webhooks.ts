import { Hono } from 'hono'
import type { Env } from '../types'
import { verifyStripeSignature } from '../lib/stripe'
import { isUniqueViolation, requireDb } from '../lib/http'
import { normalizeEmail } from '../lib/validate'

const webhooks = new Hono<Env>()

const HANDLED_EVENTS = new Set([
  'checkout.session.completed',
  'checkout.session.async_payment_succeeded',
  'checkout.session.async_payment_failed',
  'checkout.session.expired',
  'charge.refunded',
])

type StripeEvent = { id: string; type: string; data: { object: Record<string, unknown> } }

type OrderRow = { id: number; status: string; total_cents: number; currency: string; cart_id: string | null }

type Outcome = { received: true; ignored?: string; duplicate?: boolean }

function recordEvent(db: D1Database, event: StripeEvent): D1PreparedStatement {
  return db.prepare('INSERT INTO webhook_events (id, type) VALUES (?1, ?2)').bind(event.id, event.type)
}

/** Runs the batch; a duplicate event id makes the whole batch a no-op. */
async function runOnce(db: D1Database, statements: D1PreparedStatement[]): Promise<Outcome> {
  try {
    await db.batch(statements)
    return { received: true }
  } catch (error) {
    if (isUniqueViolation(error)) return { received: true, duplicate: true }
    throw error
  }
}

async function ignore(db: D1Database, event: StripeEvent, reason: string): Promise<Outcome> {
  await db.prepare('INSERT OR IGNORE INTO webhook_events (id, type) VALUES (?1, ?2)').bind(event.id, event.type).run()
  return { received: true, ignored: reason }
}

async function orderForSession(db: D1Database, sessionId: unknown): Promise<OrderRow | null> {
  if (typeof sessionId !== 'string' || !sessionId) return null
  return db
    .prepare('SELECT id, status, total_cents, currency, cart_id FROM orders WHERE stripe_session_id = ?1')
    .bind(sessionId)
    .first<OrderRow>()
}

/**
 * Marks the order paid and decrements inventory in ONE batch. Every statement
 * is guarded by the order still being `pending`, so a second delivery (or a
 * second event type for the same session) never decrements twice.
 */
async function handlePaid(db: D1Database, event: StripeEvent): Promise<Outcome> {
  const session = event.data.object
  if (session.payment_status !== 'paid') return ignore(db, event, 'not_paid')

  const order = await orderForSession(db, session.id)
  if (!order) return ignore(db, event, 'unknown_order')
  if (order.status !== 'pending') return ignore(db, event, 'already_processed')

  const amountTotal = typeof session.amount_total === 'number' ? session.amount_total : null
  // Promotion codes reduce amount_total; the pre-discount subtotal must match the order.
  const amountCheck = typeof session.amount_subtotal === 'number' ? session.amount_subtotal : amountTotal
  const currency = typeof session.currency === 'string' ? session.currency.toUpperCase() : order.currency
  const paymentIntent =
    typeof session.payment_intent === 'string'
      ? session.payment_intent
      : ((session.payment_intent as { id?: string } | null)?.id ?? null)
  const details = session.customer_details as { email?: unknown } | null | undefined
  const email = normalizeEmail(details?.email)

  if (amountCheck !== order.total_cents || currency !== order.currency.toUpperCase()) {
    console.error(`webhook amount mismatch for order ${order.id}: ${amountCheck} ${currency}`)
    await runOnce(db, [
      recordEvent(db, event),
      db
        .prepare(`UPDATE orders SET status = 'review', payment_status = 'paid', paid_cents = ?2,
                  stripe_payment_intent_id = ?3, email = COALESCE(email, ?4), updated_at = datetime('now')
                  WHERE id = ?1 AND status = 'pending'`)
        .bind(order.id, amountTotal, paymentIntent, email),
    ])
    return { received: true, ignored: 'amount_mismatch' }
  }

  const { results: items } = await db
    .prepare('SELECT variant_id, quantity FROM order_items WHERE order_id = ?1')
    .bind(order.id)
    .all<{ variant_id: number; quantity: number }>()

  const statements: D1PreparedStatement[] = [
    recordEvent(db, event),
    ...items.map((item) =>
      db
        .prepare(`UPDATE inventory SET quantity = MAX(quantity - ?2, 0)
                  WHERE variant_id = ?1 AND quantity >= 0
                    AND EXISTS (SELECT 1 FROM orders WHERE id = ?3 AND status = 'pending')`)
        .bind(item.variant_id, item.quantity, order.id)
    ),
    db
      .prepare(`UPDATE orders SET status = 'paid', payment_status = 'paid', paid_cents = ?2,
                stripe_payment_intent_id = ?3, email = COALESCE(email, ?4), updated_at = datetime('now')
                WHERE id = ?1 AND status = 'pending'`)
      .bind(order.id, amountTotal, paymentIntent, email),
  ]
  if (order.cart_id) {
    statements.push(db.prepare('DELETE FROM cart_items WHERE cart_id = ?1').bind(order.cart_id))
  }
  return runOnce(db, statements)
}

async function handleSessionStatus(
  db: D1Database,
  event: StripeEvent,
  status: string,
  paymentStatus: string | null
): Promise<Outcome> {
  const order = await orderForSession(db, event.data.object.id)
  if (!order) return ignore(db, event, 'unknown_order')
  if (order.status !== 'pending') return ignore(db, event, 'already_processed')

  return runOnce(db, [
    recordEvent(db, event),
    db
      .prepare(`UPDATE orders SET status = ?2, payment_status = COALESCE(?3, payment_status),
                updated_at = datetime('now') WHERE id = ?1 AND status = 'pending'`)
      .bind(order.id, status, paymentStatus),
  ])
}

/** Refunds do not restock inventory; merchants adjust stock in Microweber. */
async function handleRefund(db: D1Database, event: StripeEvent): Promise<Outcome> {
  const charge = event.data.object
  const intent =
    typeof charge.payment_intent === 'string'
      ? charge.payment_intent
      : ((charge.payment_intent as { id?: string } | null)?.id ?? null)
  if (!intent) return ignore(db, event, 'no_payment_intent')

  const order = await db
    .prepare('SELECT id, status FROM orders WHERE stripe_payment_intent_id = ?1')
    .bind(intent)
    .first<{ id: number; status: string }>()
  if (!order) return ignore(db, event, 'unknown_order')

  const amount = typeof charge.amount === 'number' ? charge.amount : 0
  const refunded = typeof charge.amount_refunded === 'number' ? charge.amount_refunded : 0
  const full = charge.refunded === true || (amount > 0 && refunded >= amount)

  return runOnce(db, [
    recordEvent(db, event),
    db
      .prepare(`UPDATE orders SET payment_status = ?2,
                status = CASE WHEN ?3 = 1 THEN 'refunded' ELSE status END,
                updated_at = datetime('now')
                WHERE id = ?1 AND status IN ('paid', 'fulfilled', 'review')`)
      .bind(order.id, full ? 'refunded' : 'partially_refunded', full ? 1 : 0),
  ])
}

webhooks.post('/stripe', async (c) => {
  if (!c.env.STRIPE_WEBHOOK_SECRET) return c.json({ error: 'webhook_not_configured' }, 503)
  const db = requireDb(c)
  if (db instanceof Response) return db

  const signature = c.req.header('stripe-signature')
  const payload = await c.req.text()
  if (!signature || !(await verifyStripeSignature(payload, signature, c.env.STRIPE_WEBHOOK_SECRET))) {
    return c.json({ error: 'invalid_signature' }, 400)
  }

  let event: StripeEvent
  try {
    event = JSON.parse(payload) as StripeEvent
  } catch {
    return c.json({ error: 'invalid_payload' }, 400)
  }
  if (typeof event?.id !== 'string' || typeof event.type !== 'string' || typeof event.data?.object !== 'object') {
    return c.json({ error: 'invalid_payload' }, 400)
  }

  if (!HANDLED_EVENTS.has(event.type)) return c.json(await ignore(db, event, 'unhandled_type'))

  const seen = await db.prepare('SELECT 1 AS seen FROM webhook_events WHERE id = ?1').bind(event.id).first()
  if (seen) return c.json({ received: true, duplicate: true })

  switch (event.type) {
    case 'checkout.session.completed':
    case 'checkout.session.async_payment_succeeded':
      return c.json(await handlePaid(db, event))
    case 'checkout.session.async_payment_failed':
      return c.json(await handleSessionStatus(db, event, 'cancelled', 'failed'))
    case 'checkout.session.expired':
      return c.json(await handleSessionStatus(db, event, 'expired', null))
    case 'charge.refunded':
      return c.json(await handleRefund(db, event))
    default:
      return c.json(await ignore(db, event, 'unhandled_type'))
  }
})

export default webhooks
