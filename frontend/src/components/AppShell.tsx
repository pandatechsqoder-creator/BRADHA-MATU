import { Link, useRouter } from '@tanstack/react-router'
import type { ReactNode } from 'react'
import { useCurrentUser, useLogout, useSwitchTenant } from '../lib/auth/hooks'
import { formatOperatingMode } from '../lib/formatting'
import { publicSiteUrl } from '../lib/publicSite'
import { Badge, Button, PageLoader } from './ui'

function initials(name: string): string {
  return name
    .split(' ')
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase())
    .join('')
}

function NavItem({ to, children }: { to: string; children: ReactNode }) {
  return (
    <Link
      to={to}
      className="rounded-lg px-3 py-2 text-sm text-gray-300 transition-colors hover:bg-white/10 hover:text-white [&.active]:bg-brand-700 [&.active]:text-white"
    >
      {children}
    </Link>
  )
}

export function AppShell({ children }: { children: ReactNode }) {
  const { data: user, isLoading } = useCurrentUser()
  const logout = useLogout()
  const switchTenant = useSwitchTenant()
  const router = useRouter()

  if (isLoading) return <PageLoader label="Loading your workspace…" />

  const isPlatform = Boolean(user?.roles.some((role) => role.scope === 'platform' && !role.tenant_id))
  const isWorker = Boolean(user?.roles.some((role) => role.scope === 'workforce'))
  const permissions = new Set(user?.permissions ?? [])
  const can = (permission: string) => permissions.has(permission)
  const currentTenant = user?.current_tenant ?? null
  const roleNames = Array.from(
    new Set(
      (user?.roles ?? [])
        .filter((role) => role.scope !== 'workforce' || isWorker)
        .map((role) => role.name),
    ),
  ).slice(0, 3)

  return (
    <div className="min-h-screen lg:grid lg:grid-cols-[250px_1fr]">
      <aside className="border-b border-gray-200 bg-ink-900 text-white lg:min-h-screen lg:border-b-0">
        <div className="flex items-center gap-2 px-5 py-4">
          <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-brand-500 text-sm font-bold text-ink-900">
            P
          </span>
          <div>
            <p className="text-sm font-semibold leading-tight">BRATHAMATU</p>
            <p className="text-xs text-gray-400">Stocktake Platform</p>
          </div>
        </div>

        <nav className="flex gap-1 overflow-x-auto px-3 pb-4 lg:flex-col lg:overflow-visible" aria-label="Main">
          {isPlatform && (
            <>
              <p className="hidden px-3 pt-2 text-[11px] font-semibold uppercase tracking-wider text-gray-400 lg:block">
                Platform
              </p>
              <NavItem to="/platform">Operations overview</NavItem>
              {can('tenant.view') && <NavItem to="/platform/tenants">Tenants</NavItem>}
              {(can('stocktake.job.review') || can('finance.payment.record') || can('stocktake.quote.view')) && (
                <NavItem to="/platform/stocktaking-jobs">Stock-taking jobs</NavItem>
              )}
              {(can('stocktake.job.monitor') || can('stocktake.job.assign') || can('sop.manage')) && (
                <NavItem to="/platform/operations">Field operations</NavItem>
              )}
              {can('sop.manage') && <NavItem to="/platform/sops">SOP templates</NavItem>}
              {can('pricing.manage') && <NavItem to="/platform/catalogue-pricing">Catalogue pricing</NavItem>}
              {can('pricing.manage') && <NavItem to="/platform/stocktake-policy">Stocktake policy</NavItem>}
              {(can('finance.ledger.view') || can('finance.payment.record')) && (
                <NavItem to="/platform/finance">Finance</NavItem>
              )}
              {can('report.view') && <NavItem to="/platform/reports">Reports &amp; analytics</NavItem>}
              {can('support.view') && <NavItem to="/platform/support">Support queue</NavItem>}
              {can('workforce.view') && <NavItem to="/platform/workforce">Workforce</NavItem>}
              {can('audit.view') && <NavItem to="/platform/audit">Audit trail</NavItem>}

              {can('cms.view') && (
                <>
                  <p className="hidden px-3 pt-4 text-[11px] font-semibold uppercase tracking-wider text-gray-400 lg:block">
                    Website
                  </p>
                  <NavItem to="/platform/marketing">Marketing overview</NavItem>
                  <NavItem to="/platform/marketing/pages">Marketing pages</NavItem>
                  {can('media.manage') && <NavItem to="/platform/marketing/media">Media library</NavItem>}
                  <NavItem to="/platform/marketing/navigation">Navigation</NavItem>
                  <NavItem to="/platform/marketing/content">Content library</NavItem>
                  <NavItem to="/platform/marketing/seo">Technical SEO</NavItem>
                  {can('blog.manage') && <NavItem to="/platform/marketing/blog">Blog &amp; resources</NavItem>}
                  {can('social.publish') && (
                    <NavItem to="/platform/marketing/social">Social publishing</NavItem>
                  )}
                  {can('campaigns.view') && (
                    <NavItem to="/platform/marketing/campaigns">Campaigns</NavItem>
                  )}
                  {can('analytics.view') && (
                    <NavItem to="/platform/marketing/analytics">Analytics</NavItem>
                  )}
                  {can('automation.view') && (
                    <NavItem to="/platform/marketing/automation">Automation</NavItem>
                  )}
                  {can('integrations.google.manage') && (
                    <NavItem to="/platform/marketing/integrations/google">Google integrations</NavItem>
                  )}
                  {can('integrations.meta.manage') && (
                    <NavItem to="/platform/marketing/integrations/meta">Meta integrations</NavItem>
                  )}
                  {can('leads.view') && <NavItem to="/platform/marketing/leads">Leads</NavItem>}
                  <a
                    href={publicSiteUrl()}
                    target="_blank"
                    rel="noreferrer"
                    className="rounded-lg px-3 py-2 text-sm text-gray-300 transition-colors hover:bg-white/10 hover:text-white"
                  >
                    View public website ↗
                  </a>
                </>
              )}
            </>
          )}

          {currentTenant && (
            <>
              <p className="hidden px-3 pt-4 text-[11px] font-semibold uppercase tracking-wider text-gray-400 lg:block">
                Business
              </p>
              <NavItem to="/app">Dashboard</NavItem>
              <NavItem to="/business/locations">Branches &amp; locations</NavItem>
              <NavItem to="/business/catalogue">Catalogue</NavItem>
              <NavItem to="/business/inventory">Inventory</NavItem>
              {can('integration.manage') && <NavItem to="/business/integrations">Integrations</NavItem>}
              <NavItem to="/business/stocktaking">Stock-taking jobs</NavItem>
              <NavItem to="/business/team">Team members</NavItem>
              <NavItem to="/business/schedules">Schedules</NavItem>
              {can('report.view') && <NavItem to="/business/reports">Reports &amp; analytics</NavItem>}
              <NavItem to="/settings">Settings</NavItem>
            </>
          )}

          {isWorker && (
            <>
              <p className="hidden px-3 pt-4 text-[11px] font-semibold uppercase tracking-wider text-gray-400 lg:block">
                Workforce
              </p>
              <NavItem to="/app">My dashboard</NavItem>
              <NavItem to="/workforce/profile">My profile</NavItem>
              <NavItem to="/workforce/assignments">Assignments</NavItem>
              <NavItem to="/workforce/earnings">Earnings</NavItem>
            </>
          )}

          {currentTenant && currentTenant.operating_mode === 'brathamatu_pos' && (
            <>
              <p className="hidden px-3 pt-4 text-[11px] font-semibold uppercase tracking-wider text-gray-400 lg:block">
                BRATHAMATU POS
              </p>
              <NavItem to="/pos">POS Terminal</NavItem>
              <NavItem to="/pos/back-office">Back office</NavItem>
              {can('pos.shift.open') && <NavItem to="/pos/shifts">Shifts</NavItem>}
              {can('pos.manage') && <NavItem to="/pos/terminals">Terminals</NavItem>}
              {can('pos.manage') && <NavItem to="/pos/stocktake">Stock-taking</NavItem>}
            </>
          )}

          <p className="hidden px-3 pt-4 text-[11px] font-semibold uppercase tracking-wider text-gray-400 lg:block">
            Account
          </p>
          <NavItem to="/notifications">Notifications</NavItem>
          <NavItem to="/profile">Profile</NavItem>
          <NavItem to="/support">Support</NavItem>
        </nav>

        {roleNames.length > 0 && (
          <div className="hidden flex-wrap gap-1 px-4 pb-5 pt-2 lg:flex">
            {roleNames.map((name) => (
              <span key={name} className="rounded-full bg-white/10 px-2 py-0.5 text-[10px] text-gray-300">
                {name}
              </span>
            ))}
          </div>
        )}
      </aside>

      <div className="flex min-h-screen flex-col">
        <header className="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 bg-white px-5 py-3">
          <div className="flex items-center gap-3">
            {user && (
              <span
                className="flex h-8 w-8 items-center justify-center rounded-full bg-brand-100 text-xs font-semibold text-brand-800"
                aria-hidden
              >
                {initials(user.name)}
              </span>
            )}
            <div>
              {user && (
                <p className="text-sm font-medium text-gray-800" data-testid="current-user">
                  {user.name}
                </p>
              )}
              <p className="text-xs text-gray-500">
                {isPlatform ? 'BRATHAMATU operations' : currentTenant ? currentTenant.name : 'New account'}
              </p>
            </div>
          </div>

          <div className="flex items-center gap-3">
            {(user?.tenants.length ?? 0) > 1 && (
              <label className="flex items-center gap-2 text-xs text-gray-500">
                <span className="hidden sm:inline">Active business</span>
                <select
                  aria-label="Active business"
                  className="rounded-lg border border-gray-300 bg-white px-2 py-1.5 text-sm text-gray-700"
                  value={currentTenant?.id ?? ''}
                  onChange={(event) => {
                    switchTenant.mutate(event.target.value)
                    router.navigate({ to: '/app' })
                  }}
                >
                  {user?.tenants.map((tenant) => (
                    <option key={tenant.id} value={tenant.id}>
                      {tenant.name}
                    </option>
                  ))}
                </select>
              </label>
            )}
            {currentTenant && (
              <Badge tone={currentTenant.operating_mode === 'brathamatu_pos' ? 'green' : 'gray'}>
                {formatOperatingMode(currentTenant.operating_mode)}
              </Badge>
            )}
            <Button
              variant="secondary"
              loading={logout.isPending}
              onClick={() =>
                logout.mutate(undefined, {
                  onSettled: () => router.navigate({ to: '/login' }),
                })
              }
            >
              Sign out
            </Button>
          </div>
        </header>
        <main className="flex-1 px-5 py-6">{children}</main>
      </div>
    </div>
  )
}
