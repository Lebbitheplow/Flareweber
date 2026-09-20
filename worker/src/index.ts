import { Hono } from 'hono'
import type { Env } from './types'
import products from './routes/products'
import cart from './routes/cart'
import checkout from './routes/checkout'
import orders from './routes/orders'
import customers from './routes/customers'
import webhooks from './routes/webhooks'
import forms from './routes/forms'

const api = new Hono<Env>()

api.get('/health', (c) => c.json({ ok: true, site: c.env.SITE_ID }))
api.route('/products', products)
api.route('/cart', cart)
api.route('/checkout', checkout)
api.route('/orders', orders)
api.route('/customers', customers)
api.route('/webhooks', webhooks)
api.route('/forms', forms)

const app = new Hono<Env>()

app.route('/api', api)

app.notFound((c) => c.env.ASSETS.fetch(c.req.raw))

app.onError((error, c) => {
  console.error(error)
  return c.json({ error: 'internal_error' }, 500)
})

export default {
  fetch(request: Request, env: Env['Bindings'], ctx: ExecutionContext): Response | Promise<Response> {
    const url = new URL(request.url)

    if (!url.pathname.startsWith('/api/')) {
      const asset = env.ASSETS.fetch(request)
      return asset.then((response) =>
        response.status === 404 ? app.fetch(request, env, ctx) : response
      )
    }

    return app.fetch(request, env, ctx)
  },
}
