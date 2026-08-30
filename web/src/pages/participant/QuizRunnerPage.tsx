import { useEffect, useRef, useState } from 'react'
import { useNavigate, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useSwipeable } from 'react-swipeable'
import { motion } from 'framer-motion'
import { useTranslation } from 'react-i18next'
import { ChevronLeft, List } from 'lucide-react'
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

const AUTO_ADVANCE_KEY = 'fq_auto_advance'

function readAutoAdvance(): boolean {
  const raw = localStorage.getItem(AUTO_ADVANCE_KEY)
  if (raw === null) return true
  return raw === '1' || raw === 'true'
}

export function QuizRunnerPage() {
  const { quizId = '' } = useParams()
  const navigate = useNavigate()
  const { t } = useTranslation()
  const qc = useQueryClient()
  const [selected, setSelected] = useState<string | null>(null)
  const [tick, setTick] = useState(false)
  const [autoAdvance, setAutoAdvance] = useState(readAutoAdvance)
  const autoAdvanceRef = useRef(autoAdvance)
  autoAdvanceRef.current = autoAdvance

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
        throw e
      }
    },
    onSuccess: (res) => {
      setTick(true)
      void qc.invalidateQueries({ queryKey: ['quizzes'] })
      void qc.invalidateQueries({ queryKey: ['quiz', quizId] })
      if (!autoAdvanceRef.current) return
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

  useEffect(() => {
    if (quizQ.isError || listQ.isError) {
      navigate('/', { replace: true })
    }
  }, [quizQ.isError, listQ.isError, navigate])

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

  if (quizQ.isError) {
    return <p className="py-12 text-center text-[var(--color-muted)]">{t('common.loading')}</p>
  }

  if (quizQ.isLoading || !quizQ.data) {
    return <p className="py-12 text-center text-[var(--color-muted)]">{t('common.loading')}</p>
  }

  const quiz = quizQ.data
  const savedId = quiz.selectedOptionId
  const dirty = selected !== null && selected !== savedId
  const canSubmit = !autoAdvance && selected !== null && (dirty || !savedId) && !submit.isPending

  const onPick = (optionId: string) => {
    setSelected(optionId)
    setTick(false)
    if (autoAdvance) {
      submit.mutate(optionId)
    }
  }

  const onToggleAutoAdvance = (checked: boolean) => {
    setAutoAdvance(checked)
    localStorage.setItem(AUTO_ADVANCE_KEY, checked ? '1' : '0')
  }

  return (
    <section className="py-4 space-y-5" {...swipe}>
      <div className="flex items-center gap-3">
        <button
          type="button"
          className="shrink-0 min-h-11 px-3 inline-flex items-center gap-2 border border-[var(--color-line)] bg-white/70 text-sm font-semibold hover:bg-[var(--color-accent-soft)]"
          onClick={() => navigate('/quizzes')}
        >
          <List size={16} aria-hidden />
          {t('quiz.backToList')}
        </button>
        <div className="flex-1 min-w-0">
          <ProgressBar
            answered={listQ.data?.answeredCount ?? 0}
            total={listQ.data?.total ?? 0}
            onClick={() => navigate('/quizzes')}
            hint={t('quiz.progressTap')}
          />
        </div>
      </div>

      {prev && (
        <button
          type="button"
          className="min-h-11 px-3 inline-flex items-center gap-1.5 text-sm font-semibold text-[var(--color-accent)] hover:underline"
          onClick={() => navigate(`/q/${prev}`)}
        >
          <ChevronLeft size={18} aria-hidden />
          {t('quiz.previous')}
        </button>
      )}

      <motion.div key={quiz.id} initial={{ opacity: 0, x: 24 }} animate={{ opacity: 1, x: 0 }} transition={{ duration: 0.25 }}>
        <h1 className="font-display text-2xl sm:text-3xl font-bold leading-tight">{quiz.title}</h1>
        {quiz.description_html && (
          <div className="mt-4 rounded-2xl bg-white p-4 border border-[var(--color-line)]">
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
                  onClick={() => onPick(o.id)}
                  className={`w-full min-h-14 text-left rounded-2xl px-4 py-3 transition-all text-[var(--color-ink)] bg-white ${
                    isSel
                      ? 'border-[3px] border-[#c23b2a] shadow-[0_0_0_1px_#c23b2a] scale-[1.01]'
                      : 'border border-[var(--color-line)] hover:border-[var(--color-muted)]'
                  }`}
                >
                  <div className="flex items-start gap-3">
                    <RichContent html={o.label_html || '—'} className="flex-1" />
                    {isSel && tick && <span className="text-[#c23b2a] text-xl font-bold">✓</span>}
                  </div>
                </button>
              </li>
            )
          })}
        </ul>
      </motion.div>

      <div className="space-y-3 pt-2 border-t border-[var(--color-line)]">
        {!autoAdvance && (
          <button
            type="button"
            disabled={!canSubmit}
            onClick={() => selected && submit.mutate(selected)}
            className="w-full min-h-14 rounded-2xl bg-[var(--color-accent)] text-white font-semibold text-lg disabled:opacity-40 disabled:cursor-not-allowed"
          >
            {submit.isPending ? t('quiz.submitting') : tick && !dirty ? t('quiz.saved') : t('quiz.submit')}
          </button>
        )}
        <label className="flex items-center gap-3 min-h-11 cursor-pointer select-none text-sm">
          <input
            type="checkbox"
            className="size-5 accent-[var(--color-accent)]"
            checked={autoAdvance}
            onChange={(e) => onToggleAutoAdvance(e.target.checked)}
          />
          <span>{t('quiz.autoAdvance')}</span>
        </label>
      </div>
    </section>
  )
}
