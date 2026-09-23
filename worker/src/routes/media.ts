import { Hono } from 'hono'
import type { Env } from '../types'
import { contentTypeForKey, etagMatches, mediaKeyFromPath, parseRange, resolveRange } from '../lib/media-key'

const media = new Hono<Env>()

const CACHE_CONTROL = 'public, max-age=86400'

function baseHeaders(object: R2Object, key: string): Headers {
  const headers = new Headers()
  object.writeHttpMetadata(headers)
  if (!headers.get('content-type')) headers.set('content-type', contentTypeForKey(key))
  headers.set('etag', object.httpEtag)
  headers.set('cache-control', CACHE_CONTROL)
  headers.set('accept-ranges', 'bytes')
  headers.set('last-modified', object.uploaded.toUTCString())
  headers.set('x-content-type-options', 'nosniff')
  return headers
}

async function discard(object: R2ObjectBody | R2Object | null): Promise<void> {
  if (object && 'body' in object && object.body) {
    await object.body.cancel().catch(() => undefined)
  }
}

/**
 * Serves `userfiles/media/**` objects from R2. Key = 'media/' + path after
 * /media/ (contract A). Supports HEAD, single Range requests (206), ETag with
 * If-None-Match (304) and a 24h public cache.
 */
media.on(['GET', 'HEAD'], '/*', async (c) => {
  const key = mediaKeyFromPath(new URL(c.req.url).pathname)
  if (!key) return c.json({ error: 'not_found' }, 404)

  const bucket = c.env.MEDIA
  if (!bucket) {
    // Media disabled: the compiler bundles files as static assets instead.
    return c.env.ASSETS ? c.env.ASSETS.fetch(c.req.raw) : c.json({ error: 'not_found' }, 404)
  }

  const isHead = c.req.method === 'HEAD'
  const range = isHead ? null : parseRange(c.req.header('range'))

  let object: R2ObjectBody | null
  try {
    object = await bucket.get(key, range ? { range } : undefined)
  } catch {
    // R2 throws for unsatisfiable ranges; report 416 with the object size.
    const head = await bucket.head(key)
    if (!head) return c.json({ error: 'not_found' }, 404)
    const headers = baseHeaders(head, key)
    headers.set('content-range', `bytes */${head.size}`)
    return new Response(null, { status: 416, headers })
  }

  if (!object) return c.json({ error: 'not_found' }, 404)

  const headers = baseHeaders(object, key)

  if (etagMatches(c.req.header('if-none-match'), object.httpEtag)) {
    await discard(object)
    headers.delete('content-length')
    return new Response(null, { status: 304, headers })
  }

  if (isHead) {
    await discard(object)
    headers.set('content-length', String(object.size))
    return new Response(null, { status: 200, headers })
  }

  if (range) {
    const window = resolveRange(range, object.size)
    if (!window) {
      await discard(object)
      headers.set('content-range', `bytes */${object.size}`)
      return new Response(null, { status: 416, headers })
    }
    headers.set('content-range', `bytes ${window.start}-${window.end}/${object.size}`)
    headers.set('content-length', String(window.end - window.start + 1))
    return new Response(object.body, { status: 206, headers })
  }

  headers.set('content-length', String(object.size))
  return new Response(object.body, { status: 200, headers })
})

export default media
