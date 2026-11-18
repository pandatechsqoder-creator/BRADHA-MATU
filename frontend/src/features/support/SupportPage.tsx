import { useEffect, useState } from 'react'
import { Alert, Badge, Button, Card, EmptyState, Field, PageHeader, Select, Table } from '../../components/ui'
import { ApiError } from '../../lib/api/client'
import { useCurrentUser } from '../../lib/auth/hooks'
import { formatDateTime } from '../../lib/formatting'
import type { SupportTicket } from '../../lib/api/reporting'
import {
  useCreateSupportTicket,
  useReplySupportTicket,
  useSupportTicket,
  useSupportTickets,
  useUpdateSupportTicket,
} from '../../lib/reporting/hooks'

function errorText(error: unknown, fallback: string): string {
  return error instanceof ApiError ? error.message : fallback
}

export function statusTone(status: string): 'gray' | 'green' | 'red' | 'amber' {
  if (status === 'resolved') return 'green'
  if (status === 'closed') return 'gray'
  if (status === 'pending') return 'amber'
  return 'amber'
}

export function TicketThread({ ticket }: { ticket: SupportTicket }) {
  return (
    <ol className="space-y-3">
      {(ticket.messages ?? []).map((message) => (
        <li
          key={message.id}
          className={`rounded-lg border px-4 py-3 text-sm ${
            message.is_internal
              ? 'border-amber-200 bg-amber-50'
              : 'border-gray-200 bg-white'
          }`}
        >
          <div className="flex flex-wrap items-center justify-between gap-2 text-xs text-gray-500">
            <span className="font-medium text-gray-700">
              {message.author ?? 'System'}
              {message.is_internal && ' · internal note'}
            </span>
            <span>{message.created_at ? formatDateTime(message.created_at) : ''}</span>
          </div>
          <p className="mt-2 whitespace-pre-wrap text-gray-800">{message.body}</p>
        </li>
      ))}
    </ol>
  )
}

function ContactCard() {
  return (
    <Card title="Contact BRATHAMATU">
      <dl className="space-y-3 text-sm">
        <div className="flex justify-between gap-4">
          <dt className="text-gray-500">Support email</dt>
          <dd className="font-medium text-gray-900">support@brathamatu.co.ke</dd>
        </div>
        <div className="flex justify-between gap-4">
          <dt className="text-gray-500">Operating hours</dt>
          <dd className="text-gray-900">Mon–Sat, 08:00–18:00 EAT</dd>
        </div>
      </dl>
    </Card>
  )
}

