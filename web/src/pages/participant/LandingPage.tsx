import { type FormEvent, useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useTranslation } from 'react-i18next'
import { api, ApiError } from '../../api/client'
import { useBootstrap } from '../../components/ParticipantShell'
import { RichContent } from '../../components/RichContent'

export function LandingPage() {
  const { t } = useTranslation()
  const { data, isLoading } = useBootstrap()
  const navigate = useNavigate()
  const qc = useQueryClient()
  const [name, setName] = useState('')
  const [pin, setPin] = useState('')
  const [error, setError] = useState<string | null>(null)

  const join = useMutation({
    mutationFn: async () => {
      const res = await api<{ token: string; displayName: string }>('/api/session/join', {
        method: 'POST',
        json: { name, pin: pin || undefined },
      })
      localStorage.setItem('fq_user_token', res.token)
      return res
    },
    onSuccess: async () => {
      await qc.invalidateQueries({ queryKey: ['bootstrap'] })
      navigate('/quizzes')
    },
    onError: (e: unknown) => {
      if (e instanceof ApiError) setError(t(`errors.${e.code}`, { defaultValue: e.message }))
      else setError(t('common.error'))
    },
  })

  const leave = useMutation({
    mutationFn: async () => {
      await api('/api/session/leave', { method: 'POST' })
      localStorage.removeItem('fq_user_token')
    },
    onSuccess: () => qc.invalidateQueries({ queryKey: ['bootstrap'] }),
  })

  if (isLoading) return <p className="py-12 text-center text-[var(--color-muted)]">{t('common.loading')}</p>

  if (!data?.project) {
    return (
      <section className="py-10 space-y-6">
        <h1 className="font-display text-4xl font-bold">{t('landing.titleFallback')}</h1>
        {data?.projects && data.projects.length > 0 ? (
          <ul className="space-y-2">
            <p className="text-[var(--color-muted)]">{t('landing.pickProject')}</p>
            {data.projects.map((p) => (
              <li key={p.id} className="rounded-xl border border-[var(--color-line)] px-4 py-3">
                {p.title} <span className="text-sm text-[var(--color-muted)]">({p.state})</span>
              </li>
            ))}
          </ul>
        ) : (
          <p className="text-[var(--color-muted)]">{t('landing.noProject')}</p>
        )}
      </section>
    )
  }

  if (data.session) {
    return (
      <section className="py-10 space-y-6">
        <h1 className="font-display text-4xl font-bold leading-tight">{data.project.title}</h1>
        {data.project.description_html && <RichContent html={data.project.description_html} />}
        <button
          type="button"
          className="w-full min-h-14 rounded-2xl bg-[var(--color-accent)] text-white font-semibold text-lg"
          onClick={() => navigate('/quizzes')}
        >
          {t('landing.continueAs', { name: data.session.displayName })}
        </button>
        <button
          type="button"
          className="w-full min-h-12 text-[var(--color-muted)] underline"
          onClick={() => leave.mutate()}
        >
          {t('landing.notYou')}
        </button>
      </section>
    )
  }

  const onSubmit = (e: FormEvent) => {
    e.preventDefault()
    setError(null)
    join.mutate()
  }

  return (
    <section className="py-10 space-y-6">
      <h1 className="font-display text-4xl font-bold leading-tight">{data.project.title}</h1>
      {data.project.description_html && <RichContent html={data.project.description_html} />}
      <p className="text-[var(--color-muted)]">{t('landing.subtitle')}</p>
      <form onSubmit={onSubmit} className="space-y-4">
        <label className="block space-y-1">
          <span className="text-sm font-semibold">{t('landing.nameLabel')}</span>
          <input
            className="input-field text-lg min-h-14"
            value={name}
            onChange={(e) => setName(e.target.value)}
            placeholder={t('landing.namePlaceholder')}
            autoComplete="nickname"
            required
            minLength={2}
            maxLength={40}
          />
        </label>
        {data.project.require_pin && (
          <label className="block space-y-1">
            <span className="text-sm font-semibold">{t('landing.pinLabel')}</span>
            <input
              className="input-field text-lg min-h-14"
              value={pin}
              onChange={(e) => setPin(e.target.value)}
              inputMode="numeric"
              pattern="\d{4}"
              maxLength={4}
              required
            />
          </label>
        )}
        {error && <p className="text-red-700 text-sm">{error}</p>}
        <button
          type="submit"
          disabled={join.isPending}
          className="w-full min-h-14 rounded-2xl bg-[var(--color-accent)] text-white font-semibold text-lg disabled:opacity-60"
        >
          {t('landing.join')}
        </button>
      </form>
    </section>
  )
}
