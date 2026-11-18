import { Component, type ReactNode } from 'react'
import { Alert, Button } from './ui'

export class ErrorBoundary extends Component<
  { children: ReactNode },
  { error: Error | null }
> {
  state = { error: null as Error | null }

  static getDerivedStateFromError(error: Error) {
    return { error }
  }

  render() {
    if (this.state.error) {
      return (
        <div className="mx-auto max-w-lg px-4 py-16">
          <Alert kind="error">
            <p className="font-semibold">Something went wrong.</p>
            <p className="mt-1">{this.state.error.message}</p>
          </Alert>
          <div className="mt-4">
            <Button variant="secondary" onClick={() => window.location.reload()}>
              Reload page
            </Button>
          </div>
        </div>
      )
    }
    return this.props.children
  }
}