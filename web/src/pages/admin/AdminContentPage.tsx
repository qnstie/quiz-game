import { useEffect, useMemo, useRef, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  DndContext,
  closestCenter,
  KeyboardSensor,
  PointerSensor,
  useSensor,
  useSensors,
  type DragEndEvent,
} from '@dnd-kit/core'
import {
  SortableContext,
  arrayMove,
  sortableKeyboardCoordinates,
  useSortable,
  verticalListSortingStrategy,
} from '@dnd-kit/sortable'
import { CSS } from '@dnd-kit/utilities'
import { ArrowLeft, Copy, Download, Eye, GripVertical, Plus, Trash2, Upload } from 'lucide-react'
import { api, ApiError } from '../../api/client'
import { HtmlPreviewPane, QuizPreviewModal } from '../../components/QuizPreview'
import { TipTapEditor } from '../../components/TipTapEditor'
import { isContentEditable } from '../../lib/projectState'
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
  projectId?: string
  projectState?: string
}

type Project = {
  id: string
  title: string
  slug: string
  state: string
}

function SortableQuizRow({
  quiz,
  index,
  selected,
  onToggle,
  onPreview,
  locked,
}: {
  quiz: Quiz
  index: number
  selected: boolean
  onToggle: (id: string) => void
  onPreview: (quiz: Quiz) => void
  locked: boolean
}) {
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({
    id: quiz.id,
    disabled: locked,
  })
  const style = {
    transform: CSS.Transform.toString(transform),
    transition,
    opacity: isDragging ? 0.7 : 1,
  }

  return (
    <tr ref={setNodeRef} style={style} className={isDragging ? 'bg-[var(--color-accent-soft)]' : undefined}>
      <td>
        <input
          type="checkbox"
          checked={selected}
          disabled={false}
          onChange={() => onToggle(quiz.id)}
          aria-label={`Select ${quiz.title}`}
        />
      </td>
      <td className="text-[var(--color-muted)] w-10">{index + 1}</td>
      <td>
        {!locked && (
          <button
            type="button"
            className="min-h-9 min-w-9 inline-flex items-center justify-center text-[var(--color-muted)] cursor-grab active:cursor-grabbing"
            aria-label="Drag to reorder"
            {...attributes}
            {...listeners}
          >
            <GripVertical size={16} />
          </button>
        )}
      </td>
      <td>
        <Link to={`/admin/content/${quiz.id}`} className="font-medium hover:underline">
          {quiz.title}
        </Link>
      </td>
      <td>
        {quiz.completeness?.complete ? (
          <span className="pill pill-accent">Complete</span>
        ) : (
          <span className="pill pill-neutral">{quiz.completeness?.issues?.join(', ') || 'Incomplete'}</span>
        )}
      </td>
      <td>
        <button
          type="button"
          className="btn-secondary min-h-9 text-sm inline-flex items-center gap-1.5"
          onClick={() => onPreview(quiz)}
        >
          <Eye size={14} aria-hidden />
          Preview
        </button>
      </td>
    </tr>
  )
}

function EditorWithPreview({
  label,
  value,
  projectId,
  onChange,
  onBlurSave,
  editable = true,
}: {
  label: string
  value: string
  projectId?: string
  onChange?: (html: string) => void
  onBlurSave?: (html: string) => void
  editable?: boolean
}) {
  return (
    <div className="space-y-2">
      <p className="text-sm font-semibold">{label}</p>
      <div className="grid gap-3 lg:grid-cols-2">
        <TipTapEditor
          value={value}
          projectId={projectId}
          onChange={onChange}
          onBlurSave={onBlurSave}
          editable={editable}
        />
        <HtmlPreviewPane html={value} />
      </div>
    </div>
  )
}

function htmlIsEmpty(html: string) {
  return html.replace(/<[^>]*>/g, '').replace(/&nbsp;/g, ' ').trim() === ''
}

