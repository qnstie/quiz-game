import { type FormEvent, useRef, useState } from 'react'
import { Link, NavLink, Navigate, Outlet, useNavigate } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { LogOut } from 'lucide-react'
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
  const busyRef = useRef(false)

  const login = useMutation({
    mutationFn: (creds: { email: string; password: string }) =>
      api('/api/admin/login', { method: 'POST', json: creds }),
    onSuccess: async () => {
      await qc.invalidateQueries({ queryKey: ['admin-me'] })
      navigate('/admin/projects')
    },
    onError: (e: unknown) => {
      setError(e instanceof ApiError ? e.message : 'Login failed')
    },
    onSettled: () => {
      busyRef.current = false
    },
  })

  const onSubmit = (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault()
    e.stopPropagation()
    if (busyRef.current || login.isPending) return

    const fd = new FormData(e.currentTarget)
    const nextEmail = String(fd.get('email') ?? email).trim()
    const nextPassword = String(fd.get('password') ?? password)
    if (!nextEmail || !nextPassword) {
      setError('Email and password are required')
      return
    }

    busyRef.current = true
    setEmail(nextEmail)
    setPassword(nextPassword)
    setError(null)
    login.mutate({ email: nextEmail, password: nextPassword })
  }

  return (
    <div className="min-h-dvh flex items-center justify-center p-6 bg-[var(--color-paper)]">
      {/* Enter and click both go through onSubmit once — no separate key handler. */}
      <form
        onSubmit={onSubmit}
        className="w-full max-w-md space-y-4 border border-[var(--color-line)] bg-white p-6"
        noValidate
      >
        <h1 className="font-display text-xl font-bold">Admin login</h1>
        <label className="block space-y-1">
          <span className="text-sm font-semibold">Email</span>
          <input
            className="input-field"
            type="email"
            name="email"
            autoComplete="username"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            required
          />
        </label>
        <label className="block space-y-1">
          <span className="text-sm font-semibold">Password</span>
          <input
            className="input-field"
            type="password"
            name="password"
            autoComplete="current-password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            required
          />
        </label>
        {error && <p className="text-sm text-red-700">{error}</p>}
        <button type="submit" className="btn-primary w-full" disabled={login.isPending}>
          {login.isPending ? 'Signing in…' : 'Sign in'}
        </button>
        <p className="text-center text-sm text-[var(--color-muted)] pt-1">
          <Link to="/" className="text-[var(--color-accent)] hover:underline">
            Back to quiz (participant access)
          </Link>
        </p>
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
    <div className="min-h-dvh bg-[var(--color-paper)]">
      <header className="border-b-2 border-[var(--color-line)] bg-[var(--color-paper)] sticky top-0 z-20">
        <div className="max-w-6xl mx-auto px-4 py-3 flex flex-wrap gap-3 items-center justify-between">
          <div className="font-display font-bold text-lg tracking-tight">Family Quiz Admin</div>
          <nav className="flex flex-wrap gap-1 text-sm">
            {links.map(([label, to]) => (
              <NavLink
                key={to}
                to={to}
                className={({ isActive }) =>
                  `min-h-10 px-2 inline-flex items-center ${
                    isActive
                      ? 'text-[var(--color-accent-strong)] font-semibold'
                      : 'text-[var(--color-ink)] hover:text-[var(--color-accent-strong)]'
                  }`
                }
              >
                {label}
              </NavLink>
            ))}
            <button
              type="button"
              onClick={() => void logout()}
              className="min-h-10 px-2 inline-flex items-center gap-1.5 text-[var(--color-muted)] hover:text-[var(--color-ink)]"
            >
              <LogOut size={14} aria-hidden />
              Log out
            </button>
          </nav>
        </div>
        {me.data?.seedPasswordWarning && (
          <div className="bg-[var(--color-accent-soft)] text-[var(--color-accent-strong)] text-sm px-4 py-2 text-center">
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