export function SupportPage() {
  const { data: user } = useCurrentUser()
  const tenantId = user?.current_tenant?.id
  const canSupport = Boolean(user?.permissions.includes('support.request'))
  const [status, setStatus] = useState('')
  const [selected, setSelected] = useState<string | undefined>()
  const [subject, setSubject] = useState('')
  const [description, setDescription] = useState('')
  const [category, setCategory] = useState('general')
  const [priority, setPriority] = useState('normal')
  const [reply, setReply] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)

  const tickets = useSupportTickets(tenantId, { status })
  const detail = useSupportTicket(tenantId, selected)
  const createTicket = useCreateSupportTicket(tenantId)
  const replyTicket = useReplySupportTicket(tenantId)
  const updateTicket = useUpdateSupportTicket(tenantId)

  useEffect(() => {
    if (!selected && tickets.data?.length) setSelected(tickets.data[0].id)
  }, [tickets.data, selected])

  if (!tenantId || !canSupport) {
    return (
      <div className="mx-auto max-w-3xl space-y-6">
        <PageHeader title="Support" description="Reach the BRATHAMATU team for help with your workspace." />
        <ContactCard />
        <Alert kind="info">
          Ticket creation is available to business team members. Ask your business owner for the support
          permission if you need to open a ticket here.
        </Alert>
      </div>
    )
  }

  function submitTicket() {
    setError(null)
    setNotice(null)
    createTicket.mutate(
      { subject: subject.trim(), description: description.trim(), category, priority },
      {
        onSuccess: (ticket) => {
          setSubject('')
          setDescription('')
          setPriority('normal')
          setSelected(ticket.id)
          setNotice(`Ticket ${ticket.reference.slice(-8)} created. Our team has been notified.`)
        },
        onError: (err) => setError(errorText(err, 'Could not create the ticket.')),
      },
    )
  }

  function submitReply() {
    if (!selected) return
    setError(null)
    setNotice(null)
    replyTicket.mutate(
      { ticketId: selected, body: reply.trim() },
      {
        onSuccess: () => {
          setReply('')
          setNotice('Reply sent.')
        },
        onError: (err) => setError(errorText(err, 'Could not send the reply.')),
      },
    )
  }

  function changeStatus(next: 'open' | 'closed') {
    if (!selected) return
    setError(null)
    setNotice(null)
    updateTicket.mutate(
      { ticketId: selected, status: next },
      {
        onSuccess: () => setNotice(next === 'closed' ? 'Ticket closed.' : 'Ticket reopened.'),
        onError: (err) => setError(errorText(err, 'Could not update the ticket.')),
      },
    )
  }

  const current = detail.data

  return (
    <div className="space-y-6">
      <PageHeader
        title="Support centre"
        description="Open tickets, reply to the BRATHAMATU support team, and track SLA response times."
      />

      <div className="grid grid-cols-1 gap-6 xl:grid-cols-12">
        <div className="space-y-6 xl:col-span-5">
          <Card title="Open a ticket">
            <div className="space-y-4">
              {error && <Alert kind="error">{error}</Alert>}
              {notice && !error && <Alert kind="success">{notice}</Alert>}
              <Field
                label="Subject"
                name="subject"
                value={subject}
                onChange={(event) => setSubject(event.target.value)}
                placeholder="Short summary of the issue"
              />
              <div className="grid grid-cols-2 gap-3">
                <Select label="Category" name="category" value={category} onChange={(event) => setCategory(event.target.value)}>
                  <option value="general">General</option>
                  <option value="account">Account</option>
                  <option value="billing">Billing</option>
                  <option value="technical">Technical</option>
                  <option value="stocktake">Stock-taking</option>
                  <option value="pos">POS</option>
                  <option value="integration">Integration</option>
                </Select>
                <Select label="Priority" name="priority" value={priority} onChange={(event) => setPriority(event.target.value)}>
                  <option value="low">Low (48h SLA)</option>
                  <option value="normal">Normal (24h SLA)</option>
                  <option value="high">High (8h SLA)</option>
                  <option value="urgent">Urgent (4h SLA)</option>
                </Select>
              </div>
              <Field label="Description" id="support-description">
                <textarea
                  id="support-description"
                  className="block min-h-28 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:outline-2 focus:outline-brand-600"
                  value={description}
                  onChange={(event) => setDescription(event.target.value)}
                  placeholder="What happened, what you expected, and any references."
                  data-testid="support-description"
                />
              </Field>
              <Button
                onClick={submitTicket}
                loading={createTicket.isPending}
                disabled={subject.trim().length === 0 || description.trim().length < 10}
              >
                Create ticket
              </Button>
            </div>
          </Card>

          <Card
            title="My tickets"
            actions={
              <Select label="" aria-label="Filter tickets" value={status} onChange={(event) => setStatus(event.target.value)}>
                <option value="">All statuses</option>
                <option value="open">Open</option>
                <option value="pending">Pending</option>
                <option value="resolved">Resolved</option>
                <option value="closed">Closed</option>
              </Select>
            }
          >
            {(tickets.data?.length ?? 0) === 0 ? (
              <EmptyState title="No tickets yet" description="Create a ticket and the support team will respond within SLA." />
            ) : (
              <ul className="divide-y divide-gray-100" data-testid="support-ticket-list">
                {tickets.data?.map((ticket) => (
                  <li key={ticket.id}>
                    <button
                      type="button"
                      onClick={() => setSelected(ticket.id)}
                      className={`-mx-2 flex w-[calc(100%+1rem)] items-start justify-between gap-3 rounded-lg px-2 py-3 text-left transition-colors hover:bg-gray-50 ${
                        selected === ticket.id ? 'bg-brand-50' : ''
                      }`}
                      data-testid="support-ticket-row"
                    >
                      <div className="min-w-0">
                        <p className="truncate text-sm font-medium text-gray-900">{ticket.subject}</p>
                        <p className="mt-1 text-xs text-gray-500">
                          {ticket.reference.slice(-8)} · {ticket.category} · {ticket.messages_count} messages
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
              <EmptyState title="Select a ticket" description="Choose a ticket to view the conversation and reply." />
            </Card>
          ) : (
            <Card
              title={current.subject}
              actions={
                <div className="flex items-center gap-2">
                  <Badge tone={statusTone(current.status)}>{current.status}</Badge>
                  {current.status === 'closed' ? (
                    <Button variant="secondary" onClick={() => changeStatus('open')} loading={updateTicket.isPending}>
                      Reopen
                    </Button>
                  ) : (
                    <Button variant="secondary" onClick={() => changeStatus('closed')} loading={updateTicket.isPending}>
                      Close
                    </Button>
                  )}
                </div>
              }
            >
              <div className="space-y-4">
                <dl className="grid grid-cols-2 gap-3 text-xs text-gray-500 sm:grid-cols-4">
                  <div>
                    <dt>Reference</dt>
                    <dd className="font-medium text-gray-800">{current.reference.slice(-8)}</dd>
                  </div>
                  <div>
                    <dt>Priority</dt>
                    <dd className="font-medium text-gray-800">{current.priority}</dd>
                  </div>
                  <div>
                    <dt>SLA due</dt>
                    <dd className="font-medium text-gray-800">
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

                <TicketThread ticket={current} />

                <div className="space-y-2 border-t border-gray-100 pt-4">
                  <Field label="Reply" id="support-reply">
                    <textarea
                      id="support-reply"
                      className="block min-h-24 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:outline-2 focus:outline-brand-600"
                      value={reply}
                      onChange={(event) => setReply(event.target.value)}
                      placeholder="Add more detail or answer the support team."
                      data-testid="support-reply"
                    />
                  </Field>
                  <Button onClick={submitReply} loading={replyTicket.isPending} disabled={reply.trim().length === 0}>
                    Send reply
                  </Button>
                </div>
              </div>
            </Card>
          )}
        </div>
      </div>

      <Card title="Recent conversation summary">
        <Table
          headers={['Reference', 'Subject', 'Status', 'Priority', 'Last reply']}
          empty={<EmptyState title="No tickets" description="Tickets you create will appear here." />}
        >
          {tickets.data?.map((ticket) => (
            <tr key={ticket.id}>
              <td className="px-4 py-2 font-mono text-xs text-gray-600">{ticket.reference.slice(-8)}</td>
              <td className="px-4 py-2 font-medium text-gray-800">{ticket.subject}</td>
              <td className="px-4 py-2">
                <Badge tone={statusTone(ticket.status)}>{ticket.status}</Badge>
              </td>
              <td className="px-4 py-2 text-gray-600">{ticket.priority}</td>
              <td className="px-4 py-2 text-gray-600">
                {ticket.last_reply_at ? formatDateTime(ticket.last_reply_at) : '—'}
              </td>
            </tr>
          ))}
        </Table>
      </Card>
    </div>
  )
}
