import { Alert, Badge, Card } from '../../components/ui'
import { formatDateTime, formatStatusLabel } from '../../lib/formatting'
import { useTenantJobOperations } from '../../lib/operations/hooks'
import { describeError, ProgressBar, StageBadge } from './shared'

export function TenantOperationsCard({
  tenantId,
  jobId,
  enabled,
}: {
  tenantId: string
  jobId: string
  enabled: boolean
}) {
  const operations = useTenantJobOperations(tenantId, jobId, enabled)

  if (!enabled) return null

  if (operations.isLoading) {
    return (
      <Card title="Field progress">
        <p className="text-sm text-gray-500">Loading field progress…</p>
      </Card>
    )
  }

  if (operations.isError || !operations.data) {
    return (
      <Card title="Field progress">
        <Alert kind="error">
          {operations.error ? describeError(operations.error) : 'Field progress is unavailable.'}
        </Alert>
      </Card>
    )
  }

  const data = operations.data

  return (
    <Card
      title="Field progress"
      actions={<StageBadge stage={data.summary.stage} />}
    >
      <div className="space-y-4 text-sm">
        <div>
          <div className="flex items-center justify-between text-xs text-gray-500">
            <span>Readiness</span>
            <span>{data.summary.percent}%</span>
          </div>
          <div className="mt-1">
            <ProgressBar percent={data.summary.percent} label="Field readiness" />
          </div>
          <p className="mt-1 text-xs text-gray-500">
            {data.summary.team_accepted}/{data.summary.team_required} stock takers accepted ·{' '}
            {data.summary.sop_acknowledged} SOP acknowledgement(s)
          </p>
        </div>

        <div>
          <p className="text-xs font-semibold uppercase tracking-wide text-gray-500">Job SOP</p>
          {!data.procedure ? (
            <p className="mt-1 text-gray-500">BRATHAMATU has not attached a procedure yet.</p>
          ) : (
            <div className="mt-1">
              <p className="font-medium text-gray-900">
                {data.procedure.title} <span className="text-xs text-gray-500">v{data.procedure.version}</span>
              </p>
              {data.procedure.summary && <p className="text-xs text-gray-500">{data.procedure.summary}</p>}
              <ol className="mt-1 list-decimal space-y-1 pl-5 text-gray-600">
                {data.procedure.steps.map((step) => (
                  <li key={step.position}>{step.title}</li>
                ))}
              </ol>
            </div>
          )}
        </div>

        <div>
          <p className="text-xs font-semibold uppercase tracking-wide text-gray-500">Team attendance</p>
          {data.team.length === 0 ? (
            <p className="mt-1 text-gray-500">No workers assigned yet.</p>
          ) : (
            <ul className="mt-1 space-y-2">
              {data.team.map((member, index) => (
                <li key={`${member.name ?? 'worker'}-${index}`} className="flex items-center justify-between gap-2">
                  <div>
                    <p className="text-gray-800">{member.name ?? 'Stock taker'}</p>
                    <p className="text-xs capitalize text-gray-500">
                      {member.role} · {formatStatusLabel(member.status)}
                    </p>
                  </div>
                  <div className="text-right text-xs text-gray-500">
                    {member.checked_in_at ? (
                      <>
                        <Badge tone={member.attendance_status === 'checked_out' ? 'gray' : 'green'}>
                          {member.attendance_status ? formatStatusLabel(member.attendance_status) : 'On site'}
                        </Badge>
                        <p className="mt-1">In {formatDateTime(member.checked_in_at)}</p>
                      </>
                    ) : (
                      <span>Not checked in</span>
                    )}
                  </div>
                </li>
              ))}
            </ul>
          )}
        </div>

        <div>
          <p className="text-xs font-semibold uppercase tracking-wide text-gray-500">Progress</p>
          {data.progress.events.length === 0 ? (
            <p className="mt-1 text-gray-500">No progress recorded yet.</p>
          ) : (
            <ol className="mt-1 space-y-2">
              {[...data.progress.events].reverse().map((event, index) => (
                <li key={`${event.stage}-${index}`} className="flex gap-2 text-xs">
                  <span className="mt-1 h-1.5 w-1.5 shrink-0 rounded-full bg-brand-500" aria-hidden />
                  <div>
                    <p className="text-gray-700">{event.message ?? formatStatusLabel(event.stage)}</p>
                    <p className="text-gray-500">
                      {event.percent}% · {event.occurred_at ? formatDateTime(event.occurred_at) : '—'}
                    </p>
                  </div>
                </li>
              ))}
            </ol>
          )}
        </div>
      </div>
    </Card>
  )
}
