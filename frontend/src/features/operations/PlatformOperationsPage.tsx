import { Link } from '@tanstack/react-router'
import { useMemo, useState } from 'react'
import { Alert, Badge, Card, EmptyState, Field, PageHeader, PageLoader, StatCard, Table } from '../../components/ui'
import { formatDateTime } from '../../lib/formatting'
import { usePlatformOperationsDashboard } from '../../lib/operations/hooks'
import { describeError, ProgressBar, StageBadge } from './shared'

export function PlatformOperationsPage() {
  const dashboard = usePlatformOperationsDashboard()
  const [search, setSearch] = useState('')

  const rows = useMemo(() => {
    const jobs = dashboard.data?.jobs ?? []
    const term = search.trim().toLowerCase()
    if (!term) return jobs
    return jobs.filter((row) =>
      [row.job.reference, row.job.title, row.job.tenant ?? '', row.progress.stage]
        .join(' ')
        .toLowerCase()
        .includes(term),
    )
  }, [dashboard.data?.jobs, search])

  if (dashboard.isLoading) return <PageLoader label="Loading live operations…" />
  if (dashboard.isError || !dashboard.data) {
    return (
      <div className="mx-auto max-w-3xl space-y-4">
        <PageHeader title="Field operations" />
        <Alert kind="error">
          {dashboard.error ? describeError(dashboard.error) : 'Live operations are unavailable.'}
        </Alert>
      </div>
    )
  }

  const data = dashboard.data

  return (
    <div className="mx-auto max-w-7xl space-y-6">
      <PageHeader
        title="Field operations"
        description="Live readiness, attendance and SOP progress for every job in execution. Refreshes automatically every 30 seconds."
        actions={<Badge tone="gray">Updated {formatDateTime(data.generated_at)}</Badge>}
      />

      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-6">
        <StatCard label="Active jobs" value={data.summary.active_jobs} />
        <StatCard label="Counting" value={data.summary.in_progress} hint="Jobs in progress" />
        <StatCard label="Awaiting check-in" value={data.summary.awaiting_check_in} />
        <StatCard label="Unassigned" value={data.summary.unassigned} />
        <StatCard label="SOP pending" value={data.summary.sop_pending} hint="No acknowledgement yet" />
        <StatCard label="Overdue" value={data.summary.overdue} />
      </div>

      <Card
        title="Job readiness"
        actions={
          <div className="w-64">
            <Field
              label="Filter"
              name="operations-search"
              placeholder="Reference, tenant or stage"
              value={search}
              onChange={(event) => setSearch(event.target.value)}
            />
          </div>
        }
      >
        {rows.length === 0 ? (
          <EmptyState
            title="No jobs in execution"
            description="Approved jobs appear here once they reach the field-operations stages."
          />
        ) : (
          <div className="overflow-x-auto">
            <Table
              headers={[
                'Job',
                'Stage',
                'Team',
                'Checklist',
                'Progress',
                'Next action',
                'Last activity',
              ]}
            >
              {rows.map((row) => (
                <tr key={row.job.id}>
                  <td className="px-4 py-3">
                    <Link
                      to="/platform/stocktaking-jobs/$jobId"
                      params={{ jobId: row.job.id }}
                      className="font-medium text-brand-700 hover:underline"
                    >
                      {row.job.reference}
                    </Link>
                    <p className="text-xs text-gray-500">
                      {row.job.title} · {row.job.tenant ?? 'Tenant'}
                    </p>
                  </td>
                  <td className="px-4 py-3">
                    <div className="flex flex-col items-start gap-1">
                      <StageBadge stage={row.progress.stage} />
                      {row.is_overdue && <Badge tone="red">Overdue</Badge>}
                    </div>
                  </td>
                  <td className="px-4 py-3 text-sm text-gray-600">
                    {row.team.accepted}/{row.team.required} accepted
                    <p className="text-xs text-gray-500">{row.team.checked_in} checked in</p>
                  </td>
                  <td className="px-4 py-3 text-sm text-gray-600">
                    {row.checklists.required === 0 ? '—' : `${row.checklists.answered}/${row.checklists.required}`}
                    {row.checklists.complete && row.checklists.required > 0 && (
                      <p className="text-xs text-green-700">Complete</p>
                    )}
                  </td>
                  <td className="px-4 py-3">
                    <div className="w-32">
                      <ProgressBar percent={row.progress.percent} label={`${row.job.reference} readiness`} />
                    </div>
                    <p className="mt-1 text-xs text-gray-500">{row.progress.percent}%</p>
                  </td>
                  <td className="px-4 py-3 text-sm text-gray-600">{row.next_action ?? 'Ready'}</td>
                  <td className="px-4 py-3 text-xs text-gray-500">
                    {row.last_activity_at ? formatDateTime(row.last_activity_at) : '—'}
                  </td>
                </tr>
              ))}
            </Table>
          </div>
        )}
      </Card>
    </div>
  )
}
