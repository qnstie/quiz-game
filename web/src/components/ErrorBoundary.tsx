import { Component, type ErrorInfo, type ReactNode } from 'react'

type Props = { children: ReactNode }
type State = { error: Error | null }

export class ErrorBoundary extends Component<Props, State> {
  state: State = { error: null }

  static getDerivedStateFromError(error: Error): State {
    return { error }
  }

  componentDidCatch(error: Error, info: ErrorInfo) {
    console.error(error, info)
  }

  render() {
    if (this.state.error) {
      return (
        <div className="min-h-dvh flex items-center justify-center p-8">
          <div className="text-center space-y-3 max-w-md">
            <h1 className="font-display text-2xl font-bold">Something went wrong</h1>
            <p className="text-sm text-[var(--color-muted)]">{this.state.error.message}</p>
            <button
              type="button"
              className="min-h-11 px-4 rounded-xl bg-[var(--color-accent)] text-white"
              onClick={() => window.location.assign('/')}
            >
              Reload
            </button>
          </div>
        </div>
      )
    }
    return this.props.children
  }
}
