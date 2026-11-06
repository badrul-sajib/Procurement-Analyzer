/**
 * Thin fetch client for /api/v1. Session-cookie auth (Sanctum SPA): the browser sends the
 * session cookie; we echo the XSRF-TOKEN cookie back as a header on writes.
 */
export type FieldErrors = Record<string, string[]>

export class ApiError extends Error {
  readonly status: number
  readonly code?: string
  readonly errors: FieldErrors

  constructor(status: number, message: string, code?: string, errors: FieldErrors = {}) {
    super(message)
    this.status = status
    this.code = code
    this.errors = errors
  }
}

export type PageMeta = { current_page: number; per_page: number; total: number; last_page: number; from: number | null; to: number | null }
export type Paginated<T> = { data: T[]; meta: PageMeta }

type Params = Record<string, string | number | boolean | null | undefined | (string | number)[]>
type Options = { params?: Params; body?: unknown; headers?: Record<string, string> }

const BASE = '/api/v1'
let onUnauthenticated: () => void = () => {}
export const setUnauthenticatedHandler = (fn: () => void) => (onUnauthenticated = fn)

function xsrfToken(): string | undefined {
  const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/)
  return match ? decodeURIComponent(match[1]) : undefined
}

export async function ensureCsrfCookie() {
  await fetch('/sanctum/csrf-cookie', { credentials: 'include', headers: { Accept: 'application/json' } })
}

export function toQuery(params: Params = {}): string {
  const search = new URLSearchParams()
  for (const [key, value] of Object.entries(params)) {
    if (value === undefined || value === null || value === '') continue
    if (Array.isArray(value)) value.forEach((v) => search.append(`${key}[]`, String(v)))
    else search.set(key, String(value))
  }
  const qs = search.toString()
  return qs ? `?${qs}` : ''
}

async function request<T>(method: string, path: string, options: Options = {}, retried = false): Promise<T> {
  const isForm = options.body instanceof FormData
  const headers: Record<string, string> = {
    Accept: 'application/json',
    'X-Requested-With': 'XMLHttpRequest',
    ...(isForm || options.body === undefined ? {} : { 'Content-Type': 'application/json' }),
    ...options.headers,
  }
  const token = xsrfToken()
  if (token) headers['X-XSRF-TOKEN'] = token

  const response = await fetch(`${BASE}${path}${toQuery(options.params)}`, {
    method,
    headers,
    credentials: 'include',
    body: options.body === undefined ? undefined : isForm ? (options.body as FormData) : JSON.stringify(options.body),
  })

  // Expired CSRF token: refresh the cookie once and retry.
  if (response.status === 419 && !retried) {
    await ensureCsrfCookie()
    return request<T>(method, path, options, true)
  }
  if (response.status === 204) return undefined as T

  const payload = await response.json().catch(() => ({}))
  if (!response.ok) {
    if (response.status === 401) onUnauthenticated()
    const message = payload.message ?? `Request failed (${response.status})`
    throw new ApiError(response.status, message, payload.code, payload.errors ?? {})
  }
  return payload as T
}

export const api = {
  get: <T>(path: string, params?: Params) => request<T>('GET', path, { params }),
  post: <T>(path: string, body?: unknown, headers?: Record<string, string>) => request<T>('POST', path, { body, headers }),
  put: <T>(path: string, body?: unknown) => request<T>('PUT', path, { body }),
  delete: <T>(path: string) => request<T>('DELETE', path),
}

/** Download a file endpoint (e.g. original document, export) through the authenticated session. */
export function downloadUrl(path: string): string {
  return `${BASE}${path}`
}
