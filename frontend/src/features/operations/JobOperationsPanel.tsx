import { Link } from '@tanstack/react-router'
import { useState } from 'react'
import { Alert, Badge, Button, Card, Select, Table } from '../../components/ui'
import { useCurrentUser } from '../../lib/auth/hooks'
import { formatDateTime, formatStatusLabel } from '../../lib/formatting'
import {
  useApplyJobProcedure,
  usePlatformJobOperations,
  useProcedureTemplates,
} from '../../lib/operations/hooks'
import { describeError, ProgressBar, StageBadge } from './shared'

const ASSIGNABLE_STATUSES = [
  'approved_for_execution',
  'awaiting_assignment',
  'assigned',
  'awaiting_worker_acceptance',
  'scheduled',
]

function capability(value: boolean, label: string) {
  return <Badge tone={value ? 'green' : 'amber'}>{value ? label : `No ${label.toLowerCase()}`}</Badge>
}

export function JobOperationsPanel({ jobId, jobStatus }: { jobId: string; jobStatus: string }) {
  const { data: user } = useCurrentUser()
  const permissions = new Set(user?.permissions ?? [])
  const canView =
    permissions.has('stocktake.job.monitor') ||
    permissions.has('stocktake.job.assign') ||
    permissions.has('stocktake.job.review') ||
    permissions.has('sop.manage')
  const canAssign = permissions.has('sop.manage') || permissions.has('stocktake.job.assign')

  const operations = usePlatformJobOperations(jobId, canView)
  const templates = useProcedureTemplates()
  const apply = useApplyJobProcedure(jobId)
  const [templateId, setTemplateId] = useState('')
  const [notice, setNotice] = useState<string | null>(null)

  if (!canView) return null

  if (operations.isLoading) {
    return (
      <Card title="Field operations">
        <p className="text-sm text-gray-500">Loading SOP, readiness and attendance…</p>
      </Card>
    )
  }

  if (operations.isError || !operations.data) {
    return (
      <Card title="Field operations">
        <Alert kind="error">
          {operations.error ? describeError(operations.error) : 'Field operations could not be loaded.'}
        </Alert>
      </Card>
    )
  }

  const data = operations.data
  const assignable = canAssign && ASSIGNABLE_STATUSES.includes(jobStatus)

  const submitProcedure = () => {
    if (!templateId) return
    setNotice(null)
    apply.mutate(
      { template_id: templateId },
      {
        onSuccess: () => {
          setTemplateId('')
          setNotice('SOP assigned to the job and snapshot recorded.')
        },
      },
    )
  }

  return (
    <Card
      title="Field operations"
      actions={
        <div className="flex items-center gap-2">
          <StageBadge stage={data.progress.stage} />
          <Link to="/platform/operations" className="text-xs font-medium text-brand-700 hover:underline">
            Live board →
          </Link>
        </div>
      }
    >
      <div className="space-y-5">
        <div className="grid gap-3 sm:grid-cols-4">
          <div className="rounded-lg border border-gray-200 p-3">
            <p className="text-xs uppercase tracking-wide text-gray-500">Readiness</p>
            <p className="mt-1 text-lg font-semibold text-gray-900">{data.summary.percent}%</p>
            <div className="mt-2">
              <ProgressBar percent={data.summary.percent} label="Job readiness" />
            </div>
          </div>
          <div className="rounded-lg border border-gray-200 p-3">
            <p className="text-xs uppercase tracking-wide text-gray-500">Team</p>
            <p className="mt-1 text-lg font-semibold text-gray-900">
              {data.summary.team_accepted}/{data.summary.team_required}
            </p>
            <p className="text-xs text-gray-500">{data.summary.checked_in} checked in</p>
          </div>
          <div className="rounded-lg border border-gray-200 p-3">
            <p className="text-xs uppercase tracking-wide text-gray-500">SOP acknowledgements</p>
            <p className="mt-1 text-lg font-semibold text-gray-900">{data.summary.sop_acknowledged}</p>
            <p className="text-xs text-gray-500">{data.procedure ? `v${data.procedure.version}` : 'No SOP'}</p>
          </div>
          <div className="rounded-lg border border-gray-200 p-3">
            <p className="text-xs uppercase tracking-wide text-gray-500">Count gate</p>
            <p className="mt-1 text-lg font-semibold text-gray-900">{data.summary.can_start_count ? 'Open' : 'Blocked'}</p>
            <p className="text-xs text-gray-500">{data.summary.next_action ?? 'All requirements met'}</p>
          </div>
        </div>

        {data.summary.blockers.length > 0 && (
          <Alert kind="info">
            <p className="font-medium">Outstanding before counting</p>
            <ul className="mt-1 list-disc space-y-1 pl-5 text-sm">
              {data.summary.blockers.map((blocker) => (
                <li key={blocker}>{blocker}</li>
              ))}
            </ul>
          </Alert>
        )}

        <div className="grid gap-4 lg:grid-cols-2">
          <div className="space-y-2">
            <h3 className="text-xs font-semibold uppercase tracking-wide text-gray-500">Assigned SOP</h3>
            {!data.procedure ? (
              <p className="text-sm text-gray-500">
                No SOP has been assigned. Workers cannot check in until a procedure is attached.
              </p>
            ) : (
              <div className="rounded-lg border border-gray-200 p-3 text-sm">
                <div className="flex flex-wrap items-center justify-between gap-2">
                  <p className="font-medium text-gray-900">{data.procedure.title}</p>
                  <div className="flex items-center gap-2">
                    <Badge tone="gray">v{data.procedure.version}</Badge>
                    <Badge tone={data.procedure.require_acknowledgement ? 'amber' : 'gray'}>
                      {data.procedure.require_acknowledgement ? 'Acknowledgement required' : 'No acknowledgement'}
                    </Badge>
                  </div>
                </div>
                {data.procedure.summary && <p className="mt-1 text-gray-600">{data.procedure.summary}</p>}
                <p className="mt-1 text-xs text-gray-500">
                  Assigned {data.procedure.assigned_at ? formatDateTime(data.procedure.assigned_at) : '—'} ·{' '}
                  {data.procedure.acknowledgements} acknowledgement(s)
                </p>
                <ol className="mt-2 space-y-1 text-gray-700">
                  {data.procedure.steps.map((step) => (
                    <li key={step.id}>
                      {step.position}. {step.title}
                      {step.evidence_required && <span className="ml-1 text-xs text-amber-700">evidence</span>}
                    </li>
                  ))}
                </ol>
                {data.procedure.checklists.length > 0 && (
                  <ul className="mt-2 space-y-1 text-xs text-gray-500">
                    {data.procedure.checklists.map((checklist) => (
                      <li key={checklist.id}>
                        {checklist.name}: {checklist.items_required} required of {checklist.items_total} items
                      </li>
                    ))}
                  </ul>
                )}
              </div>
            )}

            {canAssign && (
              <div className="space-y-2 rounded-lg border border-gray-200 bg-gray-50 p-3">
                <Select
                  label="Procedure template"
                  value={templateId}
                  disabled={!assignable}
                  onChange={(event) => setTemplateId(event.target.value)}
                >
                  <option value="">Choose a published template</option>
                  {(templates.data ?? [])
                    .filter((template) => template.current_version !== null)
                    .map((template) => (
                      <option key={template.id} value={template.id}>
                        {template.name} · v{template.current_version?.version}
                      </option>
                    ))}
                </Select>
                <div className="flex flex-wrap items-center gap-2">
                  <Button loading={apply.isPending} disabled={!assignable || !templateId} onClick={submitProcedure}>
                    {data.procedure ? 'Replace SOP for this job' : 'Assign SOP'}
                  </Button>
                  <Link to="/platform/sops" className="text-xs font-medium text-brand-700 hover:underline">
                    Manage templates →
                  </Link>
                </div>
                {!assignable && (
                  <p className="text-xs text-gray-500">
                    The SOP cannot be changed once the job is in progress or locked.
                  </p>
                )}
                {apply.isError && <Alert kind="error">{describeError(apply.error)}</Alert>}
                {notice && <p className="text-xs text-brand-700">{notice}</p>}
              </div>
            )}
          </div>

          <div className="space-y-2">
            <h3 className="text-xs font-semibold uppercase tracking-wide text-gray-500">Checklist progress</h3>
            {data.checklists.length === 0 ? (
              <p className="text-sm text-gray-500">No checklists attached to this SOP.</p>
            ) : (
              <Table headers={['Checklist', 'Required', 'Answered', '']}>
                {data.checklists.map((checklist) => (
                  <tr key={checklist.id}>
                    <td className="px-3 py-2 text-sm text-gray-800">
                      {checklist.name}
                      <span className="ml-1 text-xs text-gray-500">({formatStatusLabel(checklist.kind)})</span>
                    </td>
                    <td className="px-3 py-2 text-sm text-gray-600">{checklist.items_required}</td>
                    <td className="px-3 py-2 text-sm text-gray-600">{checklist.items_answered}</td>
                    <td className="px-3 py-2">
                      <Badge tone={checklist.items_answered >= checklist.items_required ? 'green' : 'amber'}>
                        {checklist.items_answered >= checklist.items_required ? 'Complete' : 'Pending'}
                      </Badge>
                    </td>
                  </tr>
                ))}
              </Table>
            )}
          </div>
        </div>

        <div>
          <h3 className="text-xs font-semibold uppercase tracking-wide text-gray-500">
            Team readiness ({data.assignments.length})
          </h3>
          {data.assignments.length === 0 ? (
            <p className="mt-2 text-sm text-gray-500">No offers or accepted assignments yet.</p>
          ) : (
            <div className="mt-2 overflow-x-auto">
              <Table headers={['Worker', 'Assignment', 'SOP', 'Checklist', 'Check-in', 'Attendance', 'Readiness']}>
                {data.assignments.map((assignment) => (
                  <tr key={assignment.id}>
                    <td className="px-3 py-2">
                      <p className="text-sm font-medium text-gray-900">{assignment.profile.name ?? 'Stock taker'}</p>
                      <p className="text-xs capitalize text-gray-500">
                        {assignment.role} · {assignment.profile.level ?? '—'}
                      </p>
                    </td>
                    <td className="px-3 py-2">
                      <Badge tone={assignment.status === 'accepted' ? 'green' : 'amber'}>
                        {formatStatusLabel(assignment.status)}
                      </Badge>
                    </td>
                    <td className="px-3 py-2 text-sm text-gray-600">
                      {assignment.acknowledged_at ? formatDateTime(assignment.acknowledged_at) : 'Pending'}
                    </td>
                    <td className="px-3 py-2 text-sm text-gray-600">
                      {assignment.checklist_answered}/{assignment.checklist_required}
                    </td>
                    <td className="px-3 py-2 text-sm text-gray-600">
                      {assignment.checkin?.checked_in_at ? formatDateTime(assignment.checkin.checked_in_at) : 'Not on site'}
                      {assignment.checkout?.checked_out_at && (
                        <p className="text-xs text-gray-500">
                          Out {formatDateTime(assignment.checkout.checked_out_at)}
                        </p>
                      )}
                    </td>
                    <td className="px-3 py-2 text-sm text-gray-600">
                      {assignment.attendance ? formatStatusLabel(assignment.attendance.status) : '—'}
                    </td>
                    <td className="px-3 py-2">
                      <div className="w-24">
                        <ProgressBar percent={assignment.readiness.percent} label="Worker readiness" />
                      </div>
                      <p className="mt-1 text-xs text-gray-500">{assignment.readiness.percent}%</p>
                    </td>
                  </tr>
                ))}
              </Table>
            </div>
          )}
        </div>

        <div className="grid gap-4 lg:grid-cols-2">
          <div>
            <h3 className="text-xs font-semibold uppercase tracking-wide text-gray-500">Progress timeline</h3>
            {data.progress.events.length === 0 ? (
              <p className="mt-2 text-sm text-gray-500">No progress events recorded yet.</p>
            ) : (
              <ol className="mt-2 space-y-3">
                {[...data.progress.events].reverse().map((event, index) => (
                  <li key={`${event.stage}-${event.occurred_at}-${index}`} className="flex gap-3 text-sm">
                    <span className="mt-1 h-2 w-2 shrink-0 rounded-full bg-brand-500" aria-hidden />
                    <div>
                      <p className="font-medium text-gray-900">{event.message ?? formatStatusLabel(event.stage)}</p>
                      <p className="text-xs text-gray-500">
                        {event.percent}% · {event.occurred_at ? formatDateTime(event.occurred_at) : '—'}
                      </p>
                    </div>
                  </li>
                ))}
              </ol>
            )}
          </div>
          <div>
            <h3 className="text-xs font-semibold uppercase tracking-wide text-gray-500">Attendance</h3>
            {data.attendance.length === 0 ? (
              <p className="mt-2 text-sm text-gray-500">No attendance records yet.</p>
            ) : (
              <Table headers={['Status', 'In', 'Out', 'Minutes']}>
                {data.attendance.map((attendance, index) => (
                  <tr key={attendance.id ?? index}>
                    <td className="px-3 py-2">
                      <Badge tone={attendance.status === 'present' ? 'green' : 'amber'}>
                        {formatStatusLabel(attendance.status)}
                      </Badge>
                    </td>
                    <td className="px-3 py-2 text-xs text-gray-600">
                      {attendance.checked_in_at ? formatDateTime(attendance.checked_in_at) : '—'}
                    </td>
                    <td className="px-3 py-2 text-xs text-gray-600">
                      {attendance.checked_out_at ? formatDateTime(attendance.checked_out_at) : '—'}
                    </td>
                    <td className="px-3 py-2 text-sm text-gray-600">{attendance.minutes_on_site ?? '—'}</td>
                  </tr>
                ))}
              </Table>
            )}
          </div>
        </div>

        <div className="flex flex-wrap gap-2 text-xs text-gray-500">
          {capability(data.procedure !== null, 'SOP')}
          {capability(data.summary.team_accepted >= data.summary.team_required, 'Team')}
          {capability(data.summary.can_start_count, 'Count gate')}
        </div>
      </div>
    </Card>
  )
}
