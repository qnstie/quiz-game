import { type FormEvent, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api } from '../../api/client'
import { TipTapEditor } from '../../components/TipTapEditor'

type Project = {
  id: string
  slug: string
  title: string
  state: string
  description_html?: string
  shuffle_quizzes: number
  require_pin: number
  results_stale: number
}

export function AdminProjectsPage() {
  const qc = useQueryClient()
  const { data, isLoading } = useQuery({
    queryKey: ['admin-projects'],
    queryFn: () =>
      api<{ projects: Project[]; activeProjectId: string | null; publicProjectId: string | null }>(
        '/api/admin/projects',
      ),
  })
  const [title, setTitle] = useState('')
  const [slug, setSlug] = useState('')

  const create = useMutation({
    mutationFn: () => api('/api/admin/projects', { method: 'POST', json: { title, slug } }),
    onSuccess: async () => {
      setTitle('')
      setSlug('')
      await qc.invalidateQueries({ queryKey: ['admin-projects'] })
    },
  })

  const setState = useMutation({
    mutationFn: ({ id, state }: { id: string; state: string }) =>
      api(`/api/admin/projects/${id}/state`, { method: 'POST', json: { state } }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['admin-projects'] }),
  })

  const select = useMutation({
    mutationFn: (id: string) => api(`/api/admin/projects/${id}/select`, { method: 'POST', json: { public: true } }),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['admin-projects'] })
      void qc.invalidateQueries({ queryKey: ['admin-me'] })
    },
  })

  const patchDesc = useMutation({
    mutationFn: ({ id, description_html }: { id: string; description_html: string }) =>
      api(`/api/admin/projects/${id}`, { method: 'PATCH', json: { description_html } }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['admin-projects'] }),
  })

  if (isLoading || !data) return <p>Loading…</p>

  const onCreate = (e: FormEvent) => {
    e.preventDefault()
    create.mutate()
  }

  return (
    <div className="space-y-8">
      <div>
        <h1 className="font-display text-3xl font-bold">Projects</h1>
        <p className="text-[var(--color-muted)] text-sm mt-1">
          Project switcher is intended for SETUP; you can still manage state here anytime.
        </p>
      </div>

      <form onSubmit={onCreate} className="flex flex-wrap gap-2 items-end">
        <label className="space-y-1">
          <span className="text-xs font-semibold">Title</span>
          <input className="min-h-11 rounded-lg border px-3" value={title} onChange={(e) => setTitle(e.target.value)} required />
        </label>
        <label className="space-y-1">
          <span className="text-xs font-semibold">Slug</span>
          <input className="min-h-11 rounded-lg border px-3" value={slug} onChange={(e) => setSlug(e.target.value)} required pattern="[a-z0-9\-]+" />
        </label>
        <button type="submit" className="min-h-11 px-4 rounded-lg bg-[var(--color-accent)] text-white font-semibold">
          Create
        </button>
      </form>

      <ul className="space-y-4">
        {data.projects.map((p) => (
          <li key={p.id} className="rounded-2xl border border-[var(--color-line)] p-4 space-y-3">
            <div className="flex flex-wrap justify-between gap-2">
              <div>
                <h2 className="font-semibold text-lg">{p.title}</h2>
                <p className="text-sm text-[var(--color-muted)]">
                  {p.slug} · {p.state}
                  {data.activeProjectId === p.id && ' · active'}
                  {data.publicProjectId === p.id && ' · public'}
                </p>
              </div>
              <div className="flex flex-wrap gap-2">
                <button type="button" className="min-h-10 px-3 rounded-lg border" onClick={() => select.mutate(p.id)}>
                  Select
                </button>
                {(['SETUP', 'ACTIVE', 'CLOSED', 'REVEALED'] as const).map((s) => (
                  <button
                    key={s}
                    type="button"
                    className={`min-h-10 px-3 rounded-lg border text-sm ${p.state === s ? 'bg-stone-900 text-white' : ''}`}
                    onClick={() => {
                      if (s !== p.state && !window.confirm(`Switch ${p.title} to ${s}?`)) return
                      setState.mutate({ id: p.id, state: s })
                    }}
                  >
                    {s}
                  </button>
                ))}
              </div>
            </div>
            {p.state === 'SETUP' && (
              <div className="space-y-2">
                <p className="text-xs font-semibold">Description (HTML)</p>
                <TipTapEditor
                  value={p.description_html || ''}
                  onChange={(html) => patchDesc.mutate({ id: p.id, description_html: html })}
                />
              </div>
            )}
          </li>
        ))}
      </ul>
    </div>
  )
}
