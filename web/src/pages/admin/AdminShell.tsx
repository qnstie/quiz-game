import { type FormEvent, useState } from 'react'
import { Link, Navigate, Outlet, useNavigate } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, ApiError } from '../../api/client'

type Me = {
  admin: { id: string; email: string; display_name: string | null }
  seedPasswordWarning: boolean
  activeProjectId: string | null
  publicProjectId: string | null
}

export function useAdminMe() {
  return useQuery({
    queryKey: ['admin-me'],
    queryFn: () => api<Me>('/api/admin/me'),
    retry: false,
  })
}

export function AdminLoginPage() {
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [error, setError] = useState<string | null>(null)
  const navigate = useNavigate()
  const qc = useQueryClient()

  const login = useMutation({
    mutationFn: () => api('/api/admin/login', { method: 'POST', json: { email, password } }),
    onSuccess: async () => {
      await qc.invalidateQueries({ queryKey: ['admin-me'] })
      navigate('/admin/projects')
    },
    onError: (e: unknown) => {
      setError(e instanceof ApiError ? e.message : 'Login failed')
    },
  })

  const onSubmit = (e: FormEvent) => {
    e.preventDefault()
    login.mutate()
  }

  return (
    <div className="min-h-dvh flex items-center justify-center p-6">
      <form onSubmit={onSubmit} className="w-full max-w-md space-y-4 rounded-2xl border border-[var(--color-line)] bg-white/80 dark:bg-stone-900 p-6">
        <h1 className="font-display text-2xl font-bold">Admin login</h1>
        <label className="block space-y-1">
          <span className="text-sm font-semibold">Email</span>
          <input
            className="w-full min-h-12 rounded-xl border border-[var(--color-line)] px-3"
            type="email"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            required
          />
        </label>
        <label className="block space-y-1">
          <span className="text-sm font-semibold">Password</span>
          <input
            className="w-full min-h-12 rounded-xl border border-[var(--color-line)] px-3"
            type="password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            required
          />
        </label>
        {error && <p className="text-sm text-red-700">{error}</p>}
        <button type="submit" className="w-full min-h-12 rounded-xl bg-[var(--color-ink)] text-white font-semibold">
          Sign in
        </button>
      </form>
    </div>
  )
}

export function AdminShell() {
  const me = useAdminMe()
  const navigate = useNavigate()
  const qc = useQueryClient()

  if (me.isLoading) return <p className="p-8">Loading…</p>
  if (me.isError) return <Navigate to="/admin/login" replace />

  const logout = async () => {
    await api('/api/admin/logout', { method: 'POST' })
    await qc.invalidateQueries({ queryKey: ['admin-me'] })
    navigate('/admin/login')
  }

  const links = [
    ['Projects', '/admin/projects'],
    ['Content', '/admin/content'],
    ['Live', '/admin/live'],
    ['Results', '/admin/results'],
    ['Present', '/admin/present'],
    ['Users', '/admin/users'],
  ] as const

  return (
    <div className="min-h-dvh">
      <header className="border-b border-[var(--color-line)] bg-white/70 dark:bg-stone-950/70 backdrop-blur sticky top-0 z-20">
        <div className="max-w-6xl mx-auto px-4 py-3 flex flex-wrap gap-3 items-center justify-between">
          <div className="font-display font-bold text-lg">Family Quiz Admin</div>
          <nav className="flex flex-wrap gap-2 text-sm">
            {links.map(([label, to]) => (
              <Link key={to} to={to} className="min-h-10 px-2 inline-flex items-center hover:underline">
                {label}
              </Link>
            ))}
            <button type="button" onClick={() => void logout()} className="min-h-10 px-2 text-[var(--color-muted)]">
              Log out
            </button>
          </nav>
        </div>
        {me.data?.seedPasswordWarning && (
          <div className="bg-amber-100 text-amber-950 text-sm px-4 py-2 text-center">
            Seed admin password is still in use — change it under Users.
          </div>
        )}
      </header>
      <main className="max-w-6xl mx-auto px-4 py-6">
        <Outlet context={{ me: me.data }} />
      </main>
    </div>
  )
}
