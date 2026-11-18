import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { TenantStocktakePage } from '../features/stocktake/TenantStocktakePage'

vi.mock('@tanstack/react-router', () => ({
  Link: ({ children, ...props }: { children: React.ReactNode; to?: string }) => (
    <a href={typeof props.to === 'string' ? props.to : '#'}>{children}</a>
  ),
}))

function jsonResponse(body: unknown) {
  return new Response(JSON.stringify(body), {
    status: 200,
    headers: { 'Content-Type': 'application/json' },
  })
}

function renderPage() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
  })
  return render(
    <QueryClientProvider client={queryClient}>
      <TenantStocktakePage />
    </QueryClientProvider>,
  )
}

describe('TenantStocktakePage', () => {
  beforeEach(() => {
    localStorage.clear()
    localStorage.setItem('brathamatu.auth.token', '1|test')
    vi.restoreAllMocks()
  })

  it('renders request buckets and job rows with status and value', async () => {
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockImplementation(async (input) => {
      const url = String(input)
      if (url.endsWith('/me')) {
        return jsonResponse({
          data: {
            id: 'u1',
            name: 'Amina Owner',
            email: 'amina@example.co.ke',
            email_verified_at: '2026-11-01T00:00:00Z',
            created_at: '2026-11-01T00:00:00Z',
            roles: [],
            permissions: ['stocktake.request.create'],
            tenants: [{ id: 't1', name: 'Acme Retail', status: 'active', operating_mode: 'manual', roles: [] }],
            current_tenant: { id: 't1', name: 'Acme Retail', operating_mode: 'manual' },
          },
        })
      }
      if (url.includes('/tenants/t1/stocktake-jobs')) {
        return jsonResponse({
          data: [
            {
              id: 'job-1',
              reference: 'STJ-20261111-ABC123',
              title: 'Quarterly stock take',
              status: 'awaiting_quote_acceptance',
              status_label: 'Awaiting quote acceptance',
              job_type: 'full',
              priority: 'high',
              count_method: 'quantity',
              scope_label: 'Nairobi HQ',
              preferred_start_at: null,
              deadline_at: null,
              required_team_size: 3,
              currency: 'KES',
              provisional_total: 12000,
              quote_total: 12500,
              quote_status: 'issued',
              deposit_required: true,
              deposit_amount: 3750,
              deposit_paid: 3750,
              submitted_at: '2026-11-10T09:00:00Z',
              accepted_at: null,
              created_at: '2026-11-09T09:00:00Z',
              updated_at: '2026-11-10T09:00:00Z',
            },
          ],
          meta: {
            current_page: 1,
            per_page: 20,
            total: 1,
            last_page: 1,
            buckets: {
              drafts: 2,
              awaiting_deposit: 1,
              with_platform: 4,
              active: 0,
              closed: 3,
            },
          },
        })
      }
      throw new Error(`Unexpected request: ${url}`)
    })

    renderPage()

    expect(await screen.findByText('Quarterly stock take')).toBeInTheDocument()
    expect(screen.getByText('STJ-20261111-ABC123')).toBeInTheDocument()
    expect(screen.getAllByText('Awaiting quote acceptance').length).toBeGreaterThan(0)
    expect(screen.getByText('Nairobi HQ')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: /new stock-taking request/i })).toHaveAttribute(
      'href',
      '/business/stocktaking/new',
    )

    const drafts = screen.getByText('Drafts').closest('div')
    expect(drafts).toHaveTextContent('2')
    expect(fetchMock).toHaveBeenCalled()
  })
})
