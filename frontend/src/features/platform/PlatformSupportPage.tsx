import { useEffect, useState } from 'react'
import { Alert, Badge, Button, Card, EmptyState, Field, PageHeader, Select } from '../../components/ui'
import { ApiError } from '../../lib/api/client'
import { formatDateTime } from '../../lib/formatting'
import type { SupportTicket } from '../../lib/api/reporting'
import {
  usePlatformSupportTicket,
  usePlatformSupportTickets,
  useReplyPlatformSupportTicket,
  useUpdatePlatformSupportTicket,
} from '../../lib/reporting/hooks'
import { statusTone, TicketThread } from '../support/SupportPage'

function errorText(error: unknown, fallback: string): string {
  return error instanceof ApiError ? error.message : fallback
}

export function PlatformSupportPage() {
  const [status, setStatus] = useState('')
  const [priority, setPriority] = useState('')
  const [selected, setSelected] = useState<string | undefined>()
  const [reply, setReply] = useState('')
  const [internal, setInternal] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)

  const tickets = usePlatformSupportTickets({ status, priority })
  const detail = usePlatformSupportTicket(selected)
  const replyTicket = useReplyPlatformSupportTicket()
  const updateTicket = useUpdatePlatformSupportTicket()

  useEffect(() => {
    if (!selected && tickets.data?.length) setSelected(tickets.data[0].id)
  }, [tickets.data, selected])

  function submitReply() {
    if (!selected) return
    setError(null)
    setNotice(null)
    replyTicket.mutate(
      { ticketId: selected, body: reply.trim(), internal },
      {
        onSuccess: () => {
          setReply('')
          setNotice(internal ? 'Internal note added.' : 'Reply sent to the customer.')
        },
        onError: (err) => setError(errorText(err, 'Could not send the reply.')),
      },
    )
  }

  function update(field: 'status' | 'priority', value: string) {
    if (!selected) return
    setError(null)
    setNotice(null)
    updateTicket.mutate(
      { ticketId: selected, input: { [field]: value } },
      {
        onSuccess: (ticket) => setNotice(`${field === 'status' ? 'Status' : 'Priority'} set to ${ticket[field]}.`),
        onError: (err) => setError(errorText(err, 'Could not update the ticket.')),
      },
    )
  }

  const current: SupportTicket | undefined = detail.data
  const openCount = tickets.data?.filter((ticket) => !['resolved', 'closed'].includes(ticket.status)).length ?? 0
  const overdueCount = tickets.data?.filter((ticket) => ticket.overdue).length ?? 0

  return (
    <div className="space-y-6">
      <PageHeader
        title="Support queue"
        description="Triage tenant tickets, respond within SLA, and keep internal notes auditable."
      />

      <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <div className="rounded-xl border border-gray-200 bg-white px-5 py-4 shadow-sm">
          <p className="text-xs uppercase tracking-wide text-gray-500">Open</p>
          <p className="mt-1 text-2xl font-semibold text-gray-900" data-testid="support-open-count">{openCount}</p>
        </div>
        <div className="rounded-xl border border-gray-200 bg-white px-5 py-4 shadow-sm">
          <p className="text-xs uppercase tracking-wide text-gray-500">Past SLA</p>
          <p className="mt-1 text-2xl font-semibold text-amber-700" data-testid="support-overdue-count">{overdueCount}</p>
        </div>
        <div className="rounded-xl border border-gray-200 bg-white px-5 py-4 shadow-sm">
          <p className="text-xs uppercase tracking-wide text-gray-500">Visible tickets</p>
          <p className="mt-1 text-2xl font-semibold text-gray-900">{tickets.data?.length ?? 0}</p>
        </div>
      </div>

      {error && <Alert kind="error">{error}</Alert>}
      {notice && !error && <Alert kind="success">{notice}</Alert>}

      <div className="grid grid-cols-1 gap-6 xl:grid-cols-12">
        <div className="xl:col-span-5">
          <Card
            title="Ticket queue"
            actions={
              <div className="flex gap-2">
                <Select label="" aria-label="Filter status" value={status} onChange={(event) => setStatus(event.target.value)}>
                  <option value="">All statuses</option>
                  <option value="open">Open</option>
                  <option value="pending">Pending</option>
                  <option value="resolved">Resolved</option>
                  <option value="closed">Closed</option>
                </Select>
                <Select label="" aria-label="Filter priority" value={priority} onChange={(event) => setPriority(event.target.value)}>
                  <option value="">All priorities</option>
                  <option value="urgent">Urgent</option>
                  <option value="high">High</option>
                  <option value="normal">Normal</option>
                  <option value="low">Low</option>
                </Select>
              </div>
            }
          >
            {(tickets.data?.length ?? 0) === 0 ? (
              <EmptyState title="Queue is clear" description="New tenant tickets will appear here with SLA timers." />
            ) : (
              <ul className="divide-y divide-gray-100" data-testid="platform-support-list">
                {tickets.data?.map((ticket) => (
                  <li key={ticket.id}>
                    <button
                      type="button"
                      onClick={() => setSelected(ticket.id)}
                      className={`-mx-2 flex w-[calc(100%+1rem)] items-start justify-between gap-3 rounded-lg px-2 py-3 text-left transition-colors hover:bg-gray-50 ${
                        selected === ticket.id ? 'bg-brand-50' : ''
                      }`}
                      data-testid="platform-support-row"
                    >
                      <div className="min-w-0">
                        <p className="truncate text-sm font-medium text-gray-900">{ticket.subject}</p>
                        <p className="mt-1 text-xs text-gray-500">
                          {ticket.tenant?.name ?? 'Unknown tenant'} · {ticket.priority} · {ticket.messages_count} messages
                        </p>
                      </div>
                      <div className="flex shrink-0 flex-col items-end gap-1">
                        <Badge tone={statusTone(ticket.status)}>{ticket.status}</Badge>
                        {ticket.overdue && <Badge tone="red">SLA overdue</Badge>}
                      </div>
                    </button>
                  </li>
                ))}
              </ul>
            )}
          </Card>
        </div>

        <div className="xl:col-span-7">
          {!current ? (
            <Card title="Ticket detail">
              <EmptyState title="Select a ticket" description="Choose a ticket to see the conversation and respond." />
            </Card>
          ) : (
            <Card
              title={current.subject}
              actions={<Badge tone={statusTone(current.status)}>{current.status}</Badge>}
            >
              <div className="space-y-4">
                <dl className="grid grid-cols-2 gap-3 text-xs text-gray-500 sm:grid-cols-4">
                  <div>
                    <dt>Tenant</dt>
                    <dd className="font-medium text-gray-800">{current.tenant?.name ?? '—'}</dd>
                  </div>
                  <div>
                    <dt>Raised by</dt>
                    <dd className="font-medium text-gray-800">{current.created_by ?? '—'}</dd>
                  </div>
                  <div>
                    <dt>SLA due</dt>
                    <dd className={`font-medium ${current.overdue ? 'text-red-700' : 'text-gray-800'}`}>
                      {current.sla_due_at ? formatDateTime(current.sla_due_at) : '—'}
                    </dd>
                  </div>
                  <div>
                    <dt>First response</dt>
                    <dd className="font-medium text-gray-800">
                      {current.first_response_at ? formatDateTime(current.first_response_at) : 'Awaiting'}
                    </dd>
                  </div>
                </dl>

                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                  <Select label="Status" name="ticket-status" value={current.status} onChange={(event) => update('status', event.target.value)}>
                    <option value="open">Open</option>
                    <option value="pending">Pending</option>
                    <option value="resolved">Resolved</option>
                    <option value="closed">Closed</option>
                  </Select>
                  <Select label="Priority" name="ticket-priority" value={current.priority} onChange={(event) => update('priority', event.target.value)}>
                    <option value="low">Low</option>
                    <option value="normal">Normal</option>
                    <option value="high">High</option>
                    <option value="urgent">Urgent</option>
                  </Select>
                </div>

                <TicketThread ticket={current} />

                <div className="space-y-2 border-t border-gray-100 pt-4">
                  <Field label={internal ? 'Internal note' : 'Customer reply'} id="platform-support-reply">
                    <textarea
                      id="platform-support-reply"
                      className="block min-h-24 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:outline-2 focus:outline-brand-600"
                      value={reply}
                      onChange={(event) => setReply(event.target.value)}
                      placeholder={internal ? 'Internal notes are visible to support staff only.' : 'Reply to the customer.'}
                      data-testid="platform-support-reply"
                    />
                  </Field>
                  <label className="flex items-center gap-2 text-sm text-gray-600">
                    <input
                      type="checkbox"
                      checked={internal}
                      onChange={(event) => setInternal(event.target.checked)}
                    />
                    Internal note (not visible to the tenant)
                  </label>
                  <Button onClick={submitReply} loading={replyTicket.isPending} disabled={reply.trim().length === 0}>
                    {internal ? 'Save note' : 'Send reply'}
                  </Button>
                </div>
              </div>
            </Card>
          )}
        </div>
      </div>
    </div>
  )
}
