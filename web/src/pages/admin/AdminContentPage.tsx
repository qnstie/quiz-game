import { useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, ApiError } from '../../api/client'
import { TipTapEditor } from '../../components/TipTapEditor'
import { useAdminMe } from './AdminShell'

type Option = {
  id: string
  label_html: string
  is_correct: number
  feedback_html: string
}

type Quiz = {
  id: string
  title: string
  description_html: string
  explanation_html: string
  points: number
  shuffle_options: number
  options?: Option[]
  completeness?: { complete: boolean; issues: string[] }
}

export function AdminContentPage() {
  const me = useAdminMe()
  const projectId = me.data?.activeProjectId
  const qc = useQueryClient()

  const { data, isLoading } = useQuery({
    queryKey: ['admin-quizzes', projectId],
    enabled: !!projectId,
    queryFn: () => api<{ quizzes: Quiz[] }>(`/api/admin/projects/${projectId}/quizzes`),
  })

  const create = useMutation({
    mutationFn: () => api(`/api/admin/projects/${projectId}/quizzes`, { method: 'POST', json: { title: 'New quiz' } }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['admin-quizzes', projectId] }),
  })

  const reorder = useMutation({
    mutationFn: (orderedIds: string[]) =>
      api(`/api/admin/projects/${projectId}/quizzes/reorder`, { method: 'POST', json: { orderedIds } }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['admin-quizzes', projectId] }),
  })

  if (!projectId) return <p>Select an active project first.</p>
  if (isLoading || !data) return <p>Loading…</p>

  const move = (id: string, dir: -1 | 1) => {
    const ids = data.quizzes.map((q) => q.id)
    const i = ids.indexOf(id)
    const j = i + dir
    if (j < 0 || j >= ids.length) return
    ;[ids[i], ids[j]] = [ids[j], ids[i]]
    reorder.mutate(ids)
  }

  return (
    <div className="space-y-6">
      <div className="flex justify-between items-center gap-3">
        <h1 className="font-display text-3xl font-bold">Content</h1>
        <button
          type="button"
          className="min-h-11 px-4 rounded-xl bg-[var(--color-accent)] text-white font-semibold"
          onClick={() => create.mutate()}
        >
          Add quiz
        </button>
      </div>
      <ul className="space-y-2">
        {data.quizzes.map((q, idx) => (
          <li key={q.id} className="rounded-xl border border-[var(--color-line)] px-4 py-3 flex flex-wrap gap-3 items-center">
            <span className="text-[var(--color-muted)] w-6">{idx + 1}</span>
            <Link to={`/admin/content/${q.id}`} className="flex-1 font-medium hover:underline">
              {q.title}
            </Link>
            {!q.completeness?.complete && (
              <span className="text-xs text-amber-700">{q.completeness?.issues?.join(', ')}</span>
            )}
            {q.completeness?.complete && <span className="text-teal-700 text-sm">✓</span>}
            <button type="button" className="text-sm min-h-10 px-2" onClick={() => move(q.id, -1)}>
              ↑
            </button>
            <button type="button" className="text-sm min-h-10 px-2" onClick={() => move(q.id, 1)}>
              ↓
            </button>
          </li>
        ))}
      </ul>
    </div>
  )
}

