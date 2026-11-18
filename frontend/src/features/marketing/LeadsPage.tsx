import { Link } from '@tanstack/react-router'
import { useState } from 'react'
import { Alert, Badge, Button, EmptyState, Field, PageHeader, PageLoader, Select, StatCard, Table } from '../../components/ui'
import { ApiError } from '../../lib/api/client'
import { useCurrentUser } from '../../lib/auth/hooks'
import { formatDateTime } from '../../lib/formatting'
import {
  LEAD_STATUSES,
  LEAD_TYPES,
  downloadLeadsCsv,
  leadStatusLabels,
  leadTypeLabels,
  useLeads,
  type LeadStatus,
} from '../../lib/marketing/leadHooks'

const statusTone: Record<LeadStatus, 'gray' | 'green' | 'red' | 'amber'> = {
  new: 'amber',
  assigned: 'gray',
  contacted: 'gray',
  qualified: 'green',
  quote_sent: 'green',
  follow_up: 'amber',
  won: 'green',
  lost: 'red',
  archived: 'gray',
}

export function LeadsPage() {
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('')
  const [type, setType] = useState('')
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')
  const [exportError, setExportError] = useState<string | null>(null)
  const [exporting, setExporting] = useState(false)

  const { data: user } = useCurrentUser()
  const canExport = user?.permissions.includes('leads.export') ?? false
  const canManage = user?.permissions.includes('leads.manage') ?? false
  const filters = { search, status, type, from, to }
  const leads = useLeads(filters)

  async function exportCsv() {
    setExportError(null)
    setExporting(true)
    try {
      await downloadLeadsCsv(filters)
    } catch (error) {
      setExportError(error instanceof ApiError ? error.message : 'Could not export leads.')
    } finally {
      setExporting(false)
    }
  }

  const total = leads.data?.pagination.total ?? 0
  const counts = leads.data?.status_counts ?? {}
  const newCount = counts.new ?? 0
  const openCount = LEAD_STATUSES.filter((value) => !['won', 'lost', 'archived'].includes(value)).reduce(
    (sum, value) => sum + (counts[value] ?? 0),
    0,
  )
  const wonCount = counts.won ?? 0

  return (
    <div className="space-y-6">
      <PageHeader
        title="Leads"
        description="Website quotation, demonstration, contact, callback and partnership submissions."
        actions={
          canExport ? (
            <Button variant="secondary" loading={exporting} onClick={exportCsv}>
              Export CSV
            </Button>
          ) : (
            <p className="text-xs text-gray-500">Export requires the leads export permission.</p>
          )
        }
      />

      {exportError && <Alert kind="error">{exportError}</Alert>}
      {leads.isError && (
        <Alert kind="error">{leads.error instanceof ApiError ? leads.error.message : 'Could not load leads.'}</Alert>
      )}

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <StatCard label="All leads" value={total} hint="Matching the current filters" />
        <StatCard label="New" value={newCount} hint="Not yet picked up" />
        <StatCard label="Open pipeline" value={openCount} hint="New through follow-up" />
        <StatCard label="Won" value={wonCount} hint="Converted enquiries" />
      </div>

      <div className="grid grid-cols-1 gap-4 rounded-xl border border-gray-200 bg-white px-5 py-4 shadow-sm md:grid-cols-2 xl:grid-cols-5">
        <Field label="Search" name="lead-search" value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Reference, business, contact, email or phone" />
        <Select label="Status" name="lead-status" value={status} onChange={(event) => setStatus(event.target.value)}>
          <option value="">All statuses</option>
          {LEAD_STATUSES.map((value) => (
            <option key={value} value={value}>
              {leadStatusLabels[value]}
            </option>
          ))}
        </Select>
        <Select label="Form type" name="lead-type" value={type} onChange={(event) => setType(event.target.value)}>
          <option value="">All types</option>
          {LEAD_TYPES.map((value) => (
            <option key={value} value={value}>
              {leadTypeLabels[value]}
            </option>
          ))}
        </Select>
        <Field label="From" name="lead-from" type="date" value={from} onChange={(event) => setFrom(event.target.value)} />
        <Field label="To" name="lead-to" type="date" value={to} onChange={(event) => setTo(event.target.value)} />
      </div>

      {leads.isLoading ? (
        <PageLoader label="Loading leads…" />
      ) : (
        <Table
          headers={['Reference', 'Contact', 'Form', 'Status', 'Attribution', 'Assigned to', 'Received', '']}
          empty={<EmptyState title="No leads match these filters" description="Try clearing the search or date range." />}
        >
          {(leads.data?.leads ?? []).map((lead) => (
            <tr key={lead.id} className="hover:bg-gray-50" data-testid="lead-row">
              <td className="whitespace-nowrap px-4 py-3">
                <p className="font-medium text-gray-900">{lead.reference}</p>
                <p className="text-xs text-gray-500">{lead.source}</p>
              </td>
              <td className="px-4 py-3">
                <p className="font-medium text-gray-900">{lead.business_name ?? lead.contact_name}</p>
                <p className="text-xs text-gray-500">
                  {lead.contact_name}
                  {lead.email ? ` · ${lead.email}` : ''}
                </p>
                {lead.phone && <p className="text-xs text-gray-500">{lead.phone}</p>}
              </td>
              <td className="whitespace-nowrap px-4 py-3">
                <Badge tone="gray">{leadTypeLabels[lead.type] ?? lead.type}</Badge>
              </td>
              <td className="whitespace-nowrap px-4 py-3">
                <Badge tone={statusTone[lead.status] ?? 'gray'}>{leadStatusLabels[lead.status] ?? lead.status}</Badge>
              </td>
              <td className="px-4 py-3 text-xs text-gray-600">
                {lead.utm_source || lead.utm_campaign ? (
                  <>
                    <p>{[lead.utm_source, lead.utm_medium].filter(Boolean).join(' / ') || '—'}</p>
                    <p className="text-gray-500">{lead.utm_campaign ?? 'No campaign'}</p>
                  </>
                ) : (
                  'Direct'
                )}
              </td>
              <td className="whitespace-nowrap px-4 py-3 text-sm text-gray-700">
                {lead.assigned_to?.name ?? 'Unassigned'}
              </td>
              <td className="whitespace-nowrap px-4 py-3 text-xs text-gray-500">
                {lead.created_at ? formatDateTime(lead.created_at) : '—'}
              </td>
              <td className="whitespace-nowrap px-4 py-3 text-right">
                <Link
                  to="/platform/marketing/leads/$leadId"
                  params={{ leadId: lead.id }}
                  className="text-sm font-medium text-brand-700 hover:underline"
                >
                  {canManage ? 'Work lead' : 'View'}
                </Link>
              </td>
            </tr>
          ))}
        </Table>
      )}
    </div>
  )
}
