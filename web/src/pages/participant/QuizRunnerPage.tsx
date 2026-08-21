import { useEffect, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useSwipeable } from 'react-swipeable'
import { motion } from 'framer-motion'
import { useTranslation } from 'react-i18next'
import { api } from '../../api/client'
import { RichContent } from '../../components/RichContent'
import { ProgressBar } from '../../components/ProgressBar'
import { dequeueAnswer, enqueueAnswer, readOutbox } from '../../lib/outbox'

type QuizDetail = {
  id: string
  title: string
  description_html: string
  options: { id: string; label_html: string }[]
  selectedOptionId: string | null
}

type AnswerRes = { next: string | null; answeredCount: number; total: number }

export function QuizRunnerPage() {
  const { quizId = '' } = useParams()
  const navigate = useNavigate()
  const { t } = useTranslation()
  const qc = useQueryClient()
  const [selected, setSelected] = useState<string | null>(null)
  const [tick, setTick] = useState(false)

  const listQ = useQuery({
    queryKey: ['quizzes'],
    queryFn: () => api<{ quizzes: { id: string }[]; answeredCount: number; total: number }>('/api/quizzes'),
  })

  const quizQ = useQuery({
    queryKey: ['quiz', quizId],
    queryFn: () => api<QuizDetail>(`/api/quizzes/${quizId}`),
    enabled: !!quizId,
  })

  useEffect(() => {
    setSelected(quizQ.data?.selectedOptionId ?? null)
    setTick(false)
  }, [quizQ.data?.selectedOptionId, quizId])

  const submit = useMutation({
    mutationFn: async (optionId: string) => {
      enqueueAnswer(quizId, optionId)
      try {
        const res = await api<AnswerRes>(`/api/answers/${quizId}`, {
          method: 'PUT',
          json: { optionId },
        })
        dequeueAnswer(quizId)
        return res
      } catch (e) {
        // keep in outbox for retry
        throw e
      }
    },
    onSuccess: (res) => {
      setTick(true)
      void qc.invalidateQueries({ queryKey: ['quizzes'] })
      void qc.invalidateQueries({ queryKey: ['quiz', quizId] })
      window.setTimeout(() => {
        if (res.next) navigate(`/q/${res.next}`)
        else navigate('/quizzes')
      }, 600)
    },
  })

  // Flush outbox on mount
  useEffect(() => {
    const items = readOutbox()
    for (const item of items) {
      void api(`/api/answers/${item.quizId}`, { method: 'PUT', json: { optionId: item.optionId } })
        .then(() => dequeueAnswer(item.quizId))
        .catch(() => undefined)
    }
  }, [])

  const ids = listQ.data?.quizzes.map((q) => q.id) ?? []
  const idx = ids.indexOf(quizId)
  const prev = idx > 0 ? ids[idx - 1] : null
  const next = idx >= 0 && idx < ids.length - 1 ? ids[idx + 1] : null

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'ArrowLeft' && prev) navigate(`/q/${prev}`)
      if (e.key === 'ArrowRight' && next) navigate(`/q/${next}`)
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [navigate, prev, next])

  const swipe = useSwipeable({
    onSwipedLeft: () => next && navigate(`/q/${next}`),
    onSwipedRight: () => prev && navigate(`/q/${prev}`),
    trackMouse: true,
  })

  if (quizQ.isLoading || !quizQ.data) {
    return <p className="py-12 text-center text-[var(--color-muted)]">{t('common.loading')}</p>
  }

  const quiz = quizQ.data

  return (
    <section className="py-4 space-y-5" {...swipe}>
      <ProgressBar
        answered={listQ.data?.answeredCount ?? 0}
        total={listQ.data?.total ?? 0}
        onClick={() => navigate('/quizzes')}
      />
      <motion.div key={quiz.id} initial={{ opacity: 0, x: 24 }} animate={{ opacity: 1, x: 0 }} transition={{ duration: 0.25 }}>
        <h1 className="font-display text-2xl sm:text-3xl font-bold leading-tight">{quiz.title}</h1>
        {quiz.description_html && (
          <div className="mt-4 rounded-2xl bg-white/60 dark:bg-stone-900/60 p-4 border border-[var(--color-line)]">
            <RichContent html={quiz.description_html} />
          </div>
        )}
        <ul className="mt-6 space-y-3">
          {quiz.options.map((o) => {
            const isSel = selected === o.id
            return (
              <li key={o.id}>
                <button
                  type="button"
                  disabled={submit.isPending}
                  onClick={() => {
                    setSelected(o.id)
                    submit.mutate(o.id)
                  }}
                  className={`w-full min-h-14 text-left rounded-2xl border px-4 py-3 transition-all ${
                    isSel
                      ? 'border-[var(--color-accent)] bg-[var(--color-accent-soft)] scale-[1.01] shadow-sm'
                      : 'border-[var(--color-line)] bg-white/50 dark:bg-stone-900/50 hover:border-[var(--color-accent)]'
                  }`}
                >
                  <div className="flex items-start gap-3">
                    <RichContent html={o.label_html || '—'} className="flex-1" />
                    {isSel && tick && <span className="text-[var(--color-accent)] text-xl">✓</span>}
                  </div>
                </button>
              </li>
            )
          })}
        </ul>
      </motion.div>
    </section>
  )
}