function OptionalFeedbackEditor({
  value,
  projectId,
  onChange,
  onBlurSave,
  editable = true,
}: {
  value: string
  projectId?: string
  onChange?: (html: string) => void
  onBlurSave?: (html: string) => void
  editable?: boolean
}) {
  const hasContent = !htmlIsEmpty(value)
  const [open, setOpen] = useState(hasContent)

  useEffect(() => {
    if (hasContent) setOpen(true)
  }, [hasContent])

  if (!open) {
    return (
      <button
        type="button"
        className="text-sm text-[var(--color-accent)] hover:underline min-h-9 disabled:opacity-40"
        disabled={!editable}
        onClick={() => setOpen(true)}
      >
        + Add feedback (optional)
      </button>
    )
  }

  return (
    <div className="space-y-2">
      <div className="flex items-center justify-between gap-2">
        <p className="text-sm font-semibold">Feedback (optional)</p>
        {!hasContent && editable && (
          <button
            type="button"
            className="text-xs text-[var(--color-muted)] hover:underline min-h-8"
            onClick={() => setOpen(false)}
          >
            Collapse
          </button>
        )}
      </div>
      <div className="grid gap-3 lg:grid-cols-2">
        <TipTapEditor
          value={value}
          projectId={projectId}
          onChange={onChange}
          onBlurSave={onBlurSave}
          editable={editable}
        />
        <HtmlPreviewPane html={value} />
      </div>
    </div>
  )
}

