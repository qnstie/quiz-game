const API_BASE = (import.meta.env.VITE_API_BASE_URL as string | undefined) ?? ''

export class ApiError extends Error {
  code: string
  status: number
  extra?: Record<string, unknown>

  constructor(code: string, message: string, status: number, extra?: Record<string, unknown>) {
    super(message)
    this.code = code
    this.status = status
    this.extra = extra
  }
}

type Options = RequestInit & { json?: unknown }

export async function api<T>(path: string, options: Options = {}): Promise<T> {
  const headers = new Headers(options.headers)
  if (options.json !== undefined) {
    headers.set('Content-Type', 'application/json')
  }
  const token = localStorage.getItem('fq_user_token')
  if (token) {
    headers.set('X-FQ-Token', token)
  }

  const res = await fetch(`${API_BASE}${path}`, {
    ...options,
    headers,
    credentials: 'include',
    body: options.json !== undefined ? JSON.stringify(options.json) : options.body,
  })

  if (res.status === 204) {
    return undefined as T
  }

  const ct = res.headers.get('content-type') || ''
  if (!ct.includes('application/json')) {
    if (!res.ok) {
      throw new ApiError('NETWORK', res.statusText || 'Request failed', res.status)
    }
    return (await res.blob()) as T
  }

  const data = await res.json()
  if (!res.ok) {
    throw new ApiError(
      data?.error?.code ?? 'ERROR',
      data?.error?.message ?? 'Request failed',
      res.status,
      data?.error,
    )
  }
  return data as T
}

export { API_BASE }
