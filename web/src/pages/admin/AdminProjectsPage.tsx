import { type FormEvent, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { FilePenLine, Plus, Trash2 } from 'lucide-react'
import { api, ApiError } from '../../api/client'
import { TipTapEditor } from '../../components/TipTapEditor'
import { isContentEditable } from '../../lib/projectState'

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

const STATES = ['SETUP', 'TEST', 'ACTIVE', 'CLOSED', 'REVEALED'] as const

export function AdminProjectsPage() {
  const navigate = useNavigate()
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
      api<{ project: Project }>(`/api/admin/projects/${id}/state`, { method: 'POST', json: { state } }),
    onSuccess: (res, { id }) => {
      qc.setQueryData<{ projects: Project[]; activeProjectId: string | null; publicProjectId: string | null }>(
        ['admin-projects'],
        (old) => {
          if (!old) return old
          return {
            ...old,
            activeProjectId:
              res.project.state === 'ACTIVE' || res.project.state === 'TEST' ? id : old.activeProjectId,
            publicProjectId:
              res.project.state === 'ACTIVE' || res.project.state === 'TEST' ? id : old.publicProjectId,
            projects: old.projects.map((p) =>
              p.id === id ? { ...p, state: res.project.state, results_stale: res.project.results_stale } : p,
            ),
          }
        },
      )
      void qc.invalidateQueries({ queryKey: ['admin-projects'] })
      void qc.invalidateQueries({ queryKey: ['admin-me'] })
    },
    onError: (e: unknown) => {
      window.alert(e instanceof ApiError ? e.message : 'Failed to change state')
    },
  })

  const select = useMutation({
    mutationFn: (id: string) => api(`/api/admin/projects/${id}/select`, { method: 'POST', json: { public: true } }),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['admin-projects'] })
      void qc.invalidateQueries({ queryKey: ['admin-me'] })
    },
  })

  const editContent = useMutation({
    mutationFn: async (id: string) => {
      if (data?.activeProjectId !== id) {
        await api(`/api/admin/projects/${id}/select`, { method: 'POST', json: { public: true } })
      }
    },
    onSuccess: async () => {
      await qc.invalidateQueries({ queryKey: ['admin-projects'] })
      await qc.invalidateQueries({ queryKey: ['admin-me'] })
      navigate('/admin/content')
    },
  })

  const patchProject = useMutation({
    mutationFn: ({ id, body }: { id: string; body: Record<string, unknown> }) =>
      api(`/api/admin/projects/${id}`, { method: 'PATCH', json: body }),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['admin-projects'] }),
  })

  const removeProject = useMutation({
    mutationFn: (id: string) => api(`/api/admin/projects/${id}`, { method: 'DELETE' }),
    onSuccess: async () => {
      await qc.invalidateQueries({ queryKey: ['admin-projects'] })
      await qc.invalidateQueries({ queryKey: ['admin-me'] })
    },
    onError: (e: unknown) => {
      window.alert(e instanceof ApiError ? e.message : 'Failed to delete project')
    },
  })

  if (isLoading || !data) return <p>Loading…</p>

  const onCreate = (e: FormEvent) => {
    e.preventDefault()
    create.mutate()
  }

  return (
    <div className="space-y-8">
      <div>
        <h1 className="font-display text-2xl font-bold">Projects</h1>
        <p className="text-[var(--color-muted)] text-sm mt-1">
          Edit in SETUP (private) or TEST (live for testers). ACTIVE is live and read-only for content.
        </p>
      </div>

      <form onSubmit={onCreate} className="flex flex-wrap gap-2 items-end">
        <label className="space-y-1">
          <span className="text-xs font-semibold">Title</span>
          <input className="input-field w-auto min-w-[12rem]" value={title} onChange={(e) => setTitle(e.target.value)} required />
        </label>
        <label className="space-y-1">
          <span className="text-xs font-semibold">Slug</span>
          <input
            className="input-field w-auto min-w-[10rem]"
            value={slug}
            onChange={(e) => setSlug(e.target.value)}
            required
            pattern="[a-z0-9\-]+"
          />
        </label>
        <button type="submit" className="btn-primary">
          <Plus size={16} aria-hidden />
          Create
        </button>
      </form>

      <ul className="divide-y divide-[var(--color-line)] border-y border-[var(--color-line)]">
        {data.projects.map((p) => {
          const locked = !isContentEditable(p.state)
          return (
            <li key={p.id} className="py-5 space-y-3">
              {p.state === 'TEST' && (
                <p className="text-sm bg-[var(--color-accent-soft)] text-[var(--color-accent-strong)] px-3 py-2">
                  TEST mode: content is editable and participants can join and answer.
                </p>
              )}
              {locked && (
                <p className="text-sm bg-[var(--color-accent-soft)] text-[var(--color-accent-strong)] px-3 py-2">
                  Read-only while {p.state}. Switch to SETUP or TEST to edit title, slug, or description.
                </p>
              )}
              <div className="flex flex-wrap justify-between gap-3">
                <div className="space-y-2 min-w-[16rem] flex-1">
                  <label className="block space-y-1">
                    <span className="text-xs font-semibold text-[var(--color-muted)]">Title</span>
                    <input
                      className="input-field font-semibold text-lg"
                      defaultValue={p.title}
                      key={`${p.id}-title-${p.title}`}
                      disabled={locked}
                      readOnly={locked}
                      onBlur={(e) => {
                        if (locked) return
                        const next = e.target.value.trim()
                        if (next && next !== p.title) {
                          patchProject.mutate({ id: p.id, body: { title: next } })
                        }
                      }}
                    />
                  </label>
                  <label className="block space-y-1 max-w-xs">
                    <span className="text-xs font-semibold text-[var(--color-muted)]">Slug</span>
                    <input
                      className="input-field text-sm"
                      defaultValue={p.slug}
                      key={`${p.id}-slug-${p.slug}`}
                      pattern="[a-z0-9\-]+"
                      disabled={locked}
                      readOnly={locked}
                      onBlur={(e) => {
                        if (locked) return
                        const next = e.target.value.trim()
                        if (next && next !== p.slug) {
                          patchProject.mutate({ id: p.id, body: { slug: next } })
                        }
                      }}
                    />
                  </label>
                  <p className="text-sm text-[var(--color-muted)]">
                    {data.activeProjectId === p.id && (
                      <span className="pill pill-accent mr-1">active</span>
                    )}
                    {data.publicProjectId === p.id && (
                      <span className="pill pill-neutral mr-1">public</span>
                    )}
                  </p>
                </div>
                <div className="flex flex-wrap gap-2 items-start">
                  <button
                    type="button"
                    className="btn-primary min-h-10"
                    disabled={editContent.isPending}
                    onClick={() => editContent.mutate(p.id)}
                  >
                    <FilePenLine size={16} aria-hidden />
                    {locked ? 'View content' : 'Edit content'}
                  </button>
                  <button
                    type="button"
                    className="btn-secondary min-h-10 disabled:opacity-40 disabled:cursor-not-allowed"
                    disabled={data.activeProjectId === p.id}
                    onClick={() => select.mutate(p.id)}
                  >
                    {data.activeProjectId === p.id ? 'Selected' : 'Select'}
                  </button>
                  <button
                    type="button"
                    className="btn-secondary min-h-10 text-red-700 border-red-300 inline-flex items-center gap-1.5"
                    disabled={removeProject.isPending}
                    onClick={() => {
                      const msg =
                        p.state === 'SETUP'
                          ? `Delete project “${p.title}”? This cannot be undone.`
                          : `Delete project “${p.title}” (currently ${p.state})? Quizzes and participant data will be removed. This cannot be undone.`
                      if (!window.confirm(msg)) return
                      removeProject.mutate(p.id)
                    }}
                  >
                    <Trash2 size={16} aria-hidden />
                    Delete
                  </button>
                  <div className="inline-flex border border-[var(--color-line)]">
                    {STATES.map((s, i) => (
                      <button
                        key={s}
                        type="button"
                        className={`min-h-10 px-3 text-sm ${
                          i > 0 ? 'border-l border-[var(--color-line)]' : ''
                        } ${
                          p.state === s
                            ? 'bg-[var(--color-ink)] text-[var(--color-paper)] font-semibold'
                            : 'bg-transparent hover:bg-[var(--color-accent-soft)]'
                        }`}
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
              </div>
              <div className="space-y-2">
                <p className="text-xs font-semibold">Description (HTML)</p>
                <TipTapEditor
                  value={p.description_html || ''}
                  projectId={p.id}
                  editable={!locked}
                  onBlurSave={(html) => {
                    if (locked) return
                    if (html === (p.description_html || '')) return
                    patchProject.mutate({ id: p.id, body: { description_html: html } })
                  }}
                />
              </div>
            </li>
          )
        })}
      </ul>
    </div>
  )
}
