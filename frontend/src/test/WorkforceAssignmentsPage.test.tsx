import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { WorkforceAssignmentsPage } from '../features/workforce/WorkforceAssignmentsPage'

vi.mock('@tanstack/react-router', () => ({
  Link: ({ children, ...props }: { children: React.ReactNode; to?: string }) => (
    <a href={typeof props.to === 'string' ? props.to : '#'}>{children}</a>
  ),
}))

function jsonResponse(body: unknown, status = 200) {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  })
}

function renderPage() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })
  return render(
    <QueryClientProvider client={queryClient}>
      <WorkforceAssignmentsPage />
    </QueryClientProvider>,
  )
}

const assignment = {
  id: 'asg-1',
  status: 'offered',
  role: 'counter',
  compensation: { amount: 700, currency: 'KES' },
  offer_expires_at: '2026-11-13T10:00:00Z',
  offered_at: '2026-11-11T10:00:00Z',
  job: {
    id: 'job-1',
    reference: 'STJ-20261111-GSILMC',
    title: 'Quarterly stock take',
    status: 'awaiting_worker_acceptance',
    tenant: 'Demo Supermarket',
    preferred_start_at: '2026-11-15T08:00:00Z',
    required_team_size: 3,
    scope_label: 'Aisle 1 — Dry Goods',
  },
  events: [],
}

const profile = {
  id: 'profile-1',
  user: { id: 'user-1', name: 'Demo Worker', email: 'worker@brathamatu.test' },
  legal_name: 'Demo Worker',
  level: 'verified',
  level_label: 'Verified',
  status: 'active',
  verification_status: 'verified',
  training_completed: true,
  completed_jobs_count: 0,
  skills: [],
  service_areas: [],
  availability: [],
  trainings: [],
  verification_checks: [],
}

describe('WorkforceAssignmentsPage', () => {
  beforeEach(() => {
    localStorage.clear()
    localStorage.setItem('brathamatu.auth.token', '1|test')
    vi.restoreAllMocks()
  })

  it('shows only the worker disclosed compensation and accepts an eligible offer', async () => {
    let accepted = false
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockImplementation(async (input, init) => {
      const url = String(input)
      const method = init?.method ?? 'GET'
      if (url.endsWith('/me')) {
        return jsonResponse({
          data: {
            id: 'user-1',
            name: 'Demo Worker',
            email: 'worker@brathamatu.test',
            email_verified_at: '2026-11-01T00:00:00Z',
            created_at: '2026-11-01T00:00:00Z',
            roles: [{ slug: 'verified-stock-taker', name: 'Verified', scope: 'workforce', tenant_id: null }],
            permissions: ['workforce.profile.view', 'assignment.view', 'assignment.accept', 'earnings.view'],
            tenants: [],
            current_tenant: null,
          },
        })
      }
      if (url.endsWith('/workforce/profile')) {
        return jsonResponse({ data: { profile } })
      }
      if (url.endsWith('/workforce/assignments')) {
        return jsonResponse({ data: { data: accepted ? [{ ...assignment, status: 'accepted', earning: { reference: 'EARN-1', status: 'accrued', amount: 700, currency: 'KES' } }] : [assignment] } })
      }
      if (url.endsWith('/workforce/assignments/asg-1/accept') && method === 'POST') {
        accepted = true
        return jsonResponse({
          data: {
            ...assignment,
            status: 'accepted',
            earning: { reference: 'EARN-1', status: 'accrued', amount: 700, currency: 'KES' },
          },
        })
      }
      throw new Error(`Unexpected request: ${method} ${url}`)
    })

    renderPage()

    expect(await screen.findByText('Quarterly stock take')).toBeInTheDocument()
    expect(screen.getByText('Your compensation')).toBeInTheDocument()
    expect(screen.getByText(/700\.00/)).toBeInTheDocument()
    expect(screen.getByText(/Tenant charges and platform margins are never shown here/)).toBeInTheDocument()
    expect(screen.queryByText(/4,466/)).not.toBeInTheDocument()

    await userEvent.click(screen.getByRole('button', { name: 'Accept assignment' }))

    await waitFor(() => expect(screen.getByText(/Earning EARN-1/)).toBeInTheDocument())
    expect(fetchMock).toHaveBeenCalledWith(
      expect.stringContaining('/workforce/assignments/asg-1/accept'),
      expect.objectContaining({ method: 'POST' }),
    )
  })
})
