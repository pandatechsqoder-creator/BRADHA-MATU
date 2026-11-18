import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { WorkerAssignmentOperationsPage } from '../features/operations/WorkerAssignmentOperationsPage'

vi.mock('@tanstack/react-router', () => ({
  Link: ({ children, ...props }: { children: React.ReactNode; to?: string }) => (
    <a href={typeof props.to === 'string' ? props.to : '#'}>{children}</a>
  ),
  useParams: () => ({ assignmentId: 'asg-1' }),
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
      <WorkerAssignmentOperationsPage />
    </QueryClientProvider>,
  )
}

describe('WorkerAssignmentOperationsPage', () => {
  beforeEach(() => {
    localStorage.clear()
    localStorage.setItem('brathamatu.auth.token', '1|test')
    vi.restoreAllMocks()
  })

  it('blocks check-in until the SOP is acknowledged and required checklist items are answered', async () => {
    let acknowledged = false
    let checkedIn = false
    const answered = new Set<string>()

    const payload = () => {
      const checklistComplete = answered.size >= 2
      const percent = 10 + (acknowledged ? 15 : 0) + (checklistComplete ? 25 : 0) + (checkedIn ? 50 : 0)
      const stage = !acknowledged
        ? 'awaiting_acknowledgement'
        : !checklistComplete
          ? 'awaiting_checklist'
          : checkedIn
            ? 'counting'
            : 'awaiting_check_in'
      const blockers = [
        ...(acknowledged ? [] : ['Acknowledge the job SOP.']),
        ...(checklistComplete ? [] : [`Complete ${2 - answered.size} required checklist item(s).`]),
        ...(checkedIn ? [] : ['Check in at the job site.']),
      ]

      return {
        assignment: {
          id: 'asg-1',
          status: 'accepted',
          role: 'counter',
          compensation: { amount: 700, currency: 'KES' },
          job: {
            id: 'job-1',
            reference: 'STJ-OPS-1',
            title: 'Quarter close count',
            status: checkedIn ? 'in_progress' : 'scheduled',
            status_label: checkedIn ? 'In progress' : 'Scheduled',
            priority: 'normal',
            count_method: 'quantity',
            scope_label: 'Nairobi Main Store',
            preferred_start_at: null,
            deadline_at: null,
            tenant: 'Acme Retail',
            required_team_size: 1,
          },
        },
        procedure: {
          id: 'proc-1',
          version: 1,
          title: 'Standard retail stock count SOP',
          summary: 'Follow the on-site control steps.',
          require_acknowledgement: true,
          assigned_at: '2026-11-11T08:00:00Z',
          acknowledged,
          acknowledged_at: acknowledged ? '2026-11-11T09:00:00Z' : null,
          steps: [
            { position: 1, title: 'Report to the manager', instructions: 'Sign the visitor book.', required: true, evidence_required: false },
            { position: 2, title: 'Count physical quantities only', instructions: null, required: true, evidence_required: false },
          ],
        },
        checklists: [
          {
            id: 'chk-1',
            name: 'Pre-count readiness',
            kind: 'pre_start',
            required: true,
            status: checklistComplete ? 'completed' : 'pending',
            items: [
              { id: 'item-1', label: 'Manager present', instructions: null, response_type: 'boolean', required: true, options: [], response: answered.has('item-1') ? { value: 'yes', notes: null, responded_at: '2026-11-11T09:05:00Z' } : null },
              { id: 'item-2', label: 'Zones allocated', instructions: null, response_type: 'boolean', required: true, options: [], response: answered.has('item-2') ? { value: 'yes', notes: null, responded_at: '2026-11-11T09:06:00Z' } : null },
            ],
          },
        ],
        checkin: checkedIn
          ? { id: 'cin-1', site_label: 'Nairobi Main Store', latitude: null, longitude: null, method: 'manual', checked_in_at: '2026-11-11T09:10:00Z' }
          : null,
        checkout: null,
        attendance: checkedIn
          ? { id: 'att-1', status: 'present', scheduled_start_at: null, checked_in_at: '2026-11-11T09:10:00Z', checked_out_at: null, minutes_on_site: null }
          : null,
        readiness: {
          accepted: true,
          has_procedure: true,
          sop_required: true,
          sop_acknowledged: acknowledged,
          checklist_required: 2,
          checklist_answered: answered.size,
          checklist_complete: checklistComplete,
          checked_in: checkedIn,
          count_in_progress: checkedIn,
          can_check_in: acknowledged && checklistComplete && !checkedIn,
          can_start_count: acknowledged && checklistComplete && checkedIn,
          percent,
          stage,
          blockers,
        },
        progress: {
          events: checkedIn
            ? [{ stage: 'count_started', percent: 100, message: 'Count started after check-in', occurred_at: '2026-11-11T09:10:00Z' }]
            : [],
          latest_percent: checkedIn ? 100 : percent,
        },
      }
    }

    const fetchMock = vi.spyOn(globalThis, 'fetch').mockImplementation(async (input, init) => {
      const url = String(input)
      const method = init?.method ?? 'GET'

      if (url.endsWith('/workforce/assignments/asg-1/operations') && method === 'GET') {
        return jsonResponse({ data: payload() })
      }
      if (url.endsWith('/workforce/assignments/asg-1/procedure/acknowledge') && method === 'POST') {
        acknowledged = true
        return jsonResponse({ data: payload() })
      }
      if (url.includes('/workforce/assignments/asg-1/checklists/') && method === 'PUT') {
        answered.add(url.split('/').pop() as string)
        return jsonResponse({ data: payload() })
      }
      if (url.endsWith('/workforce/assignments/asg-1/check-in') && method === 'POST') {
        checkedIn = true
        return jsonResponse({ data: payload() })
      }
      throw new Error(`Unexpected request: ${method} ${url}`)
    })

    renderPage()

    expect(await screen.findByText('Standard retail stock count SOP')).toBeInTheDocument()
    expect(screen.getAllByText('SOP acknowledgement pending').length).toBeGreaterThan(0)
    expect(screen.getByText('Acknowledge the job SOP.')).toBeInTheDocument()

    const checkInButton = screen.getByRole('button', { name: 'Check in on site' })
    expect(checkInButton).toBeDisabled()

    await userEvent.click(screen.getByRole('button', { name: 'Acknowledge SOP' }))

    await waitFor(() => expect(screen.getAllByText('Checklist pending').length).toBeGreaterThan(0))
    expect(screen.getByRole('button', { name: 'Check in on site' })).toBeDisabled()

    const yesButtons = screen.getAllByRole('button', { name: 'Yes' })
    await userEvent.click(yesButtons[0])
    await waitFor(() => expect(screen.getAllByText('Required checklist (1/2)').length).toBeGreaterThan(0))
    expect(screen.getByRole('button', { name: 'Check in on site' })).toBeDisabled()

    await userEvent.click(screen.getAllByRole('button', { name: 'Yes' })[1])
    await waitFor(() => expect(screen.getAllByText('Awaiting site check-in').length).toBeGreaterThan(0))
    expect(screen.getByRole('button', { name: 'Check in on site' })).toBeEnabled()

    await userEvent.click(screen.getByRole('button', { name: 'Check in on site' }))

    await waitFor(() => expect(screen.getAllByText('Counting in progress').length).toBeGreaterThan(0))
    expect(screen.getByText(/Count started after check-in/)).toBeInTheDocument()

    const calledUrls = fetchMock.mock.calls.map(([input, init]) => `${init?.method ?? 'GET'} ${String(input)}`)
    expect(calledUrls.some((entry) => entry.includes('/procedure/acknowledge'))).toBe(true)
    expect(calledUrls.filter((entry) => entry.includes('/checklists/')).length).toBe(2)
    expect(calledUrls.some((entry) => entry.endsWith('/check-in'))).toBe(true)
  })
})
