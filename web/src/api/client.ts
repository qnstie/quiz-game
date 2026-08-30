const API_BASE =
  (import.meta.env.VITE_API_BASE_URL as string | undefined) ??
  String(import.meta.env.BASE_URL || '/').replace(/\/$/, '')

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

/** Fired when a participant session is no longer valid (kicked / expired). */
export const PARTICIPANT_UNAUTHORIZED_EVENT = 'fq:participant-unauthorized'

export function clearParticipantSession() {
  localStorage.removeItem('fq_user_token')
  try {
    localStorage.setItem('fq_answer_outbox', '[]')
  } catch {
    /* ignore */
  }
  if (typeof window !== 'undefined') {
    window.dispatchEvent(new Event(PARTICIPANT_UNAUTHORIZED_EVENT))
  }
}

export function isParticipantUnauthorized(e: unknown): boolean {
  return e instanceof ApiError && e.status === 401 && e.code === 'UNAUTHENTICATED'
}

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
    cache: 'no-store',
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
    const err = new ApiError(
      data?.error?.code ?? 'ERROR',
      data?.error?.message ?? 'Request failed',
      res.status,
      data?.error,
    )
    // Participant was removed or session expired — drop local session so UI can return to join.
    if (
      err.status === 401 &&
      err.code === 'UNAUTHENTICATED' &&
      path.startsWith('/api/') &&
      !path.startsWith('/api/admin') &&
      !path.startsWith('/api/agent')
    ) {
      clearParticipantSession()
    }
    throw err
  }
  return data as T
}

export { API_BASE }
