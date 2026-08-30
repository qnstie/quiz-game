import { useEffect } from 'react'
import { Link, Outlet, useLocation, useNavigate } from 'react-router-dom'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { api, PARTICIPANT_UNAUTHORIZED_EVENT } from '../api/client'
import { LanguageToggle } from './LanguageToggle'
import { InstallBanner } from './InstallBanner'
import { isParticipantLive } from '../lib/projectState'

export type Bootstrap = {
  project: null | {
    id: string
    slug: string
    title: string
    description_html: string
    state: 'SETUP' | 'TEST' | 'ACTIVE' | 'CLOSED' | 'REVEALED'
    require_pin: boolean
    shuffle_quizzes: boolean
  }
  projects?: { id: string; slug: string; title: string; state: string }[]
  session: null | { displayName: string; userId: string; answersResetAt?: string | null }
}

export function useBootstrap(pollMs?: number) {
  return useQuery({
    queryKey: ['bootstrap'],
    queryFn: () => api<Bootstrap>('/api/bootstrap'),
    refetchInterval: pollMs,
    refetchOnMount: 'always',
    refetchOnWindowFocus: 'always',
    staleTime: 0,
  })
}

function goToLanding(navigate: ReturnType<typeof useNavigate>, qc: ReturnType<typeof useQueryClient>) {
  void qc.removeQueries({ queryKey: ['quizzes'] })
  void qc.removeQueries({ queryKey: ['quiz'] })
  void qc.invalidateQueries({ queryKey: ['bootstrap'] })
  navigate('/', { replace: true })
}

export function ParticipantShell() {
  const navigate = useNavigate()
  const location = useLocation()
  const qc = useQueryClient()
  // Poll faster while waiting for a live project so activation shows up without a PWA restart.
  const { data } = useBootstrap(3_000)
  const path = location.pathname

  useEffect(() => {
    const onUnauthorized = () => goToLanding(navigate, qc)
    window.addEventListener(PARTICIPANT_UNAUTHORIZED_EVENT, onUnauthorized)
    return () => window.removeEventListener(PARTICIPANT_UNAUTHORIZED_EVENT, onUnauthorized)
  }, [navigate, qc])

  useEffect(() => {
    const token = localStorage.getItem('fq_user_token')
    if (!data) return

    // Kicked / deleted participant: server no longer recognizes the token.
    if (!data.session && token) {
      localStorage.removeItem('fq_user_token')
      goToLanding(navigate, qc)
      return
    }

    // Progress reset: drop cached quiz state and send them back to the quiz list.
    if (data.session?.answersResetAt) {
      const key = `fq_reset_seen:${data.session.userId}`
      const seen = localStorage.getItem(key)
      if (seen !== data.session.answersResetAt) {
        localStorage.setItem(key, data.session.answersResetAt)
        void qc.invalidateQueries({ queryKey: ['quizzes'] })
        void qc.invalidateQueries({ queryKey: ['quiz'] })
        if (path.startsWith('/q/') || path.startsWith('/quizzes')) {
          navigate('/quizzes', { replace: true })
        }
      }
    }
  }, [data, navigate, path, qc])

  useEffect(() => {
    if (!data?.project) return
    const state = data.project.state
    if (state === 'SETUP' && path !== '/blocked') navigate('/blocked', { replace: true })
    if (state === 'CLOSED' && !path.startsWith('/closed') && path !== '/blocked') {
      navigate('/closed', { replace: true })
    }
    if (state === 'REVEALED' && (path === '/closed' || path === '/blocked')) {
      navigate('/results', { replace: true })
    }
    if (isParticipantLive(state) && (path === '/blocked' || path === '/closed')) {
      navigate(data.session ? '/quizzes' : '/', { replace: true })
    }
  }, [data, navigate, path])

  return (
    <div className="min-h-dvh safe-pad flex flex-col">
      <header className="flex items-center justify-between py-3 max-w-2xl mx-auto w-full">
        <span className="font-display text-lg font-bold tracking-tight">
          {data?.project?.title ?? 'Family Quiz'}
          {data?.project?.state && data.project.state !== 'ACTIVE' && (
            <span className="ml-2 align-middle text-xs font-sans font-semibold tracking-wide text-[var(--color-muted)]">
              ({data.project.state})
            </span>
          )}
        </span>
        <LanguageToggle />
      </header>
      <main className="max-w-2xl mx-auto pb-8 w-full flex-1">
        <Outlet context={{ bootstrap: data }} />
      </main>
      <footer className="max-w-2xl mx-auto w-full py-4 text-center">
        <Link
          to="/admin/login"
          className="text-xs text-[var(--color-muted)] hover:text-[var(--color-ink)] underline-offset-2 hover:underline"
        >
          Admin
        </Link>
      </footer>
      <InstallBanner />
    </div>
  )
}
