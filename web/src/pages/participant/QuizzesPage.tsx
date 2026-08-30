import { useEffect } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { api, ApiError } from '../../api/client'
import { ProgressBar } from '../../components/ProgressBar'
import { writeOutbox } from '../../lib/outbox'

type QuizList = {
  quizzes: { id: string; title: string; answered: boolean }[]
  answeredCount: number
  total: number
}

export function QuizzesPage() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const qc = useQueryClient()
  const { data, isLoading, isError } = useQuery({
    queryKey: ['quizzes'],
    queryFn: () => api<QuizList>('/api/quizzes'),
    refetchInterval: 5_000,
    staleTime: 0,
  })

  const reset = useMutation({
    mutationFn: () => api('/api/session/reset-answers', { method: 'POST' }),
    onSuccess: async () => {
      writeOutbox([])
      await qc.invalidateQueries({ queryKey: ['quizzes'] })
      await qc.invalidateQueries({ queryKey: ['quiz'] })
      await qc.invalidateQueries({ queryKey: ['bootstrap'] })
    },
  })

  useEffect(() => {
    if (isError) navigate('/', { replace: true })
  }, [isError, navigate])

  if (isError || isLoading || !data) {
    return <p className="py-12 text-center text-[var(--color-muted)]">{t('common.loading')}</p>
  }

  const firstUnanswered = data.quizzes.find((q) => !q.answered)?.id
  const allDone = data.answeredCount === data.total && data.total > 0
  const hasAnswers = data.answeredCount > 0

  return (
    <section className="py-6 space-y-6">
      <div className="flex items-end justify-between gap-4">
        <h1 className="font-display text-3xl font-bold">{t('quizzes.title')}</h1>
        {!allDone && firstUnanswered && (
          <Link
            to={`/q/${firstUnanswered}`}
            className="min-h-12 px-4 rounded-xl bg-[var(--color-accent)] text-white font-semibold inline-flex items-center"
          >
            {hasAnswers ? t('quizzes.resume') : t('quizzes.start')}
          </Link>
        )}
      </div>
      <ProgressBar answered={data.answeredCount} total={data.total} />
      <p className="text-sm text-[var(--color-muted)]">
        {t('quizzes.progress', { count: data.answeredCount, answered: data.answeredCount, total: data.total })}
      </p>

      {allDone && (
        <div className="rounded-2xl border border-[var(--color-accent)] bg-[var(--color-accent-soft)]/40 p-4">
          <h2 className="font-display text-xl font-bold">{t('quizzes.summaryTitle')}</h2>
          <p>{t('quizzes.summaryBody', { answered: data.answeredCount, total: data.total })}</p>
        </div>
      )}

      <ul className="space-y-2">
        {data.quizzes.map((q, i) => (
          <li key={q.id}>
            <Link
              to={`/q/${q.id}`}
              className="flex items-center gap-3 min-h-14 rounded-xl border border-[var(--color-line)] bg-white px-4 hover:border-[var(--color-muted)]"
            >
              <span className="text-[var(--color-muted)] w-6">{i + 1}</span>
              <span className="flex-1 font-medium">{q.title}</span>
              {q.answered && <span className="text-[var(--color-accent)] text-lg" aria-label="answered">✓</span>}
            </Link>
          </li>
        ))}
      </ul>

      {hasAnswers && (
        <div className="pt-2">
          <button
            type="button"
            className="w-full min-h-12 text-[var(--color-muted)] underline underline-offset-2 hover:text-[var(--color-ink)] disabled:opacity-50"
            disabled={reset.isPending}
            onClick={() => {
              if (!window.confirm(t('quizzes.resetConfirm'))) return
              reset.mutate()
            }}
          >
            {reset.isPending ? t('common.loading') : t('quizzes.resetAnswers')}
          </button>
          {reset.isError && (
            <p className="text-sm text-red-700 mt-2 text-center">
              {reset.error instanceof ApiError ? reset.error.message : t('common.error')}
            </p>
          )}
        </div>
      )}
    </section>
  )
}
