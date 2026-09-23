/**
 * Pure helpers for the /media/* route (kept free of Hono so they can be unit
 * tested with plain node).
 */

const MEDIA_PREFIX = '/media/'

function decodeSegment(segment: string): string {
  if (!segment.includes('%')) return segment
  try {
    return decodeURIComponent(segment)
  } catch {
    // A literal percent sign that is not a valid escape stays as-is.
    return segment
  }
}

/**
 * Maps a raw (still percent-encoded) URL pathname to the R2 key per contract A:
 * `/media/default/hero.jpg` -> `media/default/hero.jpg`. Each segment is
 * decoded exactly once. Returns null for empty, dot-segment or control-char paths.
 */
export function mediaKeyFromPath(rawPathname: string): string | null {
  if (!rawPathname.startsWith(MEDIA_PREFIX)) return null
  const rest = rawPathname.slice(MEDIA_PREFIX.length)
  if (rest.length === 0 || rest.length > 1024) return null

  const segments = rest.split('/').map(decodeSegment)
  for (const segment of segments) {
    if (segment === '' || segment === '.' || segment === '..') return null
    if (/[\\\u0000-\u001f]/.test(segment)) return null
  }
  return 'media/' + segments.join('/')
}

export type ByteRange = { offset: number; length?: number } | { suffix: number }

/**
 * Parses a single-range `Range: bytes=...` header. Multi-range and malformed
 * headers return null (the caller then serves the whole object with 200).
 */
export function parseRange(header: string | undefined | null): ByteRange | null {
  if (!header) return null
  const match = /^\s*bytes\s*=\s*(\d*)\s*-\s*(\d*)\s*$/i.exec(header)
  if (!match) return null
  const [, startText, endText] = match

  if (startText === '' && endText === '') return null
  if (startText === '') {
    const suffix = Number(endText)
    return suffix > 0 ? { suffix } : null
  }

  const offset = Number(startText)
  if (!Number.isSafeInteger(offset)) return null
  if (endText === '') return { offset }

  const end = Number(endText)
  if (!Number.isSafeInteger(end) || end < offset) return null
  return { offset, length: end - offset + 1 }
}

/** Resolves the actual byte window for Content-Range given the object size. */
export function resolveRange(range: ByteRange, size: number): { start: number; end: number } | null {
  if ('suffix' in range) {
    if (size === 0) return null
    const start = Math.max(size - range.suffix, 0)
    return { start, end: size - 1 }
  }
  if (range.offset >= size) return null
  const end = range.length === undefined ? size - 1 : Math.min(range.offset + range.length - 1, size - 1)
  return { start: range.offset, end }
}

/** If-None-Match handling: `*`, weak validators and comma separated lists. */
export function etagMatches(header: string | undefined | null, etag: string): boolean {
  if (!header) return false
  const normalize = (value: string) => value.trim().replace(/^W\//, '').replace(/^"|"$/g, '')
  const target = normalize(etag)
  for (const candidate of header.split(',')) {
    const value = candidate.trim()
    if (value === '*') return true
    if (normalize(value) === target) return true
  }
  return false
}

const CONTENT_TYPES: Record<string, string> = {
  jpg: 'image/jpeg', jpeg: 'image/jpeg', png: 'image/png', gif: 'image/gif', webp: 'image/webp',
  avif: 'image/avif', svg: 'image/svg+xml', ico: 'image/x-icon', bmp: 'image/bmp',
  mp4: 'video/mp4', webm: 'video/webm', mov: 'video/quicktime',
  mp3: 'audio/mpeg', wav: 'audio/wav', ogg: 'audio/ogg', m4a: 'audio/mp4',
  pdf: 'application/pdf', txt: 'text/plain; charset=utf-8', csv: 'text/csv; charset=utf-8',
  woff: 'font/woff', woff2: 'font/woff2', ttf: 'font/ttf', otf: 'font/otf',
  eot: 'application/vnd.ms-fontobject', zip: 'application/zip',
}

export function contentTypeForKey(key: string): string {
  const ext = key.slice(key.lastIndexOf('.') + 1).toLowerCase()
  return CONTENT_TYPES[ext] ?? 'application/octet-stream'
}
