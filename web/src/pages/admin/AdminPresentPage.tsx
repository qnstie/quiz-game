import { useEffect, useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { motion } from 'framer-motion'
import { api } from '../../api/client'
import { RichContent } from '../../components/RichContent'

export function AdminPresentPage() {
  const { data } = useQuery({
    queryKey: ['admin-results'],
    queryFn: () =>
      api<{
        leaderboard: { name: string; score: number; rank: number }[]
        quizStats: {
          quiz: { id: string; title: string; description_html: string; explanation_html: string }
          options: { id: string; label_html: string; is_correct: number; feedback_html: string }[]
          stats: { option_id: string; pick_count: number }[]
        }[]
      }>('/api/admin/results'),
  })

  const slides = useMemo(() => {
    const quizSlides = (data?.quizStats ?? []).map((q) => ({ type: 'quiz' as const, ...q }))
    return [...quizSlides, { type: 'leaderboard' as const }]
  }, [data])

  const [idx, setIdx] = useState(0)
  const [revealed, setRevealed] = useState(false)
  const [whoOpen, setWhoOpen] = useState(false)

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'ArrowRight') {
        setIdx((i) => Math.min(i + 1, slides.length - 1))
        setRevealed(false)
        setWhoOpen(false)
      }
      if (e.key === 'ArrowLeft') {
        setIdx((i) => Math.max(i - 1, 0))
        setRevealed(false)
        setWhoOpen(false)
      }
      if (e.key === ' ') {
        e.preventDefault()
        setRevealed(true)
      }
      if (e.key === 'Escape') window.history.back()
      if (e.key === 'f' || e.key === 'F') {
        if (!document.fullscreenElement) void document.documentElement.requestFullscreen()
        else void document.exitFullscreen()
      }
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [slides.length])

  if (!data) return <p className="p-8 text-2xl">Loading presentation…</p>

  const slide = slides[idx]
  if (!slide) return null

  if (slide.type === 'leaderboard') {
    return (
      <div className="min-h-dvh bg-stone-950 text-stone-50 p-8 md:p-16">
        <p className="text-sm opacity-60 mb-4">
          ← → navigate · space reveal · f fullscreen · esc exit · slide {idx + 1}/{slides.length}
        </p>
        <h1 className="font-display text-5xl font-bold mb-10">Leaderboard</h1>
        <ol className="space-y-4 max-w-3xl">
          {data.leaderboard.map((r, i) => (
            <motion.li
              key={`${r.rank}-${r.name}`}
              initial={{ opacity: 0, y: 12 }}
              animate={{ opacity: 1, y: 0 }}
              transition={{ delay: i * 0.08 }}
              className="flex justify-between text-3xl border-b border-stone-700 pb-3"
            >
              <span>
                <span className="opacity-50 mr-4">#{r.rank}</span>
                {r.name}
              </span>
              <span className="text-teal-300">{r.score}</span>
            </motion.li>
          ))}
        </ol>
      </div>
    )
  }

  const maxPick = Math.max(1, ...slide.stats.map((s) => s.pick_count))
  const correct = slide.options.find((o) => o.is_correct)

  return (
    <div className="min-h-dvh bg-stone-950 text-stone-50 p-8 md:p-16">
      <p className="text-sm opacity-60 mb-4">
        ← → navigate · space reveal · f fullscreen · esc exit · slide {idx + 1}/{slides.length}
      </p>
      <h1 className="font-display text-4xl md:text-5xl font-bold leading-tight mb-6">{slide.quiz.title}</h1>
      {slide.quiz.description_html && (
        <div className="text-2xl mb-8 max-w-4xl opacity-90">
          <RichContent html={slide.quiz.description_html} />
        </div>
      )}
      <ul className="space-y-4 max-w-4xl text-2xl">
        {slide.options.map((o) => {
          const pick = slide.stats.find((s) => s.option_id === o.id)?.pick_count ?? 0
          const isCorrect = !!o.is_correct
          return (
            <li
              key={o.id}
              className={`rounded-2xl border p-4 ${
                revealed && isCorrect ? 'border-teal-400 bg-teal-950/50' : 'border-stone-700'
              }`}
            >
              <RichContent html={o.label_html || '—'} />
              {revealed && (
                <div className="mt-3">
                  <div className="h-3 rounded-full bg-stone-800 overflow-hidden">
                    <div
                      className={`h-full ${isCorrect ? 'bg-teal-400' : 'bg-stone-500'}`}
                      style={{ width: `${(pick / maxPick) * 100}%` }}
                    />
                  </div>
                  <p className="text-base mt-1 opacity-70">{pick} picks</p>
                </div>
              )}
            </li>
          )
        })}
      </ul>
      {revealed && (
        <div className="mt-8 max-w-4xl space-y-4 text-xl">
          {slide.quiz.explanation_html && <RichContent html={slide.quiz.explanation_html} />}
          {correct?.feedback_html && <RichContent html={correct.feedback_html} />}
          <button type="button" className="text-base underline opacity-70" onClick={() => setWhoOpen((v) => !v)}>
            {whoOpen ? 'Hide' : 'Show'} who answered what
          </button>
          {whoOpen && (
            <p className="text-base opacity-60">
              Open Results → participant drill-down for the full name → option list (kept out of the projector by
              default).
            </p>
          )}
        </div>
      )}
      <div className="fixed bottom-4 right-4 opacity-40">
        <img src="/api/admin/qrcode" alt="QR" className="w-28 h-28 bg-white rounded-lg p-1" />
      </div>
    </div>
  )
}
