import { useState } from 'react'
import { Alert, Button, EmptyState, PageHeader, PageLoader, Table } from '../../components/ui'
import { useAuditLogs } from '../../lib/auth/hooks'
import { formatDateTime } from '../../lib/formatting'

export function PlatformAuditPage() {
  const [action, setAction] = useState('')
  const [page, setPage] = useState(1)
  const query = useAuditLogs({ action, page })

  return (
    <div className="mx-auto max-w-6xl space-y-6">
      <PageHeader
        title="Audit trail"
        description="Authentication, tenancy and privileged actions with actor, tenant and request correlation."
      />

      <div className="flex flex-wrap items-end gap-3 rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
        <div>
          <label htmlFor="audit-action" className="block text-xs font-medium text-gray-500">
            Filter action
          </label>
          <input
            id="audit-action"
            value={action}
            onChange={(event) => {
              setAction(event.target.value)
              setPage(1)
            }}
            placeholder="e.g. tenant or auth.login"
            className="mt-1 w-64 rounded-lg border border-gray-300 px-3 py-2 text-sm"
          />
        </div>
      </div>

      {query.isError && <Alert kind="error">Could not load audit events.</Alert>}
      {query.isLoading && <PageLoader label="Loading audit events…" />}

      {query.data && (
        <>
          {query.data.logs.length === 0 ? (
            <EmptyState title="No audit events" description="Events appear as users sign in and change tenant data." />
          ) : (
            <Table headers={['When', 'Action', 'Actor', 'Tenant', 'Subject', 'Request']}>
              {query.data.logs.map((log) => (
                <tr key={log.id} className="hover:bg-gray-50">
                  <td className="whitespace-nowrap px-4 py-3 text-gray-600">
                    {log.created_at ? formatDateTime(log.created_at) : '—'}
                  </td>
                  <td className="px-4 py-3 font-medium text-gray-900">{log.action}</td>
                  <td className="px-4 py-3 text-gray-600">{log.actor ?? 'System'}</td>
                  <td className="px-4 py-3 text-gray-600">{log.tenant ?? 'Platform'}</td>
                  <td className="px-4 py-3 text-gray-500">
                    {log.subject_type ? `${log.subject_type.split('\\').pop()} #${log.subject_id}` : '—'}
                  </td>
                  <td className="px-4 py-3 font-mono text-xs text-gray-500">{log.request_id ?? '—'}</td>
                </tr>
              ))}
            </Table>
          )}

          <div className="flex items-center justify-between text-sm text-gray-600">
            <span>
              Page {query.data.pagination.current_page} of {query.data.pagination.last_page} ·{' '}
              {query.data.pagination.total} events
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
