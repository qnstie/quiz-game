import { useEffect, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { useQueryClient } from '@tanstack/react-query'
import { api, ApiError } from '../../api/client'

/**
 * Hidden automation entry: /admin/enter?t=<admin_magic_token>
 * Not linked from the UI. Exchanges the secret for an admin session cookie.
 */
export function AdminMagicEnterPage() {
  const [params] = useSearchParams()
  const navigate = useNavigate()
  const qc = useQueryClient()
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    const token = params.get('t') || params.get('token') || ''
    if (!token) {
      setError('Missing token')
      return
    }

    let cancelled = false
    void (async () => {
      try {
        await api('/api/admin/magic-login?format=json&t=' + encodeURIComponent(token))
        await qc.invalidateQueries({ queryKey: ['admin-me'] })
        if (!cancelled) navigate('/admin/projects', { replace: true })
      } catch (e: unknown) {
        if (!cancelled) {
          setError(e instanceof ApiError ? 'Not found' : 'Login failed')
        }
      }
    })()

    return () => {
      cancelled = true
    }
  }, [params, navigate, qc])

  if (error) {
    return (
      <div className="min-h-dvh flex items-center justify-center p-8">
        <p className="text-sm text-[var(--color-muted)]">{error}</p>
      </div>
    )
  }

  return (
    <div className="min-h-dvh flex items-center justify-center p-8">
      <p className="text-sm text-[var(--color-muted)]">Signing in…</p>
    </div>
  )
}
