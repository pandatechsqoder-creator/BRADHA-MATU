import { useState } from 'react'
import { Link } from '@tanstack/react-router'
import { Alert, Badge, Button, Card, EmptyState, PageHeader, PageLoader, Table } from '../../components/ui'
import { ApiError } from '../../lib/api/client'
import type { WorkforceAssignment } from '../../lib/api/workforce'
import { formatDateTime, formatMoney, formatStatusLabel } from '../../lib/formatting'
import {
  useAcceptWorkforceAssignment,
  useDeclineWorkforceAssignment,
  useWorkforceAssignments,
  useWorkforceProfile,
} from '../../lib/workforce/hooks'

function errorMessage(error: unknown): string {
  return error instanceof ApiError ? error.message : 'Something went wrong. Try again.'
}

function statusTone(status: string): 'green' | 'amber' | 'red' | 'gray' {
  if (status === 'accepted' || status === 'completed') return 'green'
  if (status === 'offered') return 'amber'
  if (status === 'declined' || status === 'expired' || status === 'withdrawn') return 'red'
  return 'gray'
}

export function WorkforceAssignmentsPage() {
  const profile = useWorkforceProfile()
  const assignments = useWorkforceAssignments()

  if (profile.isLoading || assignments.isLoading) {
    return <PageLoader label="Loading your assignments…" />
  }

  if (!profile.data) {
    return (
      <div className="mx-auto max-w-3xl space-y-4">
        <PageHeader title="Assignments" description="Jobs offered to you by BRATHAMATU." />
        <Alert kind="info">
          Complete your workforce profile and verification before assignment offers can reach you.
        </Alert>
        <Link to="/workforce/profile" className="text-sm font-medium text-brand-700 hover:underline">
          Set up my workforce profile →
        </Link>
      </div>
    )
  }

  const rows = assignments.data ?? []
  const offers = rows.filter((assignment) => assignment.status === 'offered')
  const active = rows.filter((assignment) => assignment.status === 'accepted')
  const history = rows.filter((assignment) => !['offered', 'accepted'].includes(assignment.status))

  return (
    <div className="mx-auto max-w-4xl space-y-6">
      <PageHeader
        title="Assignments"
        description="Accept or decline job offers. You only see your own compensation — never the tenant charge or platform margin."
        actions={
          <Link to="/workforce/profile" className="text-sm font-medium text-brand-700 hover:underline">
            My profile →
          </Link>
        }
      />

      {assignments.isError && <Alert kind="error">{errorMessage(assignments.error)}</Alert>}

      <section className="space-y-3">
        <h2 className="text-sm font-semibold uppercase tracking-wide text-gray-500">Open offers</h2>
        {offers.length === 0 ? (
          <EmptyState title="No open offers" description="BRATHAMATU will offer you eligible jobs as they are approved." />
        ) : (
          offers.map((assignment) => <OfferCard key={assignment.id} assignment={assignment} />)
        )}
      </section>

      <section className="space-y-3">
        <h2 className="text-sm font-semibold uppercase tracking-wide text-gray-500">Accepted / scheduled</h2>
        {active.length === 0 ? (
          <EmptyState title="No accepted assignments" />
        ) : (
          active.map((assignment) => <AcceptedCard key={assignment.id} assignment={assignment} />)
        )}
      </section>

      <Card title="Assignment history">
        {history.length === 0 ? (
          <p className="text-sm text-gray-500">Declined, withdrawn and expired offers will appear here.</p>
        ) : (
          <Table headers={['Job', 'Responded', 'Status', 'Compensation']}>
            {history.map((assignment) => (
              <tr key={assignment.id}>
                <td className="px-4 py-3">
                  <p className="font-medium text-gray-900">{assignment.job?.reference ?? '—'}</p>
                  <p className="text-xs text-gray-500">{assignment.job?.tenant ?? ''}</p>
                </td>
                <td className="px-4 py-3 text-gray-600">
                  {assignment.responded_at ? formatDateTime(assignment.responded_at) : '—'}
                </td>
                <td className="px-4 py-3">
                  <Badge tone={statusTone(assignment.status)}>{formatStatusLabel(assignment.status)}</Badge>
                </td>
                <td className="px-4 py-3 text-gray-600">
                  {formatMoney(assignment.compensation.amount, assignment.compensation.currency)}
                </td>
              </tr>
            ))}
          </Table>
        )}
      </Card>
    </div>
  )
}

