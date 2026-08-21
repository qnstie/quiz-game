import { useQuery } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { api } from '../../api/client'
import { RichContent } from '../../components/RichContent'

export function ResultsPage() {
  const { t } = useTranslation()
  const mine = useQuery({
    queryKey: ['me-results'],
    queryFn: () =>
      api<{
        summary: { score: number; max_score: number; rank: number }
        quizzes: {
          quizId: string
          title: string
          isCorrect: boolean
          chosenOptionId: string | null
          chosenLabelHtml: string | null
          correctLabelHtml: string | null
          explanation_html: string
          feedback_html: string
        }[]
      }>('/api/me/results'),
  })
  const board = useQuery({
    queryKey: ['leaderboard'],
    queryFn: () => api<{ leaderboard: { name: string; score: number; rank: number }[] }>('/api/leaderboard'),
  })

  if (mine.isLoading) {
    return <p className="py-12 text-center text-[var(--color-muted)]">{t('common.loading')}</p>
  }

  if (mine.isError || !mine.data) {
    return <p className="py-12 text-center">{t('common.error')}</p>
  }

  const { summary, quizzes } = mine.data

  return (
    <section className="py-6 space-y-8">
      <div>
        <h1 className="font-display text-3xl font-bold">{t('results.title')}</h1>
        <p className="text-lg mt-2">
          {t('results.score', { score: summary.score, max: summary.max_score })} ·{' '}
          {t('results.rank', { rank: summary.rank })}
        </p>
      </div>

      <ul className="space-y-4">
        {quizzes.map((q) => (
          <li key={q.quizId} className="rounded-2xl border border-[var(--color-line)] p-4 space-y-2">
            <div className="flex justify-between gap-2">
              <h2 className="font-semibold">{q.title}</h2>
              <span className={q.isCorrect ? 'text-teal-700' : 'text-orange-700'}>
                {!q.chosenOptionId
                  ? t('results.unanswered')
                  : q.isCorrect
                    ? t('results.correct')
                    : t('results.incorrect')}
              </span>
            </div>
            {q.chosenLabelHtml && (
              <div>
                <p className="text-xs text-[var(--color-muted)]">Your answer</p>
                <RichContent html={q.chosenLabelHtml} />
              </div>
            )}
            {!q.isCorrect && q.correctLabelHtml && (
              <div>
                <p className="text-xs text-[var(--color-muted)]">Correct</p>
                <RichContent html={q.correctLabelHtml} />
              </div>
            )}
            {q.feedback_html && (
              <div>
                <p className="text-xs font-semibold">{t('results.feedback')}</p>
                <RichContent html={q.feedback_html} />
              </div>
            )}
            {q.explanation_html && (
              <div>
                <p className="text-xs font-semibold">{t('results.explanation')}</p>
                <RichContent html={q.explanation_html} />
              </div>
            )}
          </li>
        ))}
      </ul>

      <div>
        <h2 className="font-display text-2xl font-bold mb-3">{t('results.leaderboard')}</h2>
        <ol className="space-y-2">
          {(board.data?.leaderboard ?? []).map((row) => (
            <li
              key={`${row.rank}-${row.name}`}
              className="flex justify-between rounded-xl border border-[var(--color-line)] px-4 py-3"
            >
              <span>
                <span className="text-[var(--color-muted)] mr-3">#{row.rank}</span>
                {row.name}
              </span>
              <span className="font-semibold">{row.score}</span>
            </li>
          ))}
        </ol>
      </div>
    </section>
  )
}
