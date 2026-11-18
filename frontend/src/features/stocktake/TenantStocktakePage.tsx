import { Link } from '@tanstack/react-router'
import { useState } from 'react'
import {
  Alert,
  Badge,
  Button,
  EmptyState,
  PageHeader,
  PageLoader,
  StatCard,
  Table,
} from '../../components/ui'
import { useCurrentUser } from '../../lib/auth/hooks'
import { formatDate, formatMoney } from '../../lib/formatting'
import { useTenantStocktakeJobs } from '../../lib/stocktake/hooks'
import { Pager, PriorityBadge, StatusBadge } from './shared'

const STATUS_OPTIONS = [
  ['', 'All statuses'],
  ['draft', 'Draft'],
  ['awaiting_deposit', 'Awaiting deposit'],
  ['deposit_pending_verification', 'Deposit pending verification'],
  ['submitted', 'Submitted'],
  ['under_review', 'Under review'],
  ['needs_tenant_information', 'Needs tenant information'],
  ['awaiting_quote_acceptance', 'Awaiting quote acceptance'],
  ['awaiting_balance_payment', 'Awaiting balance payment'],
  ['approved_for_execution', 'Approved for execution'],
  ['cancelled', 'Cancelled'],
]

export function TenantStocktakePage() {
  const { data: user, isLoading } = useCurrentUser()
  const tenantId = user?.current_tenant?.id
  const [status, setStatus] = useState('')
  const [priority, setPriority] = useState('')
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)

  const query = useTenantStocktakeJobs(tenantId, { status, priority, search, page })

  if (isLoading) return <PageLoader label="Loading stock-taking…" />
  if (!tenantId) return <Alert kind="error">Select a business before managing stock-taking requests.</Alert>

  const buckets = query.data?.buckets

  return (
    <div className="mx-auto max-w-7xl space-y-6">
      <PageHeader
        title="Stock-taking jobs"
        description="Request a stock take, follow BRATHAMATU review, pay the deposit and accept your quote."
        actions={
          <Link
            to="/business/stocktaking/new"
            className="inline-flex items-center rounded-lg bg-brand-700 px-4 py-2 text-sm font-medium text-white hover:bg-brand-800"
          >
            New stock-taking request
          </Link>
        }
      />

      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
        <StatCard label="Drafts" value={buckets?.drafts ?? 0} hint="Not yet submitted" />
        <StatCard label="Awaiting deposit" value={buckets?.awaiting_deposit ?? 0} hint="Payment or verification" />
        <StatCard label="With BRATHAMATU" value={buckets?.with_platform ?? 0} hint="Review and quoting" />
        <StatCard label="Active jobs" value={buckets?.active ?? 0} hint="Scheduled or counting" />
        <StatCard label="Closed" value={buckets?.closed ?? 0} hint="Completed or cancelled" />
      </div>

      <div className="flex flex-wrap items-end gap-3 rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
        <div>
          <label htmlFor="job-search" className="block text-xs font-medium text-gray-500">
            Search
          </label>
          <input
            id="job-search"
            value={search}
            onChange={(event) => {
              setSearch(event.target.value)
              setPage(1)
            }}
            placeholder="Reference, title or description"
            className="mt-1 rounded-lg border border-gray-300 px-3 py-2 text-sm"
          />
        </div>
        <div>
          <label htmlFor="job-status" className="block text-xs font-medium text-gray-500">
            Status
          </label>
          <select
            id="job-status"
            value={status}
            onChange={(event) => {
              setStatus(event.target.value)
              setPage(1)
            }}
            className="mt-1 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm"
          >
            {STATUS_OPTIONS.map(([value, label]) => (
              <option key={value} value={value}>
                {label}
              </option>
            ))}
          </select>
        </div>
        <div>
          <label htmlFor="job-priority" className="block text-xs font-medium text-gray-500">
            Priority
          </label>
          <select
            id="job-priority"
            value={priority}
            onChange={(event) => {
              setPriority(event.target.value)
              setPage(1)
            }}
            className="mt-1 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm"
          >
            <option value="">All priorities</option>
            <option value="low">Low</option>
            <option value="normal">Normal</option>
            <option value="high">High</option>
            <option value="critical">Critical</option>
          </select>
        </div>
        <Button
          variant="secondary"
          onClick={() => {
            setSearch('')
            setStatus('')
            setPriority('')
            setPage(1)
          }}
        >
          Reset
        </Button>
      </div>

      {query.isError && <Alert kind="error">Stock-taking requests could not be loaded.</Alert>}
      {query.isLoading && <PageLoader label="Loading requests…" />}

      {query.data && (
        <>
          {query.data.jobs.length === 0 ? (
            <EmptyState
              title="No stock-taking requests yet"
              description="Create your first request to get a provisional estimate and deposit requirement."
            />
          ) : (
            <Table
              headers={['Request', 'Scope', 'Status', 'Priority', 'Value', 'Deposit', 'Submitted', '']}
            >
              {query.data.jobs.map((job) => (
                <tr key={job.id} className="hover:bg-gray-50">
                  <td className="px-4 py-3">
                    <p className="font-medium text-gray-900">{job.title}</p>
                    <p className="text-xs text-gray-500">{job.reference}</p>
                  </td>
                  <td className="px-4 py-3 text-gray-600">{job.scope_label}</td>
                  <td className="px-4 py-3">
                    <StatusBadge status={job.status} label={job.status_label} />
                  </td>
                  <td className="px-4 py-3">
                    <PriorityBadge priority={job.priority} />
                  </td>
                  <td className="px-4 py-3 text-gray-700">
                    {formatMoney(job.quote_total ?? job.provisional_total, job.currency)}
                    <span className="block text-xs text-gray-500">{job.quote_total ? 'Quoted' : 'Provisional'}</span>
                  </td>
                  <td className="px-4 py-3 text-gray-700">
                    {job.deposit_required ? (
                      <>
                        {formatMoney(job.deposit_paid, job.currency)}
                        <span className="block text-xs text-gray-500">of {formatMoney(job.deposit_amount, job.currency)}</span>
                      </>
                    ) : (
                      <Badge tone="gray">Not required</Badge>
                    )}
                  </td>
                  <td className="px-4 py-3 text-gray-600">{job.submitted_at ? formatDate(job.submitted_at) : '—'}</td>
                  <td className="px-4 py-3 text-right">
                    <Link
                      to="/business/stocktaking/$jobId"
                      params={{ jobId: job.id }}
                      className="text-sm font-medium text-brand-700 hover:underline"
                    >
                      Open →
                    </Link>
                  </td>
                </tr>
              ))}
            </Table>
          )}
          <Pager meta={query.data.pagination} onChange={setPage} />
        </>
      )}
    </div>
  )
}