function JobFacts({ assignment }: { assignment: WorkforceAssignment }) {
  const job = assignment.job

  return (
    <dl className="grid gap-2 text-sm sm:grid-cols-2">
      <div className="flex justify-between gap-3">
        <dt className="text-gray-500">Reference</dt>
        <dd className="font-medium text-gray-900">{job?.reference ?? '—'}</dd>
      </div>
      <div className="flex justify-between gap-3">
        <dt className="text-gray-500">Business</dt>
        <dd className="text-gray-800">{job?.tenant ?? '—'}</dd>
      </div>
      <div className="flex justify-between gap-3">
        <dt className="text-gray-500">Start</dt>
        <dd className="text-gray-800">{job?.preferred_start_at ? formatDateTime(job.preferred_start_at) : 'To be confirmed'}</dd>
      </div>
      <div className="flex justify-between gap-3">
        <dt className="text-gray-500">Your role</dt>
        <dd className="capitalize text-gray-800">{assignment.role}</dd>
      </div>
      <div className="flex justify-between gap-3">
        <dt className="text-gray-500">Scope</dt>
        <dd className="text-gray-800">{job?.scope_label ?? `${job?.location_count ?? 0} location(s)`}</dd>
      </div>
      <div className="flex justify-between gap-3">
        <dt className="text-gray-500">Team size</dt>
        <dd className="text-gray-800">{job?.required_team_size ?? '—'}</dd>
      </div>
    </dl>
  )
}

function OfferCard({ assignment }: { assignment: WorkforceAssignment }) {
  const accept = useAcceptWorkforceAssignment()
  const decline = useDeclineWorkforceAssignment()
  const [declining, setDeclining] = useState(false)
  const [reason, setReason] = useState('')

  return (
    <Card
      title={assignment.job?.title ?? 'Assignment offer'}
      actions={<Badge tone="amber">Offer · expires {assignment.offer_expires_at ? formatDateTime(assignment.offer_expires_at) : '—'}</Badge>}
    >
      <div className="space-y-4">
        <JobFacts assignment={assignment} />

        <div className="rounded-lg border border-brand-200 bg-brand-50 px-4 py-3">
          <p className="text-xs font-medium uppercase tracking-wide text-brand-800">Your compensation</p>
          <p className="mt-1 text-2xl font-semibold text-brand-900">
            {formatMoney(assignment.compensation.amount, assignment.compensation.currency)}
          </p>
          <p className="mt-1 text-xs text-brand-800">
            This is your disclosed fee for the assignment. Tenant charges and platform margins are never shown here.
          </p>
        </div>

        {(accept.isError || decline.isError) && (
          <Alert kind="error">{errorMessage(accept.error ?? decline.error)}</Alert>
        )}

        {declining ? (
          <div className="space-y-2">
            <label htmlFor={`reason-${assignment.id}`} className="block text-sm font-medium text-gray-700">
              Reason for declining
            </label>
            <textarea
              id={`reason-${assignment.id}`}
              className="block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:outline-2 focus:outline-brand-600"
              rows={2}
              value={reason}
              onChange={(event) => setReason(event.target.value)}
            />
            <div className="flex gap-2">
              <Button
                variant="danger"
                loading={decline.isPending}
                disabled={reason.trim().length < 3}
                onClick={() => decline.mutate({ id: assignment.id, reason: reason.trim() })}
              >
                Submit decline
              </Button>
              <Button variant="secondary" onClick={() => setDeclining(false)}>
                Cancel
              </Button>
            </div>
          </div>
        ) : (
          <div className="flex flex-wrap gap-2">
            <Button loading={accept.isPending} onClick={() => accept.mutate(assignment.id)}>
              Accept assignment
            </Button>
            <Button variant="secondary" onClick={() => setDeclining(true)}>
              Decline
            </Button>
          </div>
        )}
      </div>
    </Card>
  )
}

function AcceptedCard({ assignment }: { assignment: WorkforceAssignment }) {
  return (
    <Card
      title={assignment.job?.title ?? 'Accepted assignment'}
      actions={<Badge tone="green">{formatStatusLabel(assignment.status)}</Badge>}
    >
      <div className="space-y-4">
        <JobFacts assignment={assignment} />
        <div className="rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 text-sm">
          <p className="text-gray-600">
            Compensation: <strong className="text-gray-900">{formatMoney(assignment.compensation.amount, assignment.compensation.currency)}</strong>
          </p>
          {assignment.earning && (
            <p className="mt-1 text-gray-600">
              Earning {assignment.earning.reference}: <strong className="text-gray-900">{formatStatusLabel(assignment.earning.status)}</strong>
            </p>
          )}
        </div>
        <Link
          to="/workforce/assignments/$assignmentId/operations"
          params={{ assignmentId: assignment.id }}
          className="inline-block text-sm font-medium text-brand-700 hover:underline"
        >
          Open field operations →
        </Link>
        {assignment.job?.status === 'in_progress' && (
          <Link
            to="/workforce/assignments/$assignmentId/count"
            params={{ assignmentId: assignment.id }}
            className="ml-4 inline-block text-sm font-semibold text-green-700 hover:underline"
          >
            Start counting →
          </Link>
        )}
      </div>
    </Card>
  )
}
