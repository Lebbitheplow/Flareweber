export const FORM_LIMITS = {
  maxFields: 50,
  maxKeyLength: 100,
  maxValueLength: 10_000,
  maxJsonBytes: 32_000,
  maxFormName: 100,
}

export function isPlainObject(value: unknown): value is Record<string, unknown> {
  if (typeof value !== 'object' || value === null || Array.isArray(value)) return false
  const proto = Object.getPrototypeOf(value)
  return proto === Object.prototype || proto === null
}

export function isPositiveInt(value: unknown): value is number {
  return typeof value === 'number' && Number.isSafeInteger(value) && value > 0
}

const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/

export function normalizeEmail(value: unknown): string | null {
  if (typeof value !== 'string') return null
  const email = value.trim().toLowerCase()
  if (email.length === 0 || email.length > 254 || !EMAIL_RE.test(email)) return null
  return email
}

export type FormValidation =
  | { ok: true; form: string; data: Record<string, string> }
  | { ok: false; error: string }

/**
 * Validates a form submission body: `form` is a short slug, `data` is a plain
 * object of string values, `_hp` (honeypot) must be empty.
 */
export function validateFormSubmission(body: unknown): FormValidation {
  if (!isPlainObject(body)) return { ok: false, error: 'invalid_body' }

  const form = body.form
  if (typeof form !== 'string' || !/^[A-Za-z0-9_-]{1,100}$/.test(form)) {
    return { ok: false, error: 'invalid_form' }
  }

  const raw = body.data
  if (!isPlainObject(raw)) return { ok: false, error: 'invalid_data' }

  const hp = raw._hp
  if (hp !== undefined && hp !== '') return { ok: false, error: 'rejected' }

  const data: Record<string, string> = {}
  let count = 0
  for (const [key, value] of Object.entries(raw)) {
    if (key === '_hp') continue
    if (++count > FORM_LIMITS.maxFields) return { ok: false, error: 'too_many_fields' }
    if (key.length === 0 || key.length > FORM_LIMITS.maxKeyLength) return { ok: false, error: 'invalid_field' }
    if (typeof value !== 'string') return { ok: false, error: 'invalid_field' }
    if (value.length > FORM_LIMITS.maxValueLength) return { ok: false, error: 'field_too_long' }
    data[key] = value
  }

  if (count === 0) return { ok: false, error: 'empty_data' }
  if (JSON.stringify(data).length > FORM_LIMITS.maxJsonBytes) return { ok: false, error: 'payload_too_large' }

  return { ok: true, form, data }
}