export function AdminContentPage() {
  const me = useAdminMe()
  const navigate = useNavigate()
  const projectId = me.data?.activeProjectId
  const qc = useQueryClient()
  const [selected, setSelected] = useState<Set<string>>(new Set())
  const [copyTarget, setCopyTarget] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [previewQuiz, setPreviewQuiz] = useState<Quiz | null>(null)
  const importInputRef = useRef<HTMLInputElement>(null)

  const projectsQ = useQuery({
    queryKey: ['admin-projects'],
    queryFn: () =>
      api<{ projects: Project[]; activeProjectId: string | null }>('/api/admin/projects'),
  })

  const { data, isLoading } = useQuery({
    queryKey: ['admin-quizzes', projectId],
    enabled: !!projectId,
    queryFn: () => api<{ quizzes: Quiz[] }>(`/api/admin/projects/${projectId}/quizzes`),
  })

  const [items, setItems] = useState<Quiz[]>([])
  useEffect(() => {
    if (data?.quizzes) setItems(data.quizzes)
  }, [data])

  const activeProject = useMemo(
    () => projectsQ.data?.projects.find((p) => p.id === projectId) ?? null,
    [projectsQ.data, projectId],
  )

  const otherProjects = useMemo(
    () => (projectsQ.data?.projects ?? []).filter((p) => p.id !== projectId),
    [projectsQ.data, projectId],
  )

  const sensors = useSensors(
    useSensor(PointerSensor, { activationConstraint: { distance: 6 } }),
    useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates }),
  )

  const invalidate = () => {
    void qc.invalidateQueries({ queryKey: ['admin-quizzes', projectId] })
  }

  const create = useMutation({
    mutationFn: () =>
      api<{ quiz: Quiz & { options?: Option[]; projectId?: string } }>(
        `/api/admin/projects/${projectId}/quizzes`,
        { method: 'POST', json: { title: 'New quiz' } },
      ),
    onSuccess: (res) => {
      invalidate()
      if (res.quiz?.id) {
        qc.setQueryData(['admin-quiz', res.quiz.id], {
          quiz: {
            ...res.quiz,
            projectId,
            options: res.quiz.options ?? [],
          },
        })
        navigate(`/admin/content/${res.quiz.id}`)
      }
    },
  })

  const reorder = useMutation({
    mutationFn: (orderedIds: string[]) =>
      api(`/api/admin/projects/${projectId}/quizzes/reorder`, { method: 'POST', json: { orderedIds } }),
    onError: (e: unknown) => {
      setError(e instanceof ApiError ? e.message : 'Reorder failed')
      invalidate()
    },
  })

  const clone = useMutation({
    mutationFn: (quizIds: string[]) =>
      api(`/api/admin/projects/${projectId}/quizzes/clone`, { method: 'POST', json: { quizIds } }),
    onSuccess: () => {
      setSelected(new Set())
      invalidate()
    },
    onError: (e: unknown) => setError(e instanceof ApiError ? `${e.code}: ${e.message}` : 'Clone failed'),
  })

  const copy = useMutation({
    mutationFn: ({ quizIds, targetProjectId }: { quizIds: string[]; targetProjectId: string }) =>
      api(`/api/admin/projects/${projectId}/quizzes/copy`, {
        method: 'POST',
        json: { quizIds, targetProjectId },
      }),
    onSuccess: () => {
      setSelected(new Set())
      setCopyTarget('')
      setError(null)
    },
    onError: (e: unknown) => setError(e instanceof ApiError ? `${e.code}: ${e.message}` : 'Copy failed'),
  })

  const batchDelete = useMutation({
    mutationFn: (quizIds: string[]) =>
      api(`/api/admin/projects/${projectId}/quizzes/batch-delete`, {
        method: 'POST',
        json: { quizIds },
      }),
    onSuccess: () => {
      setSelected(new Set())
      invalidate()
    },
    onError: (e: unknown) => setError(e instanceof ApiError ? `${e.code}: ${e.message}` : 'Delete failed'),
  })

  const exportPack = useMutation({
    mutationFn: async (quizIds: string[]) => {
      const blob = await api<Blob>(`/api/admin/projects/${projectId}/quizzes/export-pack`, {
        method: 'POST',
        json: { quizIds },
      })
      const url = URL.createObjectURL(blob)
      const a = document.createElement('a')
      a.href = url
      a.download = quizIds.length === 1 ? 'quiz.zip' : `quizzes-${quizIds.length}.zip`
      document.body.appendChild(a)
      a.click()
      a.remove()
      URL.revokeObjectURL(url)
    },
    onError: (e: unknown) => setError(e instanceof ApiError ? `${e.code}: ${e.message}` : 'Export failed'),
  })

  const importPack = useMutation({
    mutationFn: async (file: File) => {
      const form = new FormData()
      form.append('file', file)
      return api<{ created: number }>(`/api/admin/projects/${projectId}/quizzes/import-pack`, {
        method: 'POST',
        body: form,
      })
    },
    onSuccess: (res) => {
      setError(null)
      invalidate()
      window.alert(`Imported ${res.created} quiz${res.created === 1 ? '' : 'zes'}.`)
    },
    onError: (e: unknown) => setError(e instanceof ApiError ? `${e.code}: ${e.message}` : 'Import failed'),
  })

  if (!projectId) return <p>Select an active project first.</p>
  if (isLoading || !data) return <p>Loading…</p>

  const locked = !isContentEditable(activeProject?.state)
  const selectedIds = [...selected]
  const allSelected = items.length > 0 && selected.size === items.length

  const toggle = (id: string) => {
    setSelected((prev) => {
      const next = new Set(prev)
      if (next.has(id)) next.delete(id)
      else next.add(id)
      return next
    })
  }

  const toggleAll = () => {
    if (allSelected) setSelected(new Set())
    else setSelected(new Set(items.map((q) => q.id)))
  }

  const onDragEnd = (event: DragEndEvent) => {
    if (locked) return
    const { active, over } = event
    if (!over || active.id === over.id) return
    const oldIndex = items.findIndex((q) => q.id === active.id)
    const newIndex = items.findIndex((q) => q.id === over.id)
    if (oldIndex < 0 || newIndex < 0) return
    const next = arrayMove(items, oldIndex, newIndex)
    setItems(next)
    reorder.mutate(next.map((q) => q.id))
  }

  const confirmDelete = () => {
    const n = selectedIds.length
    if (n === 0 || locked) return
    if (!window.confirm(`Delete ${n} quiz${n === 1 ? '' : 'zes'}? This cannot be undone.`)) return
    batchDelete.mutate(selectedIds)
  }

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap justify-between items-start gap-3">
        <div>
          <h1 className="font-display text-2xl font-bold">Content</h1>
          <p className="text-sm text-[var(--color-muted)] mt-1">
            Project:{' '}
            <span className="font-semibold text-[var(--color-ink)]">
              {activeProject?.title ?? '…'}
            </span>
            {activeProject?.state && (
              <span className="pill pill-neutral ml-2">{activeProject.state}</span>
            )}
          </p>
        </div>
        <div className="flex flex-wrap gap-2">
          <a
            className="btn-secondary min-h-10 text-sm"
            href={`${import.meta.env.BASE_URL}examples/quizzes-import.json`}
            download="quizzes-import.json"
          >
            JSON example
          </a>
          <button
            type="button"
            className="btn-secondary disabled:opacity-40"
            disabled={locked || importPack.isPending}
            onClick={() => importInputRef.current?.click()}
          >
            <Upload size={16} aria-hidden />
            Import JSON/ZIP
          </button>
          <input
            ref={importInputRef}
            type="file"
            accept=".json,.zip,application/json,application/zip"
            className="hidden"
            onChange={(e) => {
              const file = e.target.files?.[0]
              e.target.value = ''
              if (file) importPack.mutate(file)
            }}
          />
          <button
            type="button"
            className="btn-primary disabled:opacity-40"
            disabled={locked || create.isPending}
            onClick={() => create.mutate()}
          >
            <Plus size={16} aria-hidden />
            Add quiz
          </button>
        </div>
      </div>

      {locked && (
        <p className="text-sm bg-[var(--color-accent-soft)] text-[var(--color-accent-strong)] px-3 py-2">
          Read-only while project is {activeProject?.state}. Switch to SETUP or TEST on the Projects page to edit quizzes.
        </p>
      )}

      {error && <p className="text-sm text-red-700">{error}</p>}

      {selectedIds.length > 0 && (
        <div className="flex flex-wrap items-center gap-2 border border-[var(--color-line)] bg-white p-3">
          <span className="text-sm font-semibold mr-2">{selectedIds.length} selected</span>
          <button
            type="button"
            className="btn-secondary min-h-9 text-sm"
            onClick={() => exportPack.mutate(selectedIds)}
            disabled={exportPack.isPending}
          >
            <Download size={14} aria-hidden />
            Export ZIP
          </button>
          {!locked && (
            <>
          <button
            type="button"
            className="btn-secondary min-h-9 text-sm"
            onClick={() => clone.mutate(selectedIds)}
            disabled={clone.isPending}
          >
            <Copy size={14} aria-hidden />
            Clone here
          </button>
          <div className="flex flex-wrap items-center gap-2">
            <select
              className="input-field w-auto min-h-9 text-sm"
              value={copyTarget}
              onChange={(e) => setCopyTarget(e.target.value)}
            >
              <option value="">Copy to project…</option>
              {otherProjects.map((p) => (
                <option key={p.id} value={p.id}>
                  {p.title} ({p.state})
                </option>
              ))}
            </select>
            <button
              type="button"
              className="btn-secondary min-h-9 text-sm"
              disabled={!copyTarget || copy.isPending}
              onClick={() => copy.mutate({ quizIds: selectedIds, targetProjectId: copyTarget })}
            >
              Copy
            </button>
          </div>
          <button
            type="button"
            className="btn-secondary min-h-9 text-sm text-red-700 border-red-300"
            onClick={confirmDelete}
            disabled={batchDelete.isPending}
          >
            <Trash2 size={14} aria-hidden />
            Delete
          </button>
            </>
          )}
        </div>
      )}

      {items.length === 0 ? (
        <p className="text-[var(--color-muted)]">No quizzes yet.</p>
      ) : (
        <DndContext sensors={sensors} collisionDetection={closestCenter} onDragEnd={onDragEnd}>
          <SortableContext items={items.map((q) => q.id)} strategy={verticalListSortingStrategy}>
            <table className="admin-table">
              <thead>
                <tr>
                  <th className="w-10">
                    <input
                      type="checkbox"
                      checked={allSelected}
                      disabled={false}
                      onChange={toggleAll}
                      aria-label="Select all"
                    />
                  </th>
                  <th className="w-10">#</th>
                  <th className="w-10" />
                  <th>Title</th>
                  <th>Status</th>
                  <th className="w-28">Preview</th>
                </tr>
              </thead>
              <tbody>
                {items.map((q, idx) => (
                  <SortableQuizRow
                    key={q.id}
                    quiz={q}
                    index={idx}
                    selected={selected.has(q.id)}
                    onToggle={toggle}
                    onPreview={setPreviewQuiz}
                    locked={locked}
                  />
                ))}
              </tbody>
            </table>
          </SortableContext>
        </DndContext>
      )}

      {previewQuiz && (
        <QuizPreviewModal quiz={previewQuiz} onClose={() => setPreviewQuiz(null)} />
      )}
    </div>
  )
}

