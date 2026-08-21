import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api } from '../../api/client'
import { RichContent } from '../../components/RichContent'

export function AdminResultsPage() {
  const qc = useQueryClient()
  const [userId, setUserId] = useState<string | null>(null)
  const { data, isLoading } = useQuery({
    queryKey: ['admin-results'],
    queryFn: () =>
      api<{
        leaderboard: { user_id: string; name: string; score: number; rank: number; max_score: number }[]
        quizStats: {
          quiz: { id: string; title: string }
          options: { id: string; label_html: string; is_correct: number }[]
          stats: { option_id: string; pick_count: number }[]
        }[]
        resultsComputedAt: string
        resultsStale: boolean
      }>('/api/admin/results'),
  })

  const drill = useQuery({
    queryKey: ['admin-user-results', userId],
    enabled: !!userId,
    queryFn: () => api(`/api/admin/results/users/${userId}`),
  })

  const recompute = useMutation({
    mutationFn: () => api('/api/admin/results/recompute', { method: 'POST' }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['admin-results'] }),
  })

  if (isLoading || !data) return <p>Loading…</p>

  return (
    <div className="space-y-8">
      <div className="flex flex-wrap justify-between gap-3 items-center">
        <div>
          <h1 className="font-display text-3xl font-bold">Results</h1>
          <p className="text-sm text-[var(--color-muted)]">
            Computed {data.resultsComputedAt || '—'}
            {data.resultsStale ? ' · stale' : ''}
          </p>
        </div>
        <div className="flex gap-2">
          <button
            type="button"
            className="min-h-11 px-4 rounded-xl border"
            onClick={() => recompute.mutate()}
          >
            Recompute
          </button>
          <a className="min-h-11 px-4 rounded-xl bg-[var(--color-ink)] text-white inline-flex items-center" href="/api/admin/export">
            Export ZIP
          </a>
        </div>
      </div>

      <section>
        <h2 className="font-semibold text-xl mb-3">Leaderboard</h2>
        <ol className="space-y-2">
          {data.leaderboard.map((r) => (
            <li key={r.user_id}>
              <button
                type="button"
                className="w-full flex justify-between rounded-xl border px-4 py-3 text-left hover:bg-white/50"
                onClick={() => setUserId(r.user_id)}
              >
                <span>
                  #{r.rank} {r.name}
                </span>
                <span>
                  {r.score}/{r.max_score}
                </span>
              </button>
            </li>
          ))}
        </ol>
      </section>

      {userId && drill.data ? (
        <section className="rounded-2xl border p-4">
          <h2 className="font-semibold text-xl mb-2">Participant drill-down</h2>
          <pre className="text-xs overflow-auto max-h-96">{JSON.stringify(drill.data, null, 2)}</pre>
        </section>
      ) : null}

      <section className="space-y-4">
        <h2 className="font-semibold text-xl">Per-quiz stats</h2>
        {data.quizStats.map((qs) => {
          const maxPick = Math.max(1, ...qs.stats.map((s) => s.pick_count))
          return (
            <div key={qs.quiz.id} className="rounded-xl border p-4 space-y-2">
              <h3 className="font-medium">{qs.quiz.title}</h3>
              {qs.options.map((o) => {
                const pick = qs.stats.find((s) => s.option_id === o.id)?.pick_count ?? 0
                return (
                  <div key={o.id} className="space-y-1">
                    <div className="flex justify-between text-sm gap-2">
                      <div className="flex-1">
                        <RichContent html={o.label_html || '—'} />
                        {!!o.is_correct && <span className="text-teal-700 text-xs ml-1">correct</span>}
                      </div>
                      <span>{pick}</span>
                    </div>
                    <div className="h-2 bg-[var(--color-line)] rounded-full overflow-hidden">
                      <div
                        className={`h-full ${o.is_correct ? 'bg-teal-600' : 'bg-stone-400'}`}
                        style={{ width: `${(pick / maxPick) * 100}%` }}
                      />
                    </div>
                  </div>
                )
              })}
            </div>
          )
        })}
      </section>
    </div>
  )
}
