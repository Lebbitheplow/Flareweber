import { Hono } from 'hono'
import type { Env } from '../types'

const media = new Hono<Env>()

// Serve a media object out of the site's R2 bucket. Objects live under the
// "media/" prefix (see FlareWeber MediaSyncService), so /media/foo/bar.png
// maps to the R2 key "media/foo/bar.png".
media.get('*', async (c) => {
  if (!c.env.MEDIA) return c.json({ error: 'media_not_configured' }, 503)

  const key = decodeURIComponent(c.req.path.replace(/^\/media\/?/, ''))
  if (!key || key.includes('..')) return c.notFound()

  const object = await c.env.MEDIA.get(key)
  if (!object) return c.notFound()

  const headers: Record<string, string> = {
    'content-type': object.httpMetadata?.contentType ?? 'application/octet-stream',
    'cache-control': 'public, max-age=31536000, immutable',
    etag: `"${object.etag}"`,
  }

  const notModified = c.req.header('if-none-match') === headers.etag
  if (notModified || c.req.method === 'HEAD') {
    object.body?.cancel()
    return new Response(null, { status: notModified ? 304 : 200, headers })
  }

  return new Response(object.body, { status: 200, headers })
})

export default media