export function AdminQuizEditorPage() {
  const { quizId = '' } = useParams()
  const navigate = useNavigate()
  const qc = useQueryClient()
  const [error, setError] = useState<string | null>(null)
  const [opts, setOpts] = useState<Option[]>([])
  const [title, setTitle] = useState('')
  const [descriptionHtml, setDescriptionHtml] = useState('')
  const [explanationHtml, setExplanationHtml] = useState('')
  const [shuffleOptions, setShuffleOptions] = useState(true)
  const [saveStatus, setSaveStatus] = useState<'idle' | 'saving' | 'saved' | 'error'>('idle')
  const [hydrated, setHydrated] = useState(false)
  const optsDirtyRef = useRef(false)
  const fieldsDirtyRef = useRef(false)
  const titleInputRef = useRef<HTMLInputElement>(null)
  const baselineRef = useRef<{
    title: string
    description_html: string
    explanation_html: string
  } | null>(null)
  const htmlSaveTimer = useRef<number | null>(null)
  const optsSaveTimer = useRef<number | null>(null)
  // Keep latest values for flush-on-leave (debounce otherwise drops edits).
  const latestRef = useRef({
    title: '',
    descriptionHtml: '',
    explanationHtml: '',
    opts: [] as Option[],
    shuffleOptions: true,
    hydrated: false,
    projectState: 'SETUP' as string | undefined,
  })

  const { data, isLoading, isFetching, isSuccess } = useQuery({
    queryKey: ['admin-quiz', quizId],
    queryFn: () =>
      api<{ quiz: Quiz & { options: Option[]; projectId: string; projectState?: string } }>(
        `/api/admin/quizzes/${quizId}`,
      ),
    enabled: !!quizId,
    staleTime: 0,
    refetchOnMount: 'always',
    refetchOnWindowFocus: false,
  })

  latestRef.current = {
    title,
    descriptionHtml,
    explanationHtml,
    opts,
    shuffleOptions,
    hydrated,
    projectState: data?.quiz?.projectState,
  }

  const applyQuizToForm = (q: Quiz & { options?: Option[] }) => {
    setOpts(q.options ?? [])
    setTitle(q.title)
    setDescriptionHtml(q.description_html)
    setExplanationHtml(q.explanation_html)
    setShuffleOptions(!!q.shuffle_options)
    baselineRef.current = {
      title: q.title,
      description_html: q.description_html,
      explanation_html: q.explanation_html,
    }
    optsDirtyRef.current = false
    fieldsDirtyRef.current = false
  }

  // Reset when route quiz changes.
  useEffect(() => {
    setHydrated(false)
    optsDirtyRef.current = false
    fieldsDirtyRef.current = false
    baselineRef.current = null
    setOpts([])
    setTitle('')
    setDescriptionHtml('')
    setExplanationHtml('')
    setShuffleOptions(true)
    setError(null)
    setSaveStatus('idle')
    if (htmlSaveTimer.current) {
      window.clearTimeout(htmlSaveTimer.current)
      htmlSaveTimer.current = null
    }
    if (optsSaveTimer.current) {
      window.clearTimeout(optsSaveTimer.current)
      optsSaveTimer.current = null
    }
  }, [quizId])

  // Hydrate only from a settled fetch — never from a stale empty create cache alone.
  // Re-apply server data after refetch when the form is not dirty.
  useEffect(() => {
    if (!data?.quiz || data.quiz.id !== quizId || !isSuccess) return
    // On first open, wait until the mandatory refetch finishes so we don't lock in empty cache.
    if (!hydrated && isFetching) return
    if (hydrated && (fieldsDirtyRef.current || optsDirtyRef.current)) return

    applyQuizToForm(data.quiz)
    if (!hydrated) {
      setHydrated(true)
      requestAnimationFrame(() => titleInputRef.current?.focus())
    }
  }, [data, quizId, isSuccess, isFetching, hydrated])

  const patchQuizCache = (body: Partial<Quiz>) => {
    qc.setQueryData<{ quiz: Quiz & { options: Option[]; projectId: string; projectState?: string } }>(
      ['admin-quiz', quizId],
      (old) => {
        if (!old?.quiz) return old
        return { quiz: { ...old.quiz, ...body } }
      },
    )
  }

  const saveQuiz = useMutation({
    mutationFn: (body: Partial<Quiz>) => api(`/api/admin/quizzes/${quizId}`, { method: 'PATCH', json: body }),
    onSuccess: (_res, body) => {
      if (baselineRef.current) {
        baselineRef.current = {
          ...baselineRef.current,
          ...(body.title !== undefined ? { title: String(body.title) } : {}),
          ...(body.description_html !== undefined
            ? { description_html: String(body.description_html) }
            : {}),
          ...(body.explanation_html !== undefined
            ? { explanation_html: String(body.explanation_html) }
            : {}),
        }
      }
      patchQuizCache(body)
      void qc.invalidateQueries({ queryKey: ['admin-quizzes'] })
      void qc.invalidateQueries({ queryKey: ['admin-quiz', quizId] })
    },
    onError: (e: unknown) => setError(e instanceof ApiError ? `${e.code}: ${e.message}` : 'Save failed'),
  })

  const persistQuizFields = (body: Partial<Quiz>, opts?: { force?: boolean }) => {
    const force = opts?.force ?? false
    if (!latestRef.current.hydrated && !force) return
    if (!baselineRef.current && !force) return
    if (latestRef.current.projectState && !isContentEditable(latestRef.current.projectState)) return

    if (body.title !== undefined) {
      const next = String(body.title).trim()
      if (!next) return
      if (baselineRef.current && next === baselineRef.current.title) {
        const { title: _, ...rest } = body
        body = rest
      } else {
        body = { ...body, title: next }
      }
    }
    if (
      body.description_html !== undefined &&
      baselineRef.current &&
      body.description_html === baselineRef.current.description_html
    ) {
      const { description_html: _, ...rest } = body
      body = rest
    }
    if (
      body.explanation_html !== undefined &&
      baselineRef.current &&
      body.explanation_html === baselineRef.current.explanation_html
    ) {
      const { explanation_html: _, ...rest } = body
      body = rest
    }
    if (Object.keys(body).length === 0) return

    fieldsDirtyRef.current = true
    setSaveStatus('saving')
    saveQuiz.mutate(body, {
      onSuccess: () => {
        fieldsDirtyRef.current = false
        setSaveStatus('saved')
      },
      onError: () => setSaveStatus('error'),
    })
  }

  const persistOptionsNow = (options: Option[]) => {
    if (latestRef.current.projectState && !isContentEditable(latestRef.current.projectState)) return
    if (options.length === 0) return
    setSaveStatus('saving')
    return api(`/api/admin/quizzes/${quizId}/options`, {
      method: 'PUT',
      json: {
        options: options.map((o) => ({
          id: o.id,
          label_html: o.label_html,
          feedback_html: o.feedback_html,
          is_correct: !!o.is_correct,
        })),
      },
    })
      .then(() => {
        optsDirtyRef.current = false
        setSaveStatus('saved')
        qc.setQueryData<{ quiz: Quiz & { options: Option[] } }>(['admin-quiz', quizId], (old) => {
          if (!old?.quiz) return old
          return { quiz: { ...old.quiz, options } }
        })
        void qc.invalidateQueries({ queryKey: ['admin-quizzes'] })
      })
      .catch((e: unknown) => {
        setSaveStatus('error')
        setError(e instanceof ApiError ? `${e.code}: ${e.message}` : 'Save failed')
      })
  }

  const flushPendingSaves = () => {
    const cur = latestRef.current
    if (!cur.hydrated) return
    if (htmlSaveTimer.current) {
      window.clearTimeout(htmlSaveTimer.current)
      htmlSaveTimer.current = null
    }
    if (optsSaveTimer.current) {
      window.clearTimeout(optsSaveTimer.current)
      optsSaveTimer.current = null
    }
    persistQuizFields(
      {
        title: cur.title,
        description_html: cur.descriptionHtml,
        explanation_html: cur.explanationHtml,
        shuffle_options: cur.shuffleOptions ? 1 : 0,
      },
      { force: true },
    )
    if (optsDirtyRef.current) {
      void persistOptionsNow(cur.opts)
    }
  }

  // Flush if the tab is hidden / page unloaded.
  useEffect(() => {
    const onHide = () => {
      if (document.visibilityState === 'hidden') flushPendingSaves()
    }
    window.addEventListener('pagehide', flushPendingSaves)
    document.addEventListener('visibilitychange', onHide)
    return () => {
      flushPendingSaves()
      window.removeEventListener('pagehide', flushPendingSaves)
      document.removeEventListener('visibilitychange', onHide)
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [quizId])

  const updateOpts = (updater: (prev: Option[]) => Option[]) => {
    if (!hydrated) return
    if (data?.quiz.projectState && !isContentEditable(data.quiz.projectState)) return
    optsDirtyRef.current = true
    setOpts(updater)
  }

  const commitOpts = (updater: (prev: Option[]) => Option[]) => {
    if (!hydrated) return
    if (data?.quiz.projectState && !isContentEditable(data.quiz.projectState)) return
    setOpts((prev) => {
      const next = updater(prev)
      optsDirtyRef.current = true
      void persistOptionsNow(next)
      return next
    })
  }

  const remove = useMutation({
    mutationFn: () => api(`/api/admin/quizzes/${quizId}`, { method: 'DELETE' }),
    onSuccess: () => {
      void qc.removeQueries({ queryKey: ['admin-quiz', quizId] })
      navigate('/admin/content')
    },
  })

  const createNext = useMutation({
    mutationFn: () => {
      const projectId = data?.quiz.projectId
      if (!projectId) throw new Error('Missing project')
      return api<{ quiz: Quiz & { options?: Option[]; projectId?: string } }>(
        `/api/admin/projects/${projectId}/quizzes`,
        { method: 'POST', json: { title: 'New quiz' } },
      )
    },
    onSuccess: (res) => {
      flushPendingSaves()
      const created = {
        ...res.quiz,
        projectId: data?.quiz.projectId,
        projectState: data?.quiz.projectState,
        options: res.quiz.options ?? [],
      }
      qc.setQueryData(['admin-quiz', res.quiz.id], { quiz: created })
      void qc.invalidateQueries({ queryKey: ['admin-quizzes'] })
      navigate(`/admin/content/${res.quiz.id}`)
    },
    onError: (e: unknown) => setError(e instanceof ApiError ? `${e.code}: ${e.message}` : 'Create failed'),
  })

  const ready = hydrated && data?.quiz?.id === quizId && !isLoading
  if (!ready) {
    return <p className="p-4 text-[var(--color-muted)]">Loading…</p>
  }

  const quiz = data.quiz
  const locked = !isContentEditable(quiz.projectState)

  return (
    <div className="space-y-6 max-w-6xl" key={quizId}>
      <div className="flex flex-wrap items-center justify-between gap-3">
        <Link
          to="/admin/content"
          className="text-sm text-[var(--color-accent)] inline-flex items-center gap-1.5 hover:text-[var(--color-accent-strong)]"
          onClick={() => flushPendingSaves()}
        >
          <ArrowLeft size={14} aria-hidden />
          Content
        </Link>
        <p className="text-xs text-[var(--color-muted)]">
          {locked ? 'Read-only' : null}
          {!locked && saveStatus === 'saving' && 'Saving…'}
          {!locked && saveStatus === 'saved' && 'Saved'}
          {!locked && saveStatus === 'error' && 'Save failed'}
        </p>
      </div>
      <h1 className="font-display text-2xl font-bold">{locked ? 'View quiz' : 'Edit quiz'}</h1>
      {locked && (
        <p className="text-sm bg-[var(--color-accent-soft)] text-[var(--color-accent-strong)] px-3 py-2">
          Read-only while project is {quiz.projectState}. Switch to SETUP or TEST to edit.
        </p>
      )}
      {error && <p className="text-red-700 text-sm">{error}</p>}

      <label className="block space-y-1 max-w-xl">
        <span className="text-sm font-semibold">Title</span>
        <input
          ref={titleInputRef}
          className="input-field"
          value={title}
          disabled={locked}
          readOnly={locked}
          onChange={(e) => {
            fieldsDirtyRef.current = true
            setTitle(e.target.value)
          }}
          onBlur={(e) => {
            if (!locked) persistQuizFields({ title: e.target.value })
          }}
        />
      </label>

      <label className="flex items-center gap-2 min-h-12">
        <input
          type="checkbox"
          disabled={locked}
          checked={shuffleOptions}
          onChange={(e) => {
            const next = e.target.checked
            setShuffleOptions(next)
            if (!locked) persistQuizFields({ shuffle_options: next ? 1 : 0 })
          }}
        />
        <span className="text-sm">Shuffle options for participants</span>
      </label>

      <EditorWithPreview
        label="Description / prompt"
        value={descriptionHtml}
        projectId={data?.quiz?.projectId}
        editable={!locked}
        onChange={(html) => {
          fieldsDirtyRef.current = true
          setDescriptionHtml(html)
        }}
        onBlurSave={(html) => {
          setDescriptionHtml(html)
          if (!locked) persistQuizFields({ description_html: html })
        }}
      />

      <EditorWithPreview
        label="Explanation (after wrap-up)"
        value={explanationHtml}
        projectId={data?.quiz?.projectId}
        editable={!locked}
        onChange={(html) => {
          fieldsDirtyRef.current = true
          setExplanationHtml(html)
        }}
        onBlurSave={(html) => {
          setExplanationHtml(html)
          if (!locked) persistQuizFields({ explanation_html: html })
        }}
      />

      <hr className="rule" />

      <div className="space-y-4">
        <h2 className="font-semibold text-lg">Options (exactly one correct)</h2>
        {opts.map((o, i) => (
          <div key={o.id} className="border border-[var(--color-line)] p-3 space-y-4">
            <div className="flex items-center gap-2">
              <input
                type="radio"
                name="correct"
                disabled={locked}
                checked={!!o.is_correct}
                onChange={() => {
                  if (!locked) {
                    commitOpts((prev) => prev.map((x, j) => ({ ...x, is_correct: j === i ? 1 : 0 })))
                  }
                }}
              />
              <span className="text-sm font-semibold">Option {i + 1} — mark correct</span>
            </div>
            <EditorWithPreview
              label="Label"
              value={o.label_html}
              projectId={data?.quiz?.projectId}
              editable={!locked}
              onChange={(html) => {
                if (!locked) {
                  updateOpts((prev) => prev.map((x, j) => (j === i ? { ...x, label_html: html } : x)))
                }
              }}
              onBlurSave={(html) => {
                if (!locked) {
                  commitOpts((prev) => prev.map((x, j) => (j === i ? { ...x, label_html: html } : x)))
                }
              }}
            />
            <OptionalFeedbackEditor
              value={o.feedback_html}
              projectId={data?.quiz?.projectId}
              editable={!locked}
              onChange={(html) => {
                if (!locked) {
                  updateOpts((prev) => prev.map((x, j) => (j === i ? { ...x, feedback_html: html } : x)))
                }
              }}
              onBlurSave={(html) => {
                if (!locked) {
                  commitOpts((prev) => prev.map((x, j) => (j === i ? { ...x, feedback_html: html } : x)))
                }
              }}
            />
          </div>
        ))}
      </div>

      <div className="flex flex-wrap items-center gap-3 pt-2">
        <button
          type="button"
          className="btn-primary disabled:opacity-40"
          disabled={locked || createNext.isPending}
          onClick={() => {
            flushPendingSaves()
            createNext.mutate()
          }}
        >
          <Plus size={16} aria-hidden />
          Add new quiz
        </button>
        {!locked && (
          <button
            type="button"
            className="text-red-700 text-sm underline min-h-10"
            onClick={() => {
              if (window.confirm(`Delete "${quiz.title}"? This cannot be undone.`)) remove.mutate()
            }}
          >
            Delete quiz
          </button>
        )}
      </div>
    </div>
  )
}


