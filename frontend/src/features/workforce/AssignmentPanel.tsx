import { useState } from 'react'
import { Alert, Badge, Button, Card, Field, Select } from '../../components/ui'
import { ApiError } from '../../lib/api/client'
import type { AssignmentRecommendation, WorkforceAssignment } from '../../lib/api/workforce'
import { useCurrentUser } from '../../lib/auth/hooks'
import { formatDateTime, formatMoney, formatStatusLabel } from '../../lib/formatting'
import {
  useAssignmentRecommendations,
  useOfferAssignment,
  useWithdrawAssignment,
} from '../../lib/workforce/hooks'

const ASSIGNABLE_STATUSES = ['approved_for_execution', 'awaiting_assignment', 'assigned', 'awaiting_worker_acceptance']

function errorMessage(error: unknown): string {
  return error instanceof ApiError ? error.message : 'Something went wrong. Try again.'
}

function assignmentTone(status: string): 'green' | 'amber' | 'red' | 'gray' {
  if (status === 'accepted') return 'green'
  if (status === 'offered') return 'amber'
  if (['declined', 'expired', 'withdrawn', 'reassigned'].includes(status)) return 'red'
  return 'gray'
}

export function AssignmentPanel({ jobId, jobStatus }: { jobId: string; jobStatus: string }) {
  const { data: user } = useCurrentUser()
  const permissions = new Set(user?.permissions ?? [])
  const canAssign = permissions.has('stocktake.job.assign')
  const canView = canAssign || permissions.has('assignment.view')
  const recommendations = useAssignmentRecommendations(canView ? jobId : null)

  if (!canView) return null

  if (recommendations.isLoading) {
    return (
      <Card title="Workforce assignment">
        <p className="text-sm text-gray-500">Loading recommendations…</p>
      </Card>
    )
  }

  if (recommendations.isError || !recommendations.data) {
    return (
      <Card title="Workforce assignment">
        <Alert kind="error">
          {recommendations.error ? errorMessage(recommendations.error) : 'Recommendations could not be loaded.'}
        </Alert>
      </Card>
    )
  }

  const data = recommendations.data
  const assignable = canAssign && ASSIGNABLE_STATUSES.includes(jobStatus)
  const activeAssignments = data.assignments.filter((assignment) => ['offered', 'accepted'].includes(assignment.status))

  return (
    <Card
      title="Workforce assignment"
      actions={
        <div className="flex items-center gap-2 text-xs text-gray-500">
          <Badge tone={data.filled_slots >= data.required_team_size ? 'green' : 'amber'}>
            {data.filled_slots}/{data.required_team_size} slots
          </Badge>
          <span>Comp per worker {formatMoney(data.proposed_compensation, 'KES')}</span>
        </div>
      }
    >
      <div className="space-y-4">
        {!assignable && (
          <Alert kind="info">
            {ASSIGNABLE_STATUSES.includes(jobStatus)
              ? 'You do not have permission to offer assignments.'
              : 'Assignments can be offered after execution approval and before the count starts.'}
          </Alert>
        )}

        <div>
          <h3 className="text-xs font-semibold uppercase tracking-wide text-gray-500">
            Active assignments ({activeAssignments.length})
          </h3>
          {activeAssignments.length === 0 ? (
            <p className="mt-2 text-sm text-gray-500">No stock taker is currently assigned.</p>
          ) : (
            <ul className="mt-2 space-y-2">
              {activeAssignments.map((assignment) => (
                <AssignmentRow key={assignment.id} assignment={assignment} canAssign={canAssign} />
              ))}
            </ul>
          )}
        </div>

        <div>
          <h3 className="text-xs font-semibold uppercase tracking-wide text-gray-500">
            Recommended stock takers ({data.recommendations.length})
          </h3>
          {data.recommendations.length === 0 ? (
            <p className="mt-2 text-sm text-gray-500">
              No verified, trained and available stock takers exist yet. Onboard workers in the Workforce console.
            </p>
          ) : (
            <ul className="mt-2 space-y-2">
              {data.recommendations.map((recommendation) => (
                <RecommendationRow
                  key={recommendation.profile.id}
                  jobId={jobId}
                  recommendation={recommendation}
                  canAssign={assignable}
                  teamFull={data.filled_slots >= data.required_team_size}
                />
              ))}
            </ul>
          )}
        </div>
      </div>
    </Card>
  )
}