export function AdminQuizEditorPage() {
  const { quizId = '' } = useParams()
  const navigate = useNavigate()
  const qc = useQueryClient()
  const [error, setError] = useState<string | null>(null)
  const [opts, setOpts] = useState<Option[]>([])

  const { data, isLoading } = useQuery({
    queryKey: ['admin-quiz', quizId],
    queryFn: () => api<{ quiz: Quiz & { options: Option[]; projectId: string } }>(`/api/admin/quizzes/${quizId}`),
  })

  useEffect(() => {
    if (data?.quiz.options) setOpts(data.quiz.options)
  }, [data])

  const saveQuiz = useMutation({
    mutationFn: (body: Partial<Quiz>) => api(`/api/admin/quizzes/${quizId}`, { method: 'PATCH', json: body }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['admin-quiz', quizId] }),
    onError: (e: unknown) => setError(e instanceof ApiError ? `${e.code}: ${e.message}` : 'Save failed'),
  })

  const saveOptions = useMutation({
    mutationFn: (options: Option[]) =>
      api(`/api/admin/quizzes/${quizId}/options`, {
        method: 'PUT',
        json: {
          options: options.map((o) => ({
            id: o.id,
            label_html: o.label_html,
            feedback_html: o.feedback_html,
            is_correct: !!o.is_correct,
          })),
        },
      }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['admin-quiz', quizId] }),
    onError: (e: unknown) => setError(e instanceof ApiError ? `${e.code}: ${e.message}` : 'Save failed'),
  })

  const remove = useMutation({
    mutationFn: () =>
      api(`/api/admin/quizzes/${quizId}`, {
        method: 'DELETE',
        json: { confirm: data?.quiz.title ?? 'DELETE' },
      }),
    onSuccess: () => navigate('/admin/content'),
  })

  if (isLoading || !data) return <p>Loading…</p>
  const quiz = data.quiz

  return (
    <div className="space-y-6 max-w-3xl">
      <Link to="/admin/content" className="text-sm text-[var(--color-accent)]">
        ← Content
      </Link>
      <h1 className="font-display text-3xl font-bold">Edit quiz</h1>
      {error && <p className="text-red-700 text-sm">{error}</p>}

      <label className="block space-y-1">
        <span className="text-sm font-semibold">Title</span>
        <input
          className="w-full min-h-12 rounded-xl border px-3"
          defaultValue={quiz.title}
          onBlur={(e) => saveQuiz.mutate({ title: e.target.value })}
        />
      </label>

      <label className="flex items-center gap-2 min-h-12">
        <input
          type="checkbox"
          defaultChecked={!!quiz.shuffle_options}
          onChange={(e) => saveQuiz.mutate({ shuffle_options: e.target.checked ? 1 : 0 })}
        />
        <span className="text-sm">Shuffle options for participants</span>
      </label>

      <div className="space-y-2">
        <p className="text-sm font-semibold">Description / prompt</p>
        <TipTapEditor value={quiz.description_html} onChange={(html) => saveQuiz.mutate({ description_html: html })} />
      </div>

      <div className="space-y-2">
        <p className="text-sm font-semibold">Explanation (after wrap-up)</p>
        <TipTapEditor value={quiz.explanation_html} onChange={(html) => saveQuiz.mutate({ explanation_html: html })} />
      </div>

      <div className="space-y-4">
        <h2 className="font-semibold text-lg">Options (exactly one correct)</h2>
        {opts.map((o, i) => (
          <div key={o.id} className="rounded-xl border p-3 space-y-2">
            <div className="flex items-center gap-2">
              <input
                type="radio"
                name="correct"
                checked={!!o.is_correct}
                onChange={() =>
                  setOpts((prev) => prev.map((x, j) => ({ ...x, is_correct: j === i ? 1 : 0 })))
                }
              />
              <span className="text-sm font-semibold">Option {i + 1} — mark correct</span>
            </div>
            <TipTapEditor
              value={o.label_html}
              onChange={(html) => setOpts((prev) => prev.map((x, j) => (j === i ? { ...x, label_html: html } : x)))}
            />
            <p className="text-xs font-semibold">Feedback</p>
            <TipTapEditor
              value={o.feedback_html}
              onChange={(html) => setOpts((prev) => prev.map((x, j) => (j === i ? { ...x, feedback_html: html } : x)))}
            />
          </div>
        ))}
        <button
          type="button"
          className="min-h-11 px-4 rounded-xl bg-[var(--color-ink)] text-white font-semibold"
          onClick={() => saveOptions.mutate(opts)}
        >
          Save options
        </button>
      </div>

      <button
        type="button"
        className="text-red-700 text-sm underline min-h-10"
        onClick={() => {
          if (window.confirm(`Delete "${quiz.title}"?`)) remove.mutate()
        }}
      >
        Delete quiz
      </button>
    </div>
  )
}
