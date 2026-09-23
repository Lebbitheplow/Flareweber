import { Hono } from 'hono'
import { HTTPException } from 'hono/http-exception'
import type { Bindings, Env } from './types'
import products from './routes/products'
import cart from './routes/cart'
import checkout from './routes/checkout'
import orders from './routes/orders'
import customers from './routes/customers'
import webhooks from './routes/webhooks'
import forms from './routes/forms'
import media from './routes/media'

const WORKER_VERSION = '1.1.0'

const api = new Hono<Env>()

api.get('/health', async (c) => {
  let db = false
  if (c.env.DB) {
    try {
      await c.env.DB.prepare('SELECT 1').first()
      db = true
    } catch {
      db = false
    }
  }
  return c.json({ ok: true, db, media: Boolean(c.env.MEDIA), version: WORKER_VERSION })
})

api.route('/products', products)
api.route('/cart', cart)
api.route('/checkout', checkout)
api.route('/orders', orders)
api.route('/customers', customers)
api.route('/webhooks', webhooks)
api.route('/forms', forms)

const app = new Hono<Env>()

app.route('/api', api)
app.route('/media', media)

app.notFound((c) => c.json({ error: 'not_found' }, 404))

app.onError((error, c) => {
  if (error instanceof HTTPException) {
    return c.json({ error: error.message || 'request_error' }, error.status)
  }
  if (error instanceof SyntaxError) {
    return c.json({ error: 'invalid_json' }, 400)
  }
  // Log the message only; never return a stack trace to the client.
  console.error('unhandled error:', error instanceof Error ? error.message : String(error))
  return c.json({ error: 'internal_error' }, 500)
})

function isDynamic(pathname: string): boolean {
  return pathname === '/api' || pathname.startsWith('/api/') || pathname === '/media' || pathname.startsWith('/media/')
}

export default {
  fetch(request: Request, env: Bindings, ctx: ExecutionContext): Response | Promise<Response> {
    const url = new URL(request.url)

    // API and R2-backed media are dynamic; everything else is served from the
    // static asset bundle exactly once (404.html handling is configured on the
    // ASSETS binding, so a 404 from it is final).
    if (isDynamic(url.pathname)) {
      return app.fetch(request, env, ctx)
    }

    if (!env.ASSETS) {
      return new Response('Not found', { status: 404, headers: { 'content-type': 'text/plain' } })
    }
    return env.ASSETS.fetch(request)
  },
}
