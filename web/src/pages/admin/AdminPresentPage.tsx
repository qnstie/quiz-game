import { useEffect, useMemo, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { motion } from 'framer-motion'
import { api, API_BASE } from '../../api/client'
import { RichContent } from '../../components/RichContent'

type Tone = 'theatrical' | 'modernist'

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
  const [tone, setTone] = useState<Tone>(() => {
    const saved = localStorage.getItem('fq_present_tone')
    return saved === 'modernist' ? 'modernist' : 'theatrical'
  })

  useEffect(() => {
    localStorage.setItem('fq_present_tone', tone)
  }, [tone])

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
      if (e.key === 't' || e.key === 'T') {
        setTone((t) => (t === 'theatrical' ? 'modernist' : 'theatrical'))
      }
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [slides.length])

  if (!data) return <p className="p-8 text-2xl">Loading presentation…</p>

  const slide = slides[idx]
  if (!slide) return null

  const dark = tone === 'theatrical'
  const shell = dark
    ? 'min-h-dvh bg-stone-950 text-stone-50 p-8 md:p-16'
    : 'min-h-dvh bg-[var(--color-paper)] text-[var(--color-ink)] p-8 md:p-16'
  const muted = dark ? 'opacity-60' : 'text-[var(--color-muted)]'
  const correctHighlight = dark
    ? 'border-[#7fa3c9] bg-[#1c2733]'
    : 'border-[var(--color-accent)] bg-[var(--color-accent-soft)]'
  const optionBorder = dark ? 'border-stone-700' : 'border-[var(--color-line)]'
  const barTrack = dark ? 'bg-stone-800' : 'bg-[var(--color-line)]'
  const barCorrect = dark ? 'bg-[#7fa3c9]' : 'bg-[var(--color-accent)]'
  const barOther = dark ? 'bg-stone-500' : 'color-mix(in srgb, var(--color-ink) 35%, transparent)'
  const scoreColor = dark ? 'text-[#bcd2e8]' : 'text-[var(--color-accent-strong)]'
  const rowBorder = dark ? 'border-stone-700' : 'border-[var(--color-line)]'

  const chrome = (
    <div className={`flex flex-wrap items-center justify-between gap-3 text-sm mb-4 ${muted}`}>
      <p>
        ← → navigate · space reveal · f fullscreen · t tone · esc exit · slide {idx + 1}/{slides.length}
      </p>
      <div className={`inline-flex border ${optionBorder}`}>
        <button
          type="button"
          className={`min-h-9 px-3 text-xs ${tone === 'theatrical' ? (dark ? 'bg-stone-100 text-stone-900' : 'bg-[var(--color-ink)] text-[var(--color-paper)]') : ''}`}
          onClick={() => setTone('theatrical')}
        >
          Theatrical
        </button>
        <button
          type="button"
          className={`min-h-9 px-3 text-xs border-l ${optionBorder} ${tone === 'modernist' ? (dark ? 'bg-stone-100 text-stone-900' : 'bg-[var(--color-ink)] text-[var(--color-paper)]') : ''}`}
          onClick={() => setTone('modernist')}
        >
          Modernist
        </button>
      </div>
    </div>
  )

  if (slide.type === 'leaderboard') {
    return (
      <div className={shell}>
        {chrome}
        <h1 className="font-display text-3xl md:text-4xl font-bold mb-10">Leaderboard</h1>
        <ol className="space-y-4 max-w-3xl">
          {data.leaderboard.map((r, i) => (
            <motion.li
              key={`${r.rank}-${r.name}`}
              initial={{ opacity: 0, y: 12 }}
              animate={{ opacity: 1, y: 0 }}
              transition={{ delay: i * 0.08 }}
              className={`flex justify-between text-3xl border-b ${rowBorder} pb-3`}
            >
              <span>
                <span className={`${muted} mr-4`}>#{r.rank}</span>
                {r.name}
              </span>
              <span className={scoreColor}>{r.score}</span>
            </motion.li>
          ))}
        </ol>
      </div>
    )
  }

  const maxPick = Math.max(1, ...slide.stats.map((s) => s.pick_count))
  const correct = slide.options.find((o) => o.is_correct)

  return (
    <div className={shell}>
      {chrome}
      <h1 className="font-display text-3xl md:text-4xl font-bold leading-tight mb-6">{slide.quiz.title}</h1>
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
              className={`border p-4 ${revealed && isCorrect ? correctHighlight : optionBorder}`}
            >
              <RichContent html={o.label_html || '—'} />
              {revealed && (
                <div className="mt-3">
                  <div className={`h-3 overflow-hidden ${barTrack}`}>
                    <div
                      className={`h-full ${isCorrect ? barCorrect : ''}`}
                      style={{
                        width: `${(pick / maxPick) * 100}%`,
                        background: isCorrect ? undefined : barOther,
                      }}
                    />
                  </div>
                  <p className={`text-base mt-1 ${muted}`}>{pick} picks</p>
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
          <button type="button" className={`text-base underline ${muted}`} onClick={() => setWhoOpen((v) => !v)}>
            {whoOpen ? 'Hide' : 'Show'} who answered what
          </button>
          {whoOpen && (
            <p className={`text-base ${muted}`}>
              Open Results → participant drill-down for the full name → option list (kept out of the projector by
              default).
            </p>
          )}
        </div>
      )}
      <div className="fixed bottom-4 right-4 opacity-40">
        <img src={`${API_BASE}/api/admin/qrcode`} alt="QR" className="w-28 h-28 bg-white p-1" />
      </div>
    </div>
  )
}
