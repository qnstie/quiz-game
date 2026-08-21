import { useQuery } from '@tanstack/react-query'
import { api } from '../../api/client'

export function AdminLivePage() {
  const { data, isLoading } = useQuery({
    queryKey: ['admin-participants'],
    queryFn: () =>
      api<{
        participants: { id: string; name: string; answered: number; total: number; last_seen_at: string | null }[]
      }>('/api/admin/participants'),
    refetchInterval: 5000,
  })

  if (isLoading || !data) return <p>Loading…</p>

  return (
    <div className="space-y-6">
      <h1 className="font-display text-3xl font-bold">Live progress</h1>
      <p className="text-sm text-[var(--color-muted)]">Auto-refreshes every 5 seconds.</p>
      <ul className="space-y-2">
        {data.participants.map((p) => {
          const pct = p.total ? Math.round((p.answered / p.total) * 100) : 0
          return (
            <li key={p.id} className="rounded-xl border px-4 py-3">
              <div className="flex justify-between gap-2 mb-2">
                <span className="font-semibold">{p.name}</span>
                <span className="text-sm text-[var(--color-muted)]">
                  {p.answered}/{p.total} · {p.last_seen_at ?? '—'}
                </span>
              </div>
              <div className="h-2 rounded-full bg-[var(--color-line)] overflow-hidden">
                <div className="h-full bg-[var(--color-accent)]" style={{ width: `${pct}%` }} />
              </div>
            </li>
          )
        })}
        {data.participants.length === 0 && <p className="text-[var(--color-muted)]">No participants yet.</p>}
      </ul>
    </div>
  )
}
