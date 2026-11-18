import { Link } from '@tanstack/react-router'
import { Alert, Card, PageHeader, PageLoader, StatCard } from '../../components/ui'
import { useCurrentUser, usePlatformOverview } from '../../lib/auth/hooks'
import { publicSiteUrl } from '../../lib/publicSite'

export function PlatformOverviewPage() {
  const overview = usePlatformOverview()
  const { data: user } = useCurrentUser()
  const canManageWebsite = user?.permissions.includes('cms.view') ?? false

  if (overview.isLoading) return <PageLoader label="Loading platform overview…" />
  if (overview.isError) {
    return <Alert kind="error">Platform overview is unavailable. Check your platform role.</Alert>
  }

  const data = overview.data!

  return (
    <div className="mx-auto max-w-6xl space-y-6">
      <PageHeader
        title="Operations overview"
        description="Marketplace-wide tenant, identity and audit signals for BRATHAMATU operations."
      />

      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <StatCard
          label="Tenants"
          value={data.tenants.total}
          hint={`${data.tenants.active} active · ${data.tenants.pending} pending · ${data.tenants.suspended} suspended`}
        />
        <StatCard label="Users" value={data.users.total} hint={`${data.users.verified} verified`} />
        <StatCard label="Active memberships" value={data.memberships.active} />
        <StatCard label="Audit events (7 days)" value={data.audit_events_7d} />
      </div>

      <div className="grid gap-4 lg:grid-cols-2">
        <Card title="Tenant directory">
          <p className="text-sm text-gray-600">
            Inspect organisations, their operating mode, team members and access status.
          </p>
          <Link to="/platform/tenants" className="mt-4 inline-block text-sm font-medium text-brand-700 hover:underline">
            Open tenant directory →
          </Link>
        </Card>
        <Card title="Audit trail">
          <p className="text-sm text-gray-600">
            Every login, tenant change and privileged action is recorded with request IDs.
          </p>
          <Link to="/platform/audit" className="mt-4 inline-block text-sm font-medium text-brand-700 hover:underline">
            View audit events →
          </Link>
        </Card>
        {canManageWebsite && (
          <Card title="Public website">
            <p className="text-sm text-gray-600">
              Manage the BRATHAMATU public site: page content, publishing workflow, media, navigation
              and testimonials.
            </p>
            <div className="mt-4 flex flex-wrap items-center gap-4">
              <Link
                to="/platform/marketing"
                className="text-sm font-medium text-brand-700 hover:underline"
              >
                Open marketing workspace →
              </Link>
              <a
                href={publicSiteUrl()}
                target="_blank"
                rel="noreferrer"
                className="text-sm font-medium text-brand-700 hover:underline"
              >
                View public website ↗
              </a>
            </div>
          </Card>
        )}
      </div>

      <Card title="Phase progress">
        <p className="text-sm text-gray-600">
          Identity, tenancy and isolation are live. Catalogue, jobs, payments, workforce and POS
          modules follow the phases in the master implementation document and are browser-tested as
          they land.
        </p>
      </Card>
    </div>
  )
}
