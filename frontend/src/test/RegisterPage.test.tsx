import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { RegisterPage } from '../features/auth/RegisterPage'

vi.mock('@tanstack/react-router', async () => {
  const actual = await vi.importActual<typeof import('@tanstack/react-router')>('@tanstack/react-router')
  return {
    ...actual,
    Link: ({ children }: { children: React.ReactNode }) => <a href="/login">{children}</a>,
  }
})

function renderRegister() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })

  return render(
    <QueryClientProvider client={queryClient}>
      <RegisterPage />
    </QueryClientProvider>,
  )
}

function jsonResponse(data: unknown, status = 200) {
  return new Response(JSON.stringify({ data }), {
    status,
    headers: { 'Content-Type': 'application/json' },
  })
}

describe('RegisterPage', () => {
  beforeEach(() => {
    localStorage.clear()
    vi.restoreAllMocks()
  })

  it('registers a business, reserves its selected subdomain and verifies the owner email', async () => {
    const fetchMock = vi.spyOn(globalThis, 'fetch')
      .mockResolvedValueOnce(jsonResponse({
        suggestions: [{ slug: 'greenvalley', subdomain: 'greenvalley.communitygroup.co.ke', available: true }],
        base_domain: 'communitygroup.co.ke',
      }))
      .mockResolvedValueOnce(jsonResponse({
        attempt_uuid: '01J9Z000000000000000000000',
        slug: 'greenvalley',
        expires_at: '2099-01-01T00:00:00Z',
      }, 201))
      .mockResolvedValueOnce(jsonResponse({ completed: true, tenant_uuid: '01J9Z000000000000000000001' }))

    renderRegister()
    await userEvent.type(screen.getByLabelText('Business name'), 'Green Valley Supermarket')
    await userEvent.type(screen.getByLabelText('Owner’s full name'), 'Jane Mwangi')
    await userEvent.type(screen.getByLabelText('Owner’s email'), 'jane@example.com')
    await userEvent.type(screen.getByLabelText('Password'), 'ValidPass1!')
    await userEvent.type(screen.getByLabelText('Confirm password'), 'ValidPass1!')
    await userEvent.click(screen.getByRole('button', { name: /continue to business link/i }))

    await screen.findAllByText('greenvalley.communitygroup.co.ke')
    await userEvent.click(screen.getByRole('radio', { name: /greenvalley\.communitygroup\.co\.ke/i }))
    await userEvent.click(screen.getByRole('checkbox', { name: /i accept this business link/i }))
    await userEvent.click(screen.getByRole('button', { name: /reserve link and send verification code/i }))

    expect(await screen.findByRole('heading', { name: 'Verify your email' })).toBeInTheDocument()
    await userEvent.type(screen.getByLabelText('Verification code'), '123456')
    await userEvent.click(screen.getByRole('button', { name: /verify and create workspace/i }))

    expect(await screen.findByRole('heading', { name: 'Your workspace is ready' })).toBeInTheDocument()
    expect(screen.getByText('https://greenvalley.communitygroup.co.ke')).toBeInTheDocument()
    await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(3))

    const [, registrationRequest] = fetchMock.mock.calls[1]
    expect(JSON.parse(String(registrationRequest?.body))).toMatchObject({
      business_name: 'Green Valley Supermarket',
      owner_email: 'jane@example.com',
      slug: 'greenvalley',
      domain_accepted: true,
    })
    expect(registrationRequest?.headers).toMatchObject({ 'Idempotency-Key': expect.any(String) })
  }, 15_000)
})
