import { Link } from '@tanstack/react-router'
import { useState } from 'react'
import {
  Alert,
  Button,
  EmptyState,
  PageHeader,
  PageLoader,
  StatCard,
  Table,
} from '../../components/ui'
import { formatDate, formatMoney } from '../../lib/formatting'
import { usePlatformStocktakeJobs } from '../../lib/stocktake/hooks'
import { Pager, PriorityBadge, StatusBadge } from './shared'

const STATUS_OPTIONS = [
  ['', 'All statuses'],
  ['submitted', 'Submitted'],
  ['awaiting_deposit', 'Awaiting deposit'],
  ['deposit_pending_verification', 'Deposit pending verification'],
  ['under_review', 'Under review'],
  ['needs_tenant_information', 'Needs tenant information'],
  ['quote_issued', 'Quote issued'],
  ['awaiting_quote_acceptance', 'Awaiting quote acceptance'],
  ['awaiting_balance_payment', 'Awaiting balance payment'],
  ['approved_for_execution', 'Approved for execution'],
  ['cancelled', 'Cancelled'],
]

export function PlatformStocktakeJobsPage() {
  const [status, setStatus] = useState('')
  const [priority, setPriority] = useState('')
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const query = usePlatformStocktakeJobs({ status, priority, search, page })
  const buckets = query.data?.buckets

  return (
    <div className="mx-auto max-w-7xl space-y-6">
      <PageHeader
        title="Stock-taking jobs"
        description="Review requests, verify deposits and issue quotes that separate tenant charges from worker compensation."
        actions={
          <Link to="/platform/stocktake-policy" className="text-sm font-medium text-brand-700 hover:underline">
            Stocktake pricing & deposit policy →
          </Link>
        }
      />

      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-6">
        <StatCard label="New requests" value={buckets?.new_requests ?? 0} hint="Submitted to BRATHAMATU" />
        <StatCard label="Awaiting deposit" value={buckets?.awaiting_deposit ?? 0} />
        <StatCard label="Under review" value={buckets?.under_review ?? 0} />
        <StatCard label="Quotes pending" value={buckets?.quotes_pending ?? 0} />
        <StatCard label="Ready to execute" value={buckets?.ready_to_execute ?? 0} />
        <StatCard label="Active jobs" value={buckets?.active_jobs ?? 0} />
      </div>

      <div className="flex flex-wrap items-end gap-3 rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
        <div>
          <label htmlFor="platform-job-search" className="block text-xs font-medium text-gray-500">
            Search
          </label>
          <input
            id="platform-job-search"
            value={search}
            onChange={(event) => {
              setSearch(event.target.value)
              setPage(1)
            }}
            placeholder="Reference, title or tenant"
            className="mt-1 rounded-lg border border-gray-300 px-3 py-2 text-sm"
          />
        </div>
        <div>
          <label htmlFor="platform-job-status" className="block text-xs font-medium text-gray-500">
            Status
          </label>
          <select
            id="platform-job-status"
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
          <label htmlFor="platform-job-priority" className="block text-xs font-medium text-gray-500">
            Priority
          </label>
          <select
            id="platform-job-priority"
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

      {query.isError && <Alert kind="error">Stock-taking jobs could not be loaded.</Alert>}
      {query.isLoading && <PageLoader label="Loading job queue…" />}

      {query.data && (
        <>
          {query.data.jobs.length === 0 ? (
            <EmptyState title="No jobs in this view" description="Adjust the filters or wait for tenants to submit requests." />
          ) : (
            <Table
              headers={['Job', 'Tenant', 'Scope', 'Status', 'Priority', 'Value', 'Deposit', 'Submitted', '']}
            >
              {query.data.jobs.map((job) => (
                <tr key={job.id} className="hover:bg-gray-50">
                  <td className="px-4 py-3">
                    <p className="font-medium text-gray-900">{job.title}</p>
                    <p className="text-xs text-gray-500">{job.reference}</p>
                  </td>
                  <td className="px-4 py-3">
                    <p className="text-gray-700">{job.tenant?.name ?? '—'}</p>
                    <p className="text-xs text-gray-500">{job.requester?.name ?? ''}</p>
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
                    {formatMoney(job.deposit_paid, job.currency)}
                    <span className="block text-xs text-gray-500">of {formatMoney(job.deposit_amount, job.currency)}</span>
                  </td>
                  <td className="px-4 py-3 text-gray-600">{job.submitted_at ? formatDate(job.submitted_at) : '—'}</td>
                  <td className="px-4 py-3 text-right">
                    <Link
                      to="/platform/stocktaking-jobs/$jobId"
                      params={{ jobId: job.id }}
                      className="text-sm font-medium text-brand-700 hover:underline"
                    >
                      Review →
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
