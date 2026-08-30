import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom'
import { ParticipantShell } from './components/ParticipantShell'
import { LandingPage } from './pages/participant/LandingPage'
import { QuizzesPage } from './pages/participant/QuizzesPage'
import { QuizRunnerPage } from './pages/participant/QuizRunnerPage'
import { BlockedPage, ClosedPage } from './pages/participant/StatusPages'
import { ResultsPage } from './pages/participant/ResultsPage'
import { AdminLoginPage, AdminShell } from './pages/admin/AdminShell'
import { AdminMagicEnterPage } from './pages/admin/AdminMagicEnterPage'
import { AdminProjectsPage } from './pages/admin/AdminProjectsPage'
import { AdminContentPage, AdminQuizEditorPage } from './pages/admin/AdminContentPage'
import { AdminLivePage } from './pages/admin/AdminLivePage'
import { AdminResultsPage } from './pages/admin/AdminResultsPage'
import { AdminPresentPage } from './pages/admin/AdminPresentPage'
import { AdminUsersPage } from './pages/admin/AdminUsersPage'

export function AppRouter() {
  const basename = import.meta.env.BASE_URL.replace(/\/$/, '') || undefined
  return (
    <BrowserRouter basename={basename}>
      <Routes>
        <Route element={<ParticipantShell />}>
          <Route path="/" element={<LandingPage />} />
          <Route path="/quizzes" element={<QuizzesPage />} />
          <Route path="/q/:quizId" element={<QuizRunnerPage />} />
          <Route path="/blocked" element={<BlockedPage />} />
          <Route path="/closed" element={<ClosedPage />} />
          <Route path="/results" element={<ResultsPage />} />
        </Route>

        <Route path="/admin/login" element={<AdminLoginPage />} />
        <Route path="/admin/enter" element={<AdminMagicEnterPage />} />
        <Route path="/admin" element={<AdminShell />}>
          <Route index element={<Navigate to="projects" replace />} />
          <Route path="projects" element={<AdminProjectsPage />} />
          <Route path="content" element={<AdminContentPage />} />
          <Route path="content/:quizId" element={<AdminQuizEditorPage />} />
          <Route path="live" element={<AdminLivePage />} />
          <Route path="results" element={<AdminResultsPage />} />
          <Route path="present" element={<AdminPresentPage />} />
          <Route path="users" element={<AdminUsersPage />} />
        </Route>

        <Route path="*" element={<NotFound />} />
      </Routes>
    </BrowserRouter>
  )
}

function NotFound() {
  return (
    <div className="min-h-dvh flex items-center justify-center p-8">
      <div className="text-center space-y-2">
        <h1 className="font-display text-3xl font-bold">404</h1>
        <p className="text-[var(--color-muted)]">Page not found</p>
        <a href="/" className="text-[var(--color-accent)] underline">
          Home
        </a>
      </div>
    </div>
  )
}
