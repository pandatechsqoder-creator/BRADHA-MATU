import { Link } from '@tanstack/react-router'
import { Badge, Card, PageHeader, PageLoader } from '../../components/ui'
import { useCurrentUser } from '../../lib/auth/hooks'
import { formatDateTime } from '../../lib/formatting'

export function ProfilePage() {
  const { data: user, isLoading } = useCurrentUser()

  if (isLoading) return <PageLoader />
  if (!user) return null

  return (
    <div className="mx-auto max-w-3xl space-y-6">
      <PageHeader title="Your profile" description="Account identity, roles and business access." />

      <Card title="Account">
        <dl className="space-y-3 text-sm">
          <div className="flex justify-between gap-4">
            <dt className="text-gray-500">Name</dt>
            <dd className="font-medium text-gray-900">{user.name}</dd>
          </div>
          <div className="flex justify-between gap-4">
            <dt className="text-gray-500">Email</dt>
            <dd className="font-medium text-gray-900">{user.email}</dd>
          </div>
          <div className="flex justify-between gap-4">
            <dt className="text-gray-500">Email verification</dt>
            <dd>
              {user.email_verified_at ? (
                <Badge tone="green">Verified</Badge>
              ) : (
                <Link to="/verify" className="text-sm font-medium text-brand-700 hover:underline">
                  Verify email →
                </Link>
              )}
            </dd>
          </div>
          <div className="flex justify-between gap-4">
            <dt className="text-gray-500">Member since</dt>
            <dd className="text-gray-900">{formatDateTime(user.created_at)}</dd>
          </div>
        </dl>
      </Card>

      <Card title="Roles">
        {user.roles.length === 0 ? (
          <p className="text-sm text-gray-500">No roles assigned yet.</p>
        ) : (
          <div className="flex flex-wrap gap-2">
            {user.roles.map((role) => (
              <Badge key={`${role.slug}-${role.tenant_id ?? 'platform'}`} tone={role.scope === 'platform' ? 'amber' : 'green'}>
                {role.name}
                {role.tenant_id ? ' · business' : ` · ${role.scope}`}
              </Badge>
            ))}
          </div>
        )}
      </Card>

      <Card title="Business access">
        {user.tenants.length === 0 ? (
          <p className="text-sm text-gray-500">
            You are not a member of a business yet.{' '}
            <Link to="/onboarding" className="font-medium text-brand-700 hover:underline">
              Create one
            </Link>
            .
          </p>
        ) : (
          <ul className="divide-y divide-gray-100">
            {user.tenants.map((tenant) => (
              <li key={tenant.id} className="flex items-center justify-between gap-4 py-3 text-sm">
                <div>
                  <p className="font-medium text-gray-900">{tenant.name}</p>
                  <p className="text-xs capitalize text-gray-500">
                    {tenant.operating_mode.replace('_', ' ')} · {tenant.status}
                  </p>
                </div>
                <div className="flex flex-wrap justify-end gap-1">
                  {tenant.roles.map((role) => (
                    <span key={role} className="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-700">
                      {role.replaceAll('-', ' ')}
                    </span>
                  ))}
                </div>
              </li>
            ))}
          </ul>
        )}
      </Card>
    </div>
  )
}
