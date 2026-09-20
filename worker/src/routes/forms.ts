import { Hono } from 'hono'
import type { Env } from '../types'

const forms = new Hono<Env>()

forms.post('/', async (c) => {
  if (!c.env.DB) return c.json({ error: 'database_not_configured' }, 503)

  const body = await c.req.json<{ form?: string; data?: Record<string, unknown> }>()
  if (!body.form || typeof body.data !== 'object') {
    return c.json({ error: 'form and data required' }, 400)
  }

  await c.env.DB
    .prepare('INSERT INTO form_entries (form, data_json) VALUES (?1, ?2)')
    .bind(String(body.form).slice(0, 100), JSON.stringify(body.data).slice(0, 32_000))
    .run()

  return c.json({ ok: true }, 201)
})

export default forms
