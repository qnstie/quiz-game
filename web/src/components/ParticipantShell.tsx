import { useEffect } from 'react'
import { Link, Outlet, useNavigate } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { api } from '../api/client'
import { LanguageToggle } from './LanguageToggle'
import { InstallBanner } from './InstallBanner'

export type Bootstrap = {
  project: null | {
    id: string
    slug: string
    title: string
    description_html: string
    state: 'SETUP' | 'ACTIVE' | 'CLOSED' | 'REVEALED'
    require_pin: boolean
    shuffle_quizzes: boolean
  }
  projects?: { id: string; slug: string; title: string; state: string }[]
  session: null | { displayName: string; userId: string }
}

export function useBootstrap(pollMs?: number) {
  return useQuery({
    queryKey: ['bootstrap'],
    queryFn: () => api<Bootstrap>('/api/bootstrap'),
    refetchInterval: pollMs,
  })
}

export function ParticipantShell() {
  const navigate = useNavigate()
  const { data } = useBootstrap(10_000)

  useEffect(() => {
    if (!data?.project) return
    const state = data.project.state
    const path = window.location.pathname
    if (state === 'SETUP' && path !== '/blocked') navigate('/blocked', { replace: true })
    if (state === 'CLOSED' && !path.startsWith('/closed') && path !== '/blocked') {
      navigate('/closed', { replace: true })
    }
    if (state === 'REVEALED' && (path === '/closed' || path === '/blocked')) {
      navigate('/results', { replace: true })
    }
    if (state === 'ACTIVE' && (path === '/blocked' || path === '/closed')) {
      navigate(data.session ? '/quizzes' : '/', { replace: true })
    }
  }, [data, navigate])

  return (
    <div className="min-h-dvh safe-pad flex flex-col">
      <header className="flex items-center justify-between py-3 max-w-2xl mx-auto w-full">
        <span className="font-display text-lg font-bold tracking-tight">
          {data?.project?.title ?? 'Family Quiz'}
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
