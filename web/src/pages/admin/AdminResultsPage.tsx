import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Download, RotateCw } from 'lucide-react'
import { api, API_BASE } from '../../api/client'
import { RichContent } from '../../components/RichContent'

type DrillDetail = {
  quiz: { id: string; title: string }
  options: { id: string; label_html: string; is_correct: number }[]
  answer: { option_id: string | null; is_correct: number } | null
}

type DrillResponse = {
  user: { id: string; name: string }
  summary: { score: number; max_score: number; rank: number } | null
  detail: DrillDetail[]
}

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
    queryFn: () => api<DrillResponse>(`/api/admin/results/users/${userId}`),
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
          <h1 className="font-display text-2xl font-bold">Results</h1>
          <p className="text-sm text-[var(--color-muted)]">
            Computed {data.resultsComputedAt || '—'}
            {data.resultsStale ? ' · stale' : ''}
          </p>
        </div>
        <div className="flex gap-2">
          <button type="button" className="btn-secondary" onClick={() => recompute.mutate()}>
            <RotateCw size={16} aria-hidden />
            Recompute
          </button>
          <a className="btn-primary" href={`${API_BASE}/api/admin/export`}>
            <Download size={16} aria-hidden />
            Export ZIP
          </a>
        </div>
      </div>

      <section>
        <h2 className="font-semibold text-lg mb-3">Leaderboard</h2>
        {data.leaderboard.length === 0 ? (
          <p className="text-[var(--color-muted)] text-sm">No results yet.</p>
        ) : (
          <table className="admin-table">
            <thead>
              <tr>
                <th className="w-16">Rank</th>
                <th>Name</th>
                <th className="w-28">Score</th>
              </tr>
            </thead>
            <tbody>
              {data.leaderboard.map((r) => (
                <tr
                  key={r.user_id}
                  className="cursor-pointer"
                  onClick={() => setUserId(r.user_id)}
                >
                  <td>#{r.rank}</td>
                  <td className="font-medium">{r.name}</td>
                  <td>
                    {r.score}/{r.max_score}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </section>

      {userId && drill.data ? (
        <section className="border border-[var(--color-line)] p-4 space-y-4">
          <div className="flex justify-between items-baseline gap-2">
            <h2 className="font-semibold text-lg">{drill.data.user.name}</h2>
            {drill.data.summary && (
              <p className="text-sm text-[var(--color-muted)]">
                Rank #{drill.data.summary.rank} · {drill.data.summary.score}/{drill.data.summary.max_score}
              </p>
            )}
          </div>
          <table className="admin-table">
            <thead>
              <tr>
                <th>Quiz</th>
                <th>Their answer</th>
                <th className="w-28">Result</th>
              </tr>
            </thead>
            <tbody>
              {drill.data.detail.map((row) => {
                const chosen = row.options.find((o) => o.id === row.answer?.option_id)
                const correct = !!row.answer?.is_correct
                const unanswered = !row.answer?.option_id
                return (
                  <tr key={row.quiz.id}>
                    <td className="font-medium">{row.quiz.title}</td>
                    <td>
                      {chosen ? (
                        <RichContent html={chosen.label_html || '—'} />
                      ) : (
                        <span className="text-[var(--color-muted)]">—</span>
                      )}
                    </td>
                    <td>
                      {unanswered ? (
                        <span className="pill pill-neutral">Unanswered</span>
                      ) : correct ? (
                        <span className="pill pill-accent">Correct</span>
                      ) : (
                        <span className="pill pill-neutral">Incorrect</span>
                      )}
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
          <button type="button" className="text-sm text-[var(--color-muted)] underline" onClick={() => setUserId(null)}>
            Close drill-down
          </button>
        </section>
      ) : null}

      <section className="space-y-4">
        <h2 className="font-semibold text-lg">Per-quiz stats</h2>
        {data.quizStats.map((qs) => {
          const maxPick = Math.max(1, ...qs.stats.map((s) => s.pick_count))
          return (
            <div key={qs.quiz.id} className="border border-[var(--color-line)] p-4 space-y-3">
              <h3 className="font-medium">{qs.quiz.title}</h3>
              {qs.options.map((o) => {
                const pick = qs.stats.find((s) => s.option_id === o.id)?.pick_count ?? 0
                return (
                  <div key={o.id} className="space-y-1">
                    <div className="flex justify-between text-sm gap-2">
                      <div className="flex-1 flex items-start gap-2">
                        <RichContent html={o.label_html || '—'} />
                        {!!o.is_correct && <span className="pill pill-accent shrink-0">correct</span>}
                      </div>
                      <span className="text-[var(--color-muted)]">{pick}</span>
                    </div>
                    <div className="h-2 bg-[var(--color-line)] overflow-hidden">
                      <div
                        className={`h-full ${o.is_correct ? 'bg-[var(--color-accent)]' : 'bg-[var(--color-line)]'}`}
                        style={{
                          width: `${(pick / maxPick) * 100}%`,
                          background: o.is_correct
                            ? 'var(--color-accent)'
                            : 'color-mix(in srgb, var(--color-ink) 35%, transparent)',
                        }}
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
