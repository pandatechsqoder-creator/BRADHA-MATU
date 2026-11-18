import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { describe, expect, it, vi, beforeEach } from 'vitest'
import { LoginPage } from '../features/auth/LoginPage'

vi.mock('@tanstack/react-router', async () => {
  const actual = await vi.importActual<typeof import('@tanstack/react-router')>('@tanstack/react-router')
  return {
    ...actual,
    Link: ({ children }: { children: React.ReactNode }) => <a href="/register">{children}</a>,
    useRouter: () => ({ navigate: vi.fn() }),
  }
})

function renderLogin() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })
  return render(
    <QueryClientProvider client={queryClient}>
      <LoginPage />
    </QueryClientProvider>,
  )
}

describe('LoginPage', () => {
  beforeEach(() => {
    localStorage.clear()
    vi.restoreAllMocks()
  })

  it('shows client-side validation errors for empty submit', async () => {
    renderLogin()
    await userEvent.click(screen.getByRole('button', { name: /sign in/i }))
    expect(await screen.findByText('Enter a valid email address.')).toBeInTheDocument()
    expect(screen.getByText('Password is required.')).toBeInTheDocument()
  })

  it('submits valid credentials to the API', async () => {
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue(
      new Response(
        JSON.stringify({
          data: {
            user: {
              id: '01J9Z000000000000000000000',
              name: 'Test User',
              email: 'test@example.co.ke',
              email_verified_at: null,
              created_at: '2026-11-11T00:00:00Z',
            },
            token: '1|abc',
          },
          meta: { request_id: 'req-1', timestamp: '2026-11-11T00:00:00Z' },
        }),
        { status: 200, headers: { 'Content-Type': 'application/json' } },
      ),
    )
    renderLogin()
    await userEvent.type(screen.getByLabelText('Email'), 'test@example.co.ke')
    await userEvent.type(screen.getByLabelText('Password'), 'password1')
    await userEvent.click(screen.getByRole('button', { name: /sign in/i }))
    await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1))
    const [url, init] = fetchMock.mock.calls[0]
    expect(url).toBe('/api/v1/auth/login')
    expect(JSON.parse(String(init?.body))).toMatchObject({ email: 'test@example.co.ke' })
    expect(localStorage.getItem('brathamatu.auth.token')).toBe('1|abc')
  })

  it('surfaces API validation errors', async () => {
    vi.spyOn(globalThis, 'fetch').mockResolvedValue(
      new Response(
        JSON.stringify({
          error: {
            code: 'VALIDATION_FAILED',
            message: 'The supplied data is invalid.',
            details: { email: ['These credentials do not match our records.'] },
            request_id: 'req-2',
          },
        }),
        { status: 422, headers: { 'Content-Type': 'application/json' } },
      ),
    )
    renderLogin()
    await userEvent.type(screen.getByLabelText('Email'), 'test@example.co.ke')
    await userEvent.type(screen.getByLabelText('Password'), 'password1')
    await userEvent.click(screen.getByRole('button', { name: /sign in/i }))
    expect(await screen.findByText('These credentials do not match our records.')).toBeInTheDocument()
  })
})