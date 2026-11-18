import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { AcceptInvitationPage } from '../features/invitations/AcceptInvitationPage'

vi.mock('@tanstack/react-router', async () => {
  const actual = await vi.importActual<typeof import('@tanstack/react-router')>('@tanstack/react-router')
  return {
    ...actual,
    useRouter: () => ({ navigate: vi.fn() }),
  }
})

function renderInvitationPage() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })

  return render(
    <QueryClientProvider client={queryClient}>
      <AcceptInvitationPage />
    </QueryClientProvider>,
  )
}

describe('AcceptInvitationPage', () => {
  beforeEach(() => {
    localStorage.clear()
    window.history.replaceState({}, '', '/invitations/accept?token=invite-token')
    vi.restoreAllMocks()
  })

  it('creates an account through public invitation activation', async () => {
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockImplementation(async (_input, init) => {
      if (init?.method === 'POST') {
        return new Response(
          JSON.stringify({
            data: {
              user: {
                id: '01J9Z000000000000000000000',
                name: 'New Member',
                email: 'new.member@example.test',
                email_verified_at: '2026-11-18T00:00:00Z',
                must_change_password: false,
                created_at: '2026-11-18T00:00:00Z',
                roles: [],
                permissions: [],
                tenants: [
                  {
                    id: 'tenant-1',
                    name: 'Acme Retail',
                    status: 'active',
                    operating_mode: 'standard',
                    roles: [],
                  },
                ],
                current_tenant: null,
              },
              token: '1|activation-token',
            },
            meta: { request_id: 'req-1', timestamp: '2026-11-18T00:00:00Z' },
          }),
          { status: 200, headers: { 'Content-Type': 'application/json' } },
        )
      }

      return new Response(
        JSON.stringify({
          data: {
            invitation_id: 'invitation-1',
            organisation: 'Acme Retail',
            email: 'new.member@example.test',
            role: 'Staff',
            branch: null,
            status: 'pending',
            expires_at: '2026-11-25T00:00:00Z',
          },
          meta: { request_id: 'req-2', timestamp: '2026-11-18T00:00:00Z' },
        }),
        { status: 200, headers: { 'Content-Type': 'application/json' } },
      )
    })

    renderInvitationPage()

    await userEvent.type(await screen.findByLabelText('Your name'), 'New Member')
    await userEvent.type(screen.getByLabelText('Create password'), 'StrongPass1!')
    await userEvent.type(screen.getByLabelText('Confirm password'), 'StrongPass1!')
    await userEvent.click(screen.getByRole('button', { name: 'Create account and accept' }))

    await waitFor(() => expect(localStorage.getItem('brathamatu.auth.token')).toBe('1|activation-token'))
    const activationCall = fetchMock.mock.calls.find(
      ([url, init]) =>
        String(url) === '/api/v1/public/invitations/invite-token/activate' && init?.method === 'POST',
    )
    expect(fetchMock.mock.calls.some(([url]) => String(url) === '/api/v1/public/invitations/invite-token')).toBe(true)
    expect(activationCall?.[0]).toBe('/api/v1/public/invitations/invite-token/activate')
    expect(JSON.parse(String(activationCall?.[1]?.body))).toEqual({
      name: 'New Member',
      password: 'StrongPass1!',
      password_confirmation: 'StrongPass1!',
    })
  })
})
