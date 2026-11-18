import { Link } from '@tanstack/react-router'
import { Alert, Badge, Card, PageHeader, PageLoader, StatCard } from '../../components/ui'
import { ApiError } from '../../lib/api/client'
import { useCurrentUser } from '../../lib/auth/hooks'
import { useMarketingOverview } from '../../lib/marketing/hooks'

function formatNumber(value: number): string {
  return value.toLocaleString()
}

const shortcuts = [
  { to: '/platform/marketing/pages', label: 'Pages & publishing', description: 'Draft, review, schedule and publish public pages.' },
  { to: '/platform/marketing/media', label: 'Media library', description: 'Upload imagery, manage alt text and track usage.' },
  { to: '/platform/marketing/navigation', label: 'Navigation', description: 'Header and footer menus, CTAs and page links.' },
  { to: '/platform/marketing/content', label: 'Content library', description: 'Banners, CTAs, FAQs and approved testimonials.' },
  { to: '/platform/marketing/seo', label: 'Technical SEO', description: 'Redirects, indexing diagnostics and Core Web Vitals.' },
  { to: '/platform/marketing/blog', label: 'Blog & resources', description: 'Articles, downloads, categories and the editorial calendar.' },
  {
    to: '/platform/marketing/social',
    label: 'Social publishing',
    description: 'Draft, approve and publish Facebook and Instagram posts.',
    permission: 'social.publish',
  },
  {
    to: '/platform/marketing/campaigns',
    label: 'Campaigns',
    description: 'Plan budgets, audiences, UTMs and approval-gated activation.',
    permission: 'campaigns.view',
  },
  {
    to: '/platform/marketing/analytics',
    label: 'Analytics & reports',
    description: 'Consent-aware traffic, funnel, campaign and landing-page reporting.',
    permission: 'analytics.view',
  },
  {
    to: '/platform/marketing/automation',
    label: 'Automation',
    description: 'Event-driven lead follow-up, execution logs and the kill switch.',
    permission: 'automation.view',
  },
  {
    to: '/platform/marketing/integrations/google',
    label: 'Google integrations',
    description: 'Search Console, GA4, Business Profile and Tag Manager data.',
    permission: 'integrations.google.manage',
  },
  {
    to: '/platform/marketing/integrations/meta',
    label: 'Meta integrations',
    description: 'Pages, Instagram, insights and read-only ad reporting.',
    permission: 'integrations.meta.manage',
  },
]

export function MarketingOverviewPage() {
  const overview = useMarketingOverview()
  const { data: user } = useCurrentUser()
  const visibleShortcuts = shortcuts.filter(
    (shortcut) => !shortcut.permission || (user?.permissions.includes(shortcut.permission) ?? false),
  )

  if (overview.isLoading) return <PageLoader label="Loading marketing workspace…" />

  if (overview.isError) {
    return <Alert kind="error">{(overview.error as ApiError).message}</Alert>
  }

  const data = overview.data
  const statuses = data?.pages.by_status

  return (
    <div className="space-y-6">
      <PageHeader
        title="Marketing"
        description="Public website content, publishing workflow and media assets for the BRATHAMATU brand."
      />

      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <StatCard label="Pages" value={formatNumber(data?.pages.total ?? 0)} hint={`${data?.pages.live ?? 0} live`} />
        <StatCard
          label="Awaiting review"
          value={formatNumber((statuses?.review ?? 0) + (statuses?.approved ?? 0))}
          hint={`${statuses?.scheduled ?? 0} scheduled`}
        />
        <StatCard
          label="Media assets"
          value={formatNumber(data?.media.assets ?? 0)}
          hint={`${formatNumber(Math.round((data?.media.storage_bytes ?? 0) / 1024))} KB stored`}
        />
        <StatCard
          label="Testimonials"
          value={formatNumber(data?.content.testimonials ?? 0)}
          hint={`${data?.content.approved_testimonials ?? 0} approved`}
        />
      </div>

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <Card title="Publishing pipeline">
          <dl className="grid grid-cols-2 gap-3 text-sm sm:grid-cols-3">
            {Object.entries(data?.pages.by_status ?? {}).map(([status, count]) => (
              <div key={status} className="rounded-lg border border-gray-100 bg-gray-50 px-3 py-2">
                <dt className="text-xs capitalize text-gray-500">{status}</dt>
                <dd className="text-lg font-semibold text-gray-900">{count}</dd>
              </div>
            ))}
          </dl>
          <p className="mt-4 text-xs text-gray-500">
            Separation of duties is {data?.workflow.separation_of_duties ? 'enabled' : 'disabled'}:{' '}
            {data?.workflow.separation_of_duties
              ? 'an author cannot approve their own page.'
              : 'authors may approve their own pages.'}
          </p>
        </Card>

        <Card title="Content inventory">
          <ul className="space-y-2 text-sm text-gray-700">
            <li className="flex items-center justify-between border-b border-gray-100 pb-2">
              <span>Menus</span>
              <span className="font-medium">
                {data?.navigation.menus ?? 0} menus · {data?.navigation.menu_items ?? 0} items
              </span>
            </li>
            <li className="flex items-center justify-between border-b border-gray-100 pb-2">
              <span>Banners</span>
              <span className="font-medium">
                {data?.content.banners ?? 0} · {data?.content.active_banners ?? 0} active
              </span>
            </li>
            <li className="flex items-center justify-between border-b border-gray-100 pb-2">
              <span>Call-to-action buttons</span>
              <span className="font-medium">{data?.content.ctas ?? 0}</span>
            </li>
            <li className="flex items-center justify-between">
              <span>FAQ entries</span>
              <span className="font-medium">{data?.content.faqs ?? 0}</span>
            </li>
          </ul>
        </Card>
      </div>

      <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
        {visibleShortcuts.map((shortcut) => (
          <Link
            key={shortcut.to}
            to={shortcut.to}
            className="rounded-xl border border-gray-200 bg-white px-5 py-4 shadow-sm transition-colors hover:border-brand-300 hover:bg-brand-50"
          >
            <p className="text-sm font-semibold text-gray-900">{shortcut.label}</p>
            <p className="mt-1 text-xs text-gray-500">{shortcut.description}</p>
          </Link>
        ))}
      </div>

      <div className="flex flex-wrap gap-2 text-xs text-gray-500">
        <Badge tone="gray">Public site rendering is Blade-based</Badge>
        <Badge tone="gray">Preview links are signed and expire</Badge>
        <Badge tone="gray">Every transition is audited</Badge>
      </div>
    </div>
  )
}
