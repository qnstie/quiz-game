import { type FormEvent, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api } from '../../api/client'

export function AdminUsersPage() {
  const qc = useQueryClient()
  const { data, isLoading } = useQuery({
    queryKey: ['admin-superusers'],
    queryFn: () =>
      api<{
        superusers: {
          id: string
          email: string
          display_name: string | null
          is_active: number
          password_algo: string
        }[]
      }>('/api/admin/superusers'),
  })
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')

  const create = useMutation({
    mutationFn: () => api('/api/admin/superusers', { method: 'POST', json: { email, password } }),
    onSuccess: async () => {
      setEmail('')
      setPassword('')
      await qc.invalidateQueries({ queryKey: ['admin-superusers'] })
    },
  })

  const patch = useMutation({
    mutationFn: ({ id, body }: { id: string; body: Record<string, unknown> }) =>
      api(`/api/admin/superusers/${id}`, { method: 'PATCH', json: body }),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['admin-superusers'] })
      void qc.invalidateQueries({ queryKey: ['admin-me'] })
    },
  })

  if (isLoading || !data) return <p>Loading…</p>

  const onCreate = (e: FormEvent) => {
    e.preventDefault()
    create.mutate()
  }

  return (
    <div className="space-y-8">
      <h1 className="font-display text-3xl font-bold">Superusers</h1>
      <form onSubmit={onCreate} className="flex flex-wrap gap-2 items-end">
        <input
          className="min-h-11 rounded-lg border px-3"
          type="email"
          placeholder="email"
          value={email}
          onChange={(e) => setEmail(e.target.value)}
          required
        />
        <input
          className="min-h-11 rounded-lg border px-3"
          type="password"
          placeholder="password (8+)"
          value={password}
          onChange={(e) => setPassword(e.target.value)}
          required
          minLength={8}
        />
        <button type="submit" className="min-h-11 px-4 rounded-lg bg-[var(--color-accent)] text-white font-semibold">
          Add
        </button>
      </form>
      <ul className="space-y-2">
        {data.superusers.map((u) => (
          <li key={u.id} className="rounded-xl border px-4 py-3 flex flex-wrap gap-3 items-center justify-between">
            <div>
              <div className="font-medium">{u.email}</div>
              <div className="text-xs text-[var(--color-muted)]">
                {u.password_algo} · {u.is_active ? 'active' : 'disabled'}
              </div>
            </div>
            <div className="flex gap-2">
              <button
                type="button"
                className="min-h-10 px-3 rounded-lg border text-sm"
                onClick={() => {
                  const pw = window.prompt('New password (leave blank to cancel)')
                  if (pw && pw.length >= 8) patch.mutate({ id: u.id, body: { password: pw } })
                }}
              >
                Change password
              </button>
              <button
                type="button"
                className="min-h-10 px-3 rounded-lg border text-sm"
                onClick={() => patch.mutate({ id: u.id, body: { is_active: !u.is_active } })}
              >
                {u.is_active ? 'Disable' : 'Enable'}
              </button>
            </div>
          </li>
        ))}
      </ul>
    </div>
  )
}
