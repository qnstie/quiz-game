import { useEffect, useState } from 'react'
import { X } from 'lucide-react'
import { RichContent } from './RichContent'

export type PreviewOption = {
  id: string
  label_html: string
  is_correct?: number
  feedback_html?: string
}

export type PreviewQuiz = {
  id: string
  title: string
  description_html: string
  explanation_html?: string
  options?: PreviewOption[]
}

/** Participant-like render of a quiz prompt + options. */
export function QuizPreview({
  quiz,
  interactive = true,
  showExplanation = false,
}: {
  quiz: PreviewQuiz
  interactive?: boolean
  showExplanation?: boolean
}) {
  const [picked, setPicked] = useState<string | null>(null)
  const options = quiz.options ?? []

  useEffect(() => {
    setPicked(null)
  }, [quiz.id])

  return (
    <div className="space-y-5">
      <h2 className="font-display text-2xl font-bold leading-tight">{quiz.title || 'Untitled'}</h2>
      {quiz.description_html?.trim() ? (
        <div className="rounded-2xl bg-white/80 p-4 border border-[var(--color-line)]">
          <RichContent html={quiz.description_html} />
        </div>
      ) : (
        <p className="text-sm text-[var(--color-muted)] italic">No description yet.</p>
      )}
      <ul className="space-y-3">
        {options.map((o, i) => {
          const isSel = picked === o.id
          const showFb = interactive && isSel && o.feedback_html?.trim()
          return (
            <li key={o.id}>
              <button
                type="button"
                disabled={!interactive}
                onClick={() => interactive && setPicked(o.id)}
                className={`w-full min-h-12 text-left rounded-2xl px-4 py-3 transition-all text-[var(--color-ink)] bg-white ${
                  isSel
                    ? 'border-[3px] border-[#c23b2a] shadow-[0_0_0_1px_#c23b2a]'
                    : 'border border-[var(--color-line)]'
                } ${!interactive ? 'cursor-default' : ''}`}
              >
                <div className="flex items-start gap-3">
                  <span className="text-xs font-semibold text-[var(--color-muted)] mt-1 w-4 shrink-0">
                    {String.fromCharCode(65 + i)}
                  </span>
                  <RichContent html={o.label_html?.trim() ? o.label_html : '—'} className="flex-1" />
                  {!!o.is_correct && (
                    <span className="text-xs font-semibold text-emerald-700 shrink-0">Correct</span>
                  )}
                </div>
              </button>
              {showFb && (
                <div className="mt-2 ml-6 rounded-xl border border-[var(--color-line)] bg-[var(--color-paper)] p-3 text-sm">
                  <p className="text-xs font-semibold text-[var(--color-muted)] mb-1">Feedback</p>
                  <RichContent html={o.feedback_html!} />
                </div>
              )}
            </li>
          )
        })}
      </ul>
      {showExplanation && quiz.explanation_html?.trim() ? (
        <div className="rounded-2xl border border-[var(--color-line)] bg-[var(--color-paper)] p-4">
          <p className="text-xs font-semibold text-[var(--color-muted)] mb-2">Explanation</p>
          <RichContent html={quiz.explanation_html} />
        </div>
      ) : null}
    </div>
  )
}

export function QuizPreviewModal({
  quiz,
  onClose,
}: {
  quiz: PreviewQuiz
  onClose: () => void
}) {
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose()
    }
    window.addEventListener('keydown', onKey)
    const prev = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    return () => {
      window.removeEventListener('keydown', onKey)
      document.body.style.overflow = prev
    }
  }, [onClose])

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/45"
      role="dialog"
      aria-modal="true"
      aria-label="Question preview"
      onClick={onClose}
    >
      <div
        className="relative w-full max-w-lg max-h-[90vh] overflow-y-auto bg-[var(--color-paper)] border border-[var(--color-line)] shadow-xl p-5 sm:p-6"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="flex items-center justify-between gap-3 mb-4">
          <p className="text-xs font-semibold uppercase tracking-wide text-[var(--color-muted)]">
            Question preview
          </p>
          <button
            type="button"
            className="min-h-9 min-w-9 inline-flex items-center justify-center border border-[var(--color-line)] hover:bg-[var(--color-accent-soft)]"
            onClick={onClose}
            aria-label="Close"
          >
            <X size={16} />
          </button>
        </div>
        <QuizPreview quiz={quiz} interactive showExplanation />
      </div>
    </div>
  )
}

/** Live HTML render beside an editor. */
export function HtmlPreviewPane({ html, label = 'Preview' }: { html: string; label?: string }) {
  const empty = !html?.trim() || html === '<p></p>'
  return (
    <div className="border border-[var(--color-line)] bg-[var(--color-paper)] min-h-28 flex flex-col overflow-hidden">
      <div className="px-3 py-1.5 border-b border-[var(--color-line)] text-[10px] font-semibold uppercase tracking-wide text-[var(--color-muted)]">
        {label}
      </div>
      <div className="p-3 flex-1">
        {empty ? (
          <p className="text-sm text-[var(--color-muted)] italic">Nothing to preview</p>
        ) : (
          <RichContent html={html} className="prose-sm" />
        )}
      </div>
    </div>
  )
}
