import { type FormEvent, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Plus, RotateCcw, Trash2 } from 'lucide-react'
import { api, ApiError } from '../../api/client'

type Superuser = {
  id: string
  email: string
  display_name: string | null
  is_active: number
  password_algo: string
}

type Participant = {
  id: string
  name: string
  answered: number
  total: number
  last_seen_at: string | null
  created_at: string
}

export function AdminUsersPage() {
  const qc = useQueryClient()
  const { data, isLoading } = useQuery({
    queryKey: ['admin-superusers'],
    queryFn: () => api<{ superusers: Superuser[] }>('/api/admin/superusers'),
  })
  const participantsQ = useQuery({
    queryKey: ['admin-participants'],
    queryFn: () => api<{ participants: Participant[] }>('/api/admin/participants'),
    refetchInterval: 5_000,
  })
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [message, setMessage] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)

  const create = useMutation({
    mutationFn: () => api('/api/admin/superusers', { method: 'POST', json: { email, password } }),
    onSuccess: async () => {
      setEmail('')
      setPassword('')
      setError(null)
      setMessage('Superuser created.')
      await qc.invalidateQueries({ queryKey: ['admin-superusers'] })
    },
    onError: (e: unknown) => {
      setMessage(null)
      setError(e instanceof ApiError ? e.message : 'Create failed')
    },
  })

  const patch = useMutation({
    mutationFn: ({ id, body }: { id: string; body: Record<string, unknown> }) =>
      api(`/api/admin/superusers/${id}`, { method: 'PATCH', json: body }),
    onSuccess: async (_data, vars) => {
      setError(null)
      setMessage(vars.body.password ? 'Password updated.' : 'User updated.')
      await qc.invalidateQueries({ queryKey: ['admin-superusers'] })
      await qc.invalidateQueries({ queryKey: ['admin-me'] })
      const me = await api<{ seedPasswordWarning: boolean }>('/api/admin/me')
      qc.setQueryData(['admin-me'], (old: unknown) =>
        old && typeof old === 'object' ? { ...old, seedPasswordWarning: me.seedPasswordWarning } : old,
      )
    },
    onError: (e: unknown) => {
      setMessage(null)
      setError(e instanceof ApiError ? e.message : 'Update failed')
    },
  })

  const deleteParticipant = useMutation({
    mutationFn: (userId: string) => api(`/api/admin/participants/${userId}`, { method: 'DELETE' }),
    onSuccess: async () => {
      setMessage('Participant removed. They will see the join screen on next access.')
      setError(null)
      await qc.invalidateQueries({ queryKey: ['admin-participants'] })
    },
    onError: (e: unknown) => {
      setMessage(null)
      setError(e instanceof ApiError ? e.message : 'Delete failed')
    },
  })

  const resetAnswers = useMutation({
    mutationFn: (userId: string) =>
      api(`/api/admin/participants/${userId}/reset-answers`, { method: 'POST' }),
    onSuccess: async () => {
      setMessage('Answers reset. Participant will return to the quiz list on next access.')
      setError(null)
      await qc.invalidateQueries({ queryKey: ['admin-participants'] })
    },
    onError: (e: unknown) => {
      setMessage(null)
      setError(e instanceof ApiError ? e.message : 'Reset failed')
    },
  })

  if (isLoading || !data) return <p>Loading…</p>

  const onCreate = (e: FormEvent) => {
    e.preventDefault()
    create.mutate()
  }

  const participants = participantsQ.data?.participants ?? []

  return (
    <div className="space-y-10">
      <div className="space-y-8">
        <h1 className="font-display text-2xl font-bold">Users</h1>
        {message && <p className="text-sm text-emerald-800">{message}</p>}
        {error && <p className="text-sm text-red-700">{error}</p>}

        <section className="space-y-4">
          <div>
            <h2 className="font-display text-xl font-bold">Superusers</h2>
            <p className="text-sm text-[var(--color-muted)] mt-1">
              Admins who sign in with email and password.
            </p>
          </div>
          <form onSubmit={onCreate} className="flex flex-wrap gap-2 items-end">
            <input
              className="input-field w-auto min-w-[14rem]"
              type="email"
              placeholder="email"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              required
            />
            <input
              className="input-field w-auto min-w-[12rem]"
              type="password"
              placeholder="password (8+)"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              required
              minLength={8}
            />
            <button type="submit" className="btn-primary">
              <Plus size={16} aria-hidden />
              Add
            </button>
          </form>

          <table className="admin-table">
            <thead>
              <tr>
                <th>Email</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              {data.superusers.map((u) => (
                <tr key={u.id}>
                  <td>
                    <div className="font-medium">{u.email}</div>
                    <div className="text-xs text-[var(--color-muted)]">{u.password_algo}</div>
                  </td>
                  <td>
                    {u.is_active ? (
                      <span className="pill pill-accent">Active</span>
                    ) : (
                      <span className="pill pill-neutral">Disabled</span>
                    )}
                  </td>
                  <td>
                    <div className="flex flex-wrap gap-2">
                      <button
                        type="button"
                        className="btn-secondary min-h-9 text-sm"
                        disabled={patch.isPending}
                        onClick={() => {
                          const pw = window.prompt('New password (min 8 characters)')
                          if (pw === null) return
                          if (pw.length < 8) {
                            setError('Password must be at least 8 characters.')
                            setMessage(null)
                            return
                          }
                          patch.mutate({ id: u.id, body: { password: pw } })
                        }}
                      >
                        Change password
                      </button>
                      <button
                        type="button"
                        className="btn-secondary min-h-9 text-sm"
                        disabled={patch.isPending}
                        onClick={() => patch.mutate({ id: u.id, body: { is_active: !u.is_active } })}
                      >
                        {u.is_active ? 'Disable' : 'Enable'}
                      </button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </section>
      </div>

      <section className="space-y-4">
        <div>
          <h2 className="font-display text-xl font-bold">Participants</h2>
          <p className="text-sm text-[var(--color-muted)] mt-1">
            People who joined the active project by name (no password). Reset clears answers; remove sends them
            back to the join screen.
          </p>
        </div>

        {participantsQ.isLoading ? (
          <p className="text-sm text-[var(--color-muted)]">Loading participants…</p>
        ) : participants.length === 0 ? (
          <p className="text-sm text-[var(--color-muted)]">No participants yet for the active project.</p>
        ) : (
          <table className="admin-table">
            <thead>
              <tr>
                <th>Name</th>
                <th>Progress</th>
                <th>Last seen</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody>
              {participants.map((p) => (
                <tr key={p.id}>
                  <td className="font-medium">{p.name}</td>
                  <td>
                    {p.answered} / {p.total}
                  </td>
                  <td className="text-sm text-[var(--color-muted)]">
                    {p.last_seen_at ? new Date(p.last_seen_at).toLocaleString() : '—'}
                  </td>
                  <td>
                    <div className="flex flex-wrap gap-2">
                      <button
                        type="button"
                        className="btn-secondary min-h-9 text-sm inline-flex items-center gap-1.5"
                        disabled={resetAnswers.isPending || p.answered === 0}
                        onClick={() => {
                          if (
                            !window.confirm(
                              `Reset all answers for ${p.name}? They will keep their name and return to the quiz list.`,
                            )
                          ) {
                            return
                          }
                          resetAnswers.mutate(p.id)
                        }}
                      >
                        <RotateCcw size={14} aria-hidden />
                        Reset answers
                      </button>
                      <button
                        type="button"
                        className="btn-secondary min-h-9 text-sm text-red-700 border-red-300 inline-flex items-center gap-1.5"
                        disabled={deleteParticipant.isPending}
                        onClick={() => {
                          if (
                            !window.confirm(
                              `Remove ${p.name}? They will need to join again from the landing page.`,
                            )
                          ) {
                            return
                          }
                          deleteParticipant.mutate(p.id)
                        }}
                      >
                        <Trash2 size={14} aria-hidden />
                        Remove
                      </button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </section>
    </div>
  )
}
