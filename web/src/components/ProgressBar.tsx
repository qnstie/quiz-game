import { Link } from 'react-router-dom'

export function ProgressBar({
  answered,
  total,
  onClick,
  hint,
}: {
  answered: number
  total: number
  onClick?: () => void
  hint?: string
}) {
  const pct = total > 0 ? Math.round((answered / total) * 100) : 0
  const inner = (
    <div className="w-full">
      <div className="flex justify-between text-sm text-[var(--color-muted)] mb-1 gap-2">
        <span>
          {answered} / {total}
          {hint ? <span className="opacity-70"> · {hint}</span> : null}
        </span>
        <span>{pct}%</span>
      </div>
      <div className="h-2 rounded-full bg-[var(--color-line)] overflow-hidden">
        <div
          className="h-full bg-[var(--color-accent)] transition-all duration-300"
          style={{ width: `${pct}%` }}
        />
      </div>
    </div>
  )
  if (onClick) {
    return (
      <button
        type="button"
        className="w-full text-left min-h-12 rounded-xl px-1 -mx-1 hover:bg-black/[0.03]"
        onClick={onClick}
        aria-label={hint}
      >
        {inner}
      </button>
    )
  }
  return <Link to="/quizzes">{inner}</Link>
}
