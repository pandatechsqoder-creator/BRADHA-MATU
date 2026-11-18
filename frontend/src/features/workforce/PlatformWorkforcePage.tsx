import { useState } from 'react'
import { Link } from '@tanstack/react-router'
import { Badge, Card, Field, PageHeader, PageLoader, Select, StatCard, Table } from '../../components/ui'
import type { StockTakerSummary } from '../../lib/api/workforce'
import { formatDate, formatStatusLabel } from '../../lib/formatting'
import { useStockTakers } from '../../lib/workforce/hooks'

const LEVELS = ['applicant', 'trainee', 'verified', 'professional', 'expert', 'senior_supervisor']
const VERIFICATION_STATES = ['unverified', 'pending', 'verified', 'rejected']

function verificationTone(status: string): 'green' | 'amber' | 'red' | 'gray' {
  if (status === 'verified') return 'green'
  if (status === 'rejected') return 'red'
  if (status === 'pending') return 'amber'
  return 'gray'
}

export function PlatformWorkforcePage() {
  const [filters, setFilters] = useState({ search: '', level: '', verification_status: '' })
  const stockTakers = useStockTakers({
    search: filters.search || undefined,
    level: filters.level || undefined,
    verification_status: filters.verification_status || undefined,
  })

  return (
    <div className="mx-auto max-w-7xl space-y-6">
      <PageHeader
        title="Workforce"
        description="Verify stock takers, manage progression and keep skills, service areas and availability current."
      />

      <div className="grid gap-4 sm:grid-cols-3">
        <StatCard label="Profiles" value={stockTakers.data?.meta.total ?? '—'} />
        <StatCard label="Awaiting verification" value={stockTakers.data?.meta.pending_verification ?? '—'} />
        <StatCard label="Verified" value={stockTakers.data?.meta.counts?.verified ?? '—'} />
      </div>

      <Card title="Stock takers">
        <div className="mb-4 grid gap-3 sm:grid-cols-3">
          <Field
            label="Search"
            name="search"
            placeholder="Name, email, phone or city"
            value={filters.search}
            onChange={(event) => setFilters({ ...filters, search: event.target.value })}
          />
          <Select
            label="Level"
            name="level"
            value={filters.level}
            onChange={(event) => setFilters({ ...filters, level: event.target.value })}
          >
            <option value="">All levels</option>
            {LEVELS.map((level) => (
              <option key={level} value={level}>
                {formatStatusLabel(level)}
              </option>
            ))}
          </Select>
          <Select
            label="Verification"
            name="verification"
            value={filters.verification_status}
            onChange={(event) => setFilters({ ...filters, verification_status: event.target.value })}
          >
            <option value="">All verification states</option>
            {VERIFICATION_STATES.map((state) => (
              <option key={state} value={state}>
                {formatStatusLabel(state)}
              </option>
            ))}
          </Select>
        </div>

        {stockTakers.isLoading ? (
          <PageLoader label="Loading workforce…" />
        ) : (
          <Table headers={['Stock taker', 'Level', 'Verification', 'Training', 'Workload', 'Accuracy', '']}>
            {(stockTakers.data?.profiles ?? []).map((profile: StockTakerSummary) => (
              <tr key={profile.id}>
                <td className="px-4 py-3">
                  <p className="font-medium text-gray-900">{profile.legal_name ?? '—'}</p>
                  <p className="text-xs text-gray-500">
                    {profile.user?.email ?? ''} {profile.city ? `· ${profile.city}` : ''}
                  </p>
                </td>
                <td className="px-4 py-3 text-gray-600">{profile.level_label}</td>
                <td className="px-4 py-3">
                  <Badge tone={verificationTone(profile.verification_status)}>
                    {formatStatusLabel(profile.verification_status)}
                  </Badge>
                </td>
                <td className="px-4 py-3">
                  <Badge tone={profile.training_completed ? 'green' : 'amber'}>
                    {profile.training_completed ? 'Completed' : 'Pending'}
                  </Badge>
                </td>
                <td className="px-4 py-3 text-gray-600">
                  {profile.availability_windows} window(s) · {profile.service_areas.length} area(s)
                </td>
                <td className="px-4 py-3 text-gray-600">
                  {profile.verified_accuracy != null ? `${profile.verified_accuracy}%` : '—'}
                </td>
                <td className="px-4 py-3 text-right">
                  <Link
                    to="/platform/workforce/$profileId"
                    params={{ profileId: profile.id }}
                    className="text-sm font-medium text-brand-700 hover:underline"
                  >
                    Review →
                  </Link>
                  <p className="text-xs text-gray-500">Joined {profile.created_at ? formatDate(profile.created_at) : '—'}</p>
                </td>
              </tr>
            ))}
          </Table>
        )}

        {(stockTakers.data?.profiles.length ?? 0) === 0 && !stockTakers.isLoading && (
          <p className="mt-4 text-sm text-gray-500">No stock takers match these filters.</p>
        )}
      </Card>
    </div>
  )
}
