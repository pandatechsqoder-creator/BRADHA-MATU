import { Link } from '@tanstack/react-router'
import { useState } from 'react'
import { Alert, Badge, Button, PageHeader, PageLoader, Table } from '../../components/ui'
import { usePlatformTenants } from '../../lib/auth/hooks'
import { formatDate, formatOperatingMode } from '../../lib/formatting'

export function PlatformTenantsPage() {
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('')
  const [page, setPage] = useState(1)
  const query = usePlatformTenants({ search, status, page })

  return (
    <div className="mx-auto max-w-6xl space-y-6">
      <PageHeader
        title="Tenants"
        description="All organisations registered on the platform, with member and branch counts."
      />

      <div className="flex flex-wrap items-end gap-3 rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
        <div>
          <label htmlFor="tenant-search" className="block text-xs font-medium text-gray-500">
            Search
          </label>
          <input
            id="tenant-search"
            value={search}
            onChange={(event) => {
              setSearch(event.target.value)
              setPage(1)
            }}
            placeholder="Name or email"
            className="mt-1 rounded-lg border border-gray-300 px-3 py-2 text-sm"
          />
        </div>
        <div>
          <label htmlFor="tenant-status" className="block text-xs font-medium text-gray-500">
            Status
          </label>
          <select
            id="tenant-status"
            value={status}
            onChange={(event) => {
              setStatus(event.target.value)
              setPage(1)
            }}
            className="mt-1 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm"
          >
            <option value="">All statuses</option>
            <option value="active">Active</option>
            <option value="pending">Pending</option>
            <option value="suspended">Suspended</option>
          </select>
        </div>
      </div>

      {query.isError && <Alert kind="error">Could not load tenants.</Alert>}
      {query.isLoading && <PageLoader label="Loading tenants…" />}

      {query.data && (
        <>
          <Table headers={['Organisation', 'Industry', 'Status', 'Operating mode', 'Members', 'Branches', 'Created']}>
            {query.data.tenants.map((tenant) => (
              <tr key={tenant.id} className="hover:bg-gray-50">
                <td className="px-4 py-3">
                  <Link
                    to="/platform/tenants/$tenantId"
                    params={{ tenantId: tenant.id }}
                    className="font-medium text-brand-700 hover:underline"
                  >
                    {tenant.name}
                  </Link>
                  <p className="text-xs text-gray-500">{tenant.email ?? 'No contact email'}</p>
                </td>
                <td className="px-4 py-3 text-gray-600">{tenant.industry ?? '—'}</td>
                <td className="px-4 py-3">
                  <Badge tone={tenant.status === 'active' ? 'green' : tenant.status === 'pending' ? 'amber' : 'red'}>
                    {tenant.status}
                  </Badge>
                </td>
                <td className="px-4 py-3 text-gray-600">{formatOperatingMode(tenant.operating_mode)}</td>
                <td className="px-4 py-3 text-gray-600">{tenant.members_count ?? '—'}</td>
                <td className="px-4 py-3 text-gray-600">{tenant.branches_count ?? '—'}</td>
                <td className="px-4 py-3 text-gray-500">{tenant.created_at ? formatDate(tenant.created_at) : '—'}</td>
              </tr>
            ))}
          </Table>

          {query.data.tenants.length === 0 && (
            <Alert kind="info">No tenants match the current filters.</Alert>
          )}

          <div className="flex items-center justify-between text-sm text-gray-600">
            <span>
              Page {query.data.pagination.current_page} of {query.data.pagination.last_page} ·{' '}
              {query.data.pagination.total} tenants
            </span>
            <div className="flex gap-2">
              <Button
                variant="secondary"
                disabled={query.data.pagination.current_page <= 1}
                onClick={() => setPage((current) => Math.max(1, current - 1))}
              >
                Previous
              </Button>
              <Button
                variant="secondary"
                disabled={query.data.pagination.current_page >= query.data.pagination.last_page}
                onClick={() => setPage((current) => current + 1)}
              >
                Next
              </Button>
            </div>
          </div>
        </>
      )}
    </div>
  )
}
