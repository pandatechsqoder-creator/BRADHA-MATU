import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { FinanceWorkspacePage } from '../features/finance/FinanceWorkspacePage'

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
      <FinanceWorkspacePage />
    </QueryClientProvider>,
  )
}

describe('FinanceWorkspacePage', () => {
  beforeEach(() => {
    localStorage.clear()
    localStorage.setItem('brathamatu.auth.token', '1|test')
    vi.restoreAllMocks()
  })

  it('renders collections, ledger balance and recent payments for finance staff', async () => {
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockImplementation(async (input) => {
      const url = String(input)
      if (url.endsWith('/me')) {
        return jsonResponse({
          data: {
            id: 'u1',
            name: 'Finance Lead',
            email: 'finance@brathamatu.test',
            email_verified_at: '2026-11-01T00:00:00Z',
            created_at: '2026-11-01T00:00:00Z',
            roles: [{ slug: 'platform_finance', name: 'Finance', scope: 'platform', tenant_id: null }],
            permissions: ['finance.ledger.view', 'finance.payment.record', 'finance.refund.approve'],
            tenants: [],
            current_tenant: null,
          },
        })
      }
      if (url.includes('/platform/finance/overview')) {
        return jsonResponse({
          data: {
            collected_today: 3750,
            collected_this_month: 12500,
            by_method: [{ method: 'mpesa', payments: 2, total: 12500 }],
            awaiting_verification: 1,
            processing: 0,
            failed_payments: 0,
            awaiting_deposit_jobs: 1,
            awaiting_balance_jobs: 0,
            refunds_requested: 1,
            ledger: {
              accounts: [
                {
                  code: '1000',
                  name: 'M-Pesa clearing',
                  type: 'asset',
                  normal_balance: 'debit',
                  debit: 12500,
                  credit: 0,
                  balance: 12500,
                },
                {
                  code: '2000',
                  name: 'Tenant deposits',
                  type: 'liability',
                  normal_balance: 'credit',
                  debit: 0,
                  credit: 12500,
                  balance: -12500,
                },
              ],
              totals: { debit: 12500, credit: 12500 },
              balanced: true,
            },
            recent_payments: [
              {
                id: 'pay-1',
                purpose: 'deposit',
                amount: 3750,
                currency: 'KES',
                method: 'mpesa',
                provider: 'mpesa_sandbox',
                status: 'succeeded',
                internal_reference: 'PAY-DEP-001',
                provider_reference: 'MPESA-REF-001',
                checkout_reference: null,
                phone: '2547****123',
                sandbox: true,
                can_simulate: false,
                can_verify: false,
                created_at: '2026-11-11T08:00:00Z',
                job: { id: 'job-1', reference: 'STJ-20261111-ABC123', title: 'Quarterly stock take', status: 'submitted' },
                tenant: { id: 't1', name: 'Acme Retail' },
              },
            ],
            recent_reconciliations: [],
          },
        })
      }
      throw new Error(`Unexpected request: ${url}`)
    })

    renderPage()

    expect(await screen.findByRole('heading', { name: 'Finance' })).toBeInTheDocument()
    expect(await screen.findByText('Collected today')).toBeInTheDocument()
    expect(await screen.findByText('PAY-DEP-001')).toBeInTheDocument()
    expect(screen.getByText('Acme Retail')).toBeInTheDocument()
    expect((await screen.findAllByText('Ledger balanced')).length).toBeGreaterThan(0)
    expect(screen.getAllByText('Balanced').length).toBeGreaterThan(0)
    expect(screen.getByRole('tab', { name: 'Ledger' })).toBeInTheDocument()
    expect(fetchMock).toHaveBeenCalled()
  })

  it('blocks the workspace when the user has no finance permission', async () => {
    vi.spyOn(globalThis, 'fetch').mockImplementation(async (input) => {
      const url = String(input)
      if (url.endsWith('/me')) {
        return jsonResponse({
          data: {
            id: 'u2',
            name: 'Stocktaker',
            email: 'worker@brathamatu.test',
            email_verified_at: '2026-11-01T00:00:00Z',
            created_at: '2026-11-01T00:00:00Z',
            roles: [],
            permissions: [],
            tenants: [],
            current_tenant: null,
          },
        })
      }
      throw new Error(`Unexpected request: ${url}`)
    })

    renderPage()

    expect(await screen.findByText(/do not have permission to view the finance workspace/i)).toBeInTheDocument()
  })
})
