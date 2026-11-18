import { Link, useParams } from '@tanstack/react-router'
import { Badge, Card, EmptyState, PageHeader, PageLoader } from '../../components/ui'
import { useMembers, usePlatformTenant } from '../../lib/auth/hooks'
import { formatDateTime, formatOperatingMode } from '../../lib/formatting'

export function PlatformTenantDetailPage() {
  const params = useParams({ strict: false }) as { tenantId?: string }
  const tenant = usePlatformTenant(params.tenantId)
  const members = useMembers(params.tenantId)

  if (tenant.isLoading) return <PageLoader label="Loading tenant…" />
  if (tenant.isError || !tenant.data) {
    return (
      <div className="mx-auto max-w-3xl space-y-4">
        <EmptyState title="Tenant not found" description="The organisation may have been removed or the link is invalid." />
        <Link to="/platform/tenants" className="text-sm font-medium text-brand-700 hover:underline">
          ← Back to tenants
        </Link>
      </div>
    )
  }

  const data = tenant.data

  return (
    <div className="mx-auto max-w-4xl space-y-6">
      <div>
        <Link to="/platform/tenants" className="text-sm font-medium text-brand-700 hover:underline">
          ← Tenants
        </Link>
      </div>
      <PageHeader
        title={data.name}
        description={data.industry ?? 'Industry not supplied'}
        actions={<Badge tone={data.status === 'active' ? 'green' : data.status === 'pending' ? 'amber' : 'red'}>{data.status}</Badge>}
      />

      <div className="grid gap-4 lg:grid-cols-2">
        <Card title="Organisation profile">
          <dl className="space-y-3 text-sm">
            {[
              ['Legal name', data.legal_name ?? '—'],
              ['Business type', data.business_type ?? '—'],
              ['Email', data.email ?? '—'],
              ['Phone', data.phone ?? '—'],
              ['Address', data.address ?? '—'],
              ['Timezone', data.timezone],
              ['Operating mode', formatOperatingMode(data.operating_mode)],
            ].map(([label, value]) => (
              <div key={label} className="flex justify-between gap-4">
                <dt className="text-gray-500">{label}</dt>
                <dd className="text-right font-medium text-gray-900">{value}</dd>
              </div>
            ))}
          </dl>
        </Card>

        <Card title={`Team members (${members.data?.length ?? 0})`}>
          {members.isLoading && <PageLoader label="Loading members…" />}
          {members.data?.length === 0 && <EmptyState title="No members" />}
          <ul className="divide-y divide-gray-100">
            {members.data?.map((member) => (
              <li key={member.id} className="flex items-start justify-between gap-3 py-3 text-sm">
                <div>
                  <p className="font-medium text-gray-900">{member.name}</p>
                  <p className="text-xs text-gray-500">{member.email}</p>
                  <p className="text-xs text-gray-500">
                    Joined {member.joined_at ? formatDateTime(member.joined_at) : '—'}
                  </p>
                </div>
                <div className="flex flex-wrap justify-end gap-1">
                  {member.roles.map((role) => (
                    <Badge key={role.slug} tone="green">
                      {role.name}
                    </Badge>
                  ))}
                </div>
              </li>
            ))}
          </ul>
        </Card>
      </div>
    </div>
  )
}