function AssignmentRow({ assignment, canAssign }: { assignment: WorkforceAssignment; canAssign: boolean }) {
  const withdraw = useWithdrawAssignment()
  const [confirming, setConfirming] = useState(false)

  return (
    <li className="rounded-lg border border-gray-200 px-3 py-2 text-sm">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <p className="font-medium text-gray-900">
            {assignment.profile?.name ?? 'Stock taker'} · <span className="capitalize">{assignment.role}</span>
          </p>
          <p className="text-xs text-gray-500">
            {assignment.job?.reference ?? ''} · Offered{' '}
            {assignment.offered_at ? formatDateTime(assignment.offered_at) : '—'} ·{' '}
            {formatMoney(assignment.compensation.amount, assignment.compensation.currency)}
          </p>
        </div>
        <div className="flex items-center gap-2">
          <Badge tone={assignmentTone(assignment.status)}>{formatStatusLabel(assignment.status)}</Badge>
          {canAssign && ['offered', 'accepted'].includes(assignment.status) && (
            confirming ? (
              <div className="flex gap-2">
                <Button
                  variant="danger"
                  loading={withdraw.isPending}
                  onClick={() => withdraw.mutate({ id: assignment.id, reason: 'Withdrawn from assignment console.' })}
                >
                  Confirm
                </Button>
                <Button variant="secondary" onClick={() => setConfirming(false)}>
                  Cancel
                </Button>
              </div>
            ) : (
              <Button variant="secondary" onClick={() => setConfirming(true)}>
                Withdraw
              </Button>
            )
          )}
        </div>
      </div>
      {withdraw.isError && <p className="mt-1 text-xs text-red-600">{errorMessage(withdraw.error)}</p>}
    </li>
  )
}

function RecommendationRow({
  jobId,
  recommendation,
  canAssign,
  teamFull,
}: {
  jobId: string
  recommendation: AssignmentRecommendation
  canAssign: boolean
  teamFull: boolean
}) {
  const offer = useOfferAssignment(jobId)
  const [open, setOpen] = useState(false)
  const [role, setRole] = useState<'counter' | 'lead' | 'supervisor'>('counter')
  const [compensation, setCompensation] = useState(String(recommendation.proposed_compensation))
  const [overrideReason, setOverrideReason] = useState('')
  const [notice, setNotice] = useState<string | null>(null)

  const profile = recommendation.profile

  const submit = () => {
    offer.mutate(
      {
        profile_id: profile.id,
        role,
        compensation_amount: Number(compensation) || undefined,
        override_reason: overrideReason || undefined,
      },
      {
        onSuccess: () => {
          setOpen(false)
          setNotice('Offer sent.')
        },
      },
    )
  }

  return (
    <li className="rounded-lg border border-gray-200 px-3 py-2 text-sm">
      <div className="flex flex-wrap items-start justify-between gap-2">
        <div>
          <p className="font-medium text-gray-900">
            {profile.legal_name ?? profile.user?.name ?? 'Stock taker'}{' '}
            <span className="text-xs font-normal text-gray-500">
              {profile.level_label} · {profile.city ?? '—'}
            </span>
          </p>
          <p className="text-xs text-gray-500">
            Score {recommendation.score} · {recommendation.reasons.join(' ')}
          </p>
          {recommendation.blockers.length > 0 && (
            <p className="mt-1 text-xs text-red-600">{recommendation.blockers.join(' ')}</p>
          )}
        </div>
        <div className="flex items-center gap-2">
          <Badge tone={recommendation.eligible ? 'green' : 'red'}>
            {recommendation.eligible ? 'Eligible' : 'Blocked'}
          </Badge>
          {recommendation.already_assigned ? (
            <Badge tone="amber">Already assigned</Badge>
          ) : (
            canAssign &&
            !teamFull && (
              <Button variant="secondary" onClick={() => setOpen(!open)}>
                {open ? 'Close' : 'Offer'}
              </Button>
            )
          )}
        </div>
      </div>

      {notice && <p className="mt-1 text-xs text-brand-700">{notice}</p>}
      {offer.isError && <p className="mt-1 text-xs text-red-600">{errorMessage(offer.error)}</p>}

      {open && (
        <div className="mt-3 space-y-2 rounded-lg border border-gray-200 bg-gray-50 p-3">
          <div className="grid gap-2 sm:grid-cols-2">
            <Select label="Role" name={`role-${profile.id}`} value={role} onChange={(event) => setRole(event.target.value as typeof role)}>
              {['counter', 'lead', 'supervisor'].map((option) => (
                <option key={option} value={option}>
                  {formatStatusLabel(option)}
                </option>
              ))}
            </Select>
            <Field
              label="Compensation (KES)"
              name={`comp-${profile.id}`}
              type="number"
              min={0}
              step="0.01"
              value={compensation}
              onChange={(event) => setCompensation(event.target.value)}
            />
          </div>
          {!recommendation.eligible && (
            <Field
              label="Override reason"
              name={`override-${profile.id}`}
              hint="Required to offer a blocked stock taker. Stored with the offer and audit trail."
              value={overrideReason}
              onChange={(event) => setOverrideReason(event.target.value)}
            />
          )}
          <Button
            loading={offer.isPending}
            disabled={!recommendation.eligible && overrideReason.trim().length < 5}
            onClick={submit}
          >
            Send offer
          </Button>
        </div>
      )}
    </li>
  )
}
