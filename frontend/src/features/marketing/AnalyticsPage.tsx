import { useState } from 'react'
import { Alert, Badge, Card, EmptyState, PageHeader, PageLoader, Select, StatCard, Table } from '../../components/ui'
import { ApiError } from '../../lib/api/client'
import {
  useAnalyticsCampaigns,
  useAnalyticsEvents,
  useAnalyticsFunnel,
  useAnalyticsLandingPages,
  useAnalyticsOverview,
  useAnalyticsSearch,
} from '../../lib/marketing/analyticsHooks'
import { formatDateTime } from '../../lib/formatting'

const ranges = [7, 30, 90]

function formatNumber(value: number): string {
  return value.toLocaleString()
}

function Bar({ value, max }: { value: number; max: number }) {
  const width = max > 0 ? Math.max(2, Math.round((value / max) * 100)) : 2
  return (
    <div className="h-2 w-full rounded-full bg-gray-100">
      <div className="h-2 rounded-full bg-brand-500" style={{ width: `${width}%` }} />
    </div>
  )
}

export function AnalyticsPage() {
  const [days, setDays] = useState(30)
  const overview = useAnalyticsOverview(days)
  const funnel = useAnalyticsFunnel(days)
  const campaigns = useAnalyticsCampaigns(days)
  const landingPages = useAnalyticsLandingPages(days)
  const search = useAnalyticsSearch(days)
  const events = useAnalyticsEvents(days)

  if (overview.isLoading) return <PageLoader label="Loading analytics…" />

  if (overview.isError) {
    return <Alert kind="error">{(overview.error as ApiError).message}</Alert>
  }

  const data = overview.data
  const consent = data?.consent
  const stageMax = Math.max(1, ...(funnel.data?.stages ?? []).map((stage) => stage.count))
  const dailyMax = Math.max(1, ...(data?.daily ?? []).map((day) => day.events))

  return (
    <div className="space-y-6" data-testid="analytics-page">
      <PageHeader
        title="Analytics"
        description="Consent-aware website analytics, lead funnel, campaign performance and landing-page attribution. Only sessions that accepted analytics are counted."
        actions={
          <Select
            label="Period"
            value={String(days)}
            onChange={(event) => setDays(Number(event.target.value))}
            data-testid="analytics-range"
          >
            {ranges.map((range) => (
              <option key={range} value={range}>{range} days</option>
            ))}
          </Select>
        }
      />

      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <StatCard
          label="Sessions"
          value={formatNumber(data?.sessions ?? 0)}
          hint={`${formatNumber(data?.page_views ?? 0)} page views`}
        />
        <StatCard
          label="Conversions"
          value={formatNumber(data?.conversions ?? 0)}
          hint={
            data?.conversion_rate != null
              ? `${data.conversion_rate}% of sessions`
              : 'No consented sessions yet'
          }
        />
        <StatCard
          label="Lead-matched"
          value={formatNumber(data?.lead_conversions ?? 0)}
          hint="Server-side form conversions"
        />
        <StatCard
          label="Analytics consent"
          value={consent?.grant_rate != null ? `${consent.grant_rate}%` : '—'}
          hint={`${consent?.analytics_granted ?? 0} granted · ${consent?.necessary_only ?? 0} necessary only`}
        />
      </div>

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <Card title="Last days of consented traffic">
          <div className="flex h-32 items-end gap-1" data-testid="analytics-daily">
            {(data?.daily ?? []).map((day) => (
              <div
                key={day.date}
                className="flex-1 rounded-t bg-brand-400"
                title={`${day.date}: ${day.events} events, ${day.conversions} conversions`}
                style={{ height: `${Math.max(3, Math.round((day.events / dailyMax) * 100))}%` }}
              />
            ))}
          </div>
          <p className="mt-3 text-xs text-gray-500">
            Consent policy {consent?.policy_version}. Unconsented sessions are never ingested
            {consent?.unconsented_excluded ? ' and are excluded from every report.' : '.'}
          </p>
        </Card>

        <Card title="Lead funnel">
          {funnel.data?.stages ? (
            <div className="space-y-2" data-testid="analytics-funnel">
              {funnel.data.stages.map((stage) => (
                <div key={stage.status} className="grid grid-cols-[6rem_1fr_2.5rem] items-center gap-3 text-sm">
                  <span className="capitalize text-gray-600">{stage.status.replace(/_/g, ' ')}</span>
                  <Bar value={stage.count} max={stageMax} />
                  <span className="text-right font-medium text-gray-800">{stage.count}</span>
                </div>
              ))}
              <p className="mt-3 text-xs text-gray-500">
                {funnel.data.reconciled
                  ? `${funnel.data.converted_leads} of ${funnel.data.leads} leads reconciled with server-side conversions.`
                  : 'Conversion events exceed captured leads; review the lead pipeline.'}
              </p>
            </div>
          ) : (
            <PageLoader label="Loading funnel…" />
          )}
        </Card>
      </div>

      <Card title="Campaign performance">
        <Table
          headers={['Campaign', 'Status', 'Sessions', 'Analytics conversions', 'Recorded leads', 'Spend', 'Cost / lead']}
          empty={<EmptyState title="No campaigns yet" description="Create a campaign to see attributed sessions and conversions." />}
        >
          {(campaigns.data?.campaigns ?? []).map((campaign) => (
            <tr key={campaign.id} data-testid={`analytics-campaign-row-${campaign.id}`}>
              <td className="px-4 py-3">
                <p className="font-medium text-gray-900">{campaign.name}</p>
                <p className="text-xs text-gray-500">{campaign.utm_campaign}</p>
              </td>
              <td className="px-4 py-3"><Badge tone="gray">{campaign.status}</Badge></td>
              <td className="px-4 py-3">{formatNumber(campaign.analytics_sessions)}</td>
              <td className="px-4 py-3">{formatNumber(campaign.analytics_conversions)}</td>
              <td className="px-4 py-3">{formatNumber(campaign.recorded_results.leads)}</td>
              <td className="px-4 py-3">
                {campaign.recorded_results.spend != null
                  ? `${campaign.budget_currency} ${formatNumber(Math.round(campaign.recorded_results.spend))}`
                  : '—'}
              </td>
              <td className="px-4 py-3">
                {campaign.cost_per_lead != null
                  ? `${campaign.budget_currency} ${formatNumber(Math.round(campaign.cost_per_lead))}`
                  : '—'}
              </td>
            </tr>
          ))}
        </Table>
      </Card>

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <Card title="Landing pages">
          <Table
            headers={['Path', 'Views', 'Sessions', 'Conversions']}
            empty={<EmptyState title="No consented page views yet" description="Views appear once a visitor accepts analytics." />}
          >
            {(landingPages.data?.pages ?? []).map((page) => (
              <tr key={page.path}>
                <td className="px-4 py-3 font-mono text-xs text-gray-800">{page.path}</td>
                <td className="px-4 py-3">{formatNumber(page.views)}</td>
                <td className="px-4 py-3">{formatNumber(page.sessions)}</td>
                <td className="px-4 py-3">{formatNumber(page.conversions)}</td>
              </tr>
            ))}
          </Table>
        </Card>

        <Card title="Search performance">
          {search.isLoading ? (
            <PageLoader label="Loading search data…" />
          ) : search.data?.available ? (
            <div className="space-y-4" data-testid="analytics-search">
              <div className="grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                <div><p className="text-xs text-gray-500">Clicks</p><p className="font-semibold">{formatNumber(search.data.clicks)}</p></div>
                <div><p className="text-xs text-gray-500">Impressions</p><p className="font-semibold">{formatNumber(search.data.impressions)}</p></div>
                <div><p className="text-xs text-gray-500">CTR</p><p className="font-semibold">{search.data.ctr}%</p></div>
                <div><p className="text-xs text-gray-500">Position</p><p className="font-semibold">{search.data.position}</p></div>
              </div>
              <div>
                <p className="mb-1 text-xs font-medium text-gray-600">Top queries</p>
                <ul className="space-y-1 text-sm">
                  {search.data.top_queries.map((row) => (
                    <li key={row.query} className="flex justify-between gap-3">
                      <span className="truncate text-gray-700">{row.query}</span>
                      <span className="text-gray-500">{row.clicks} clicks</span>
                    </li>
                  ))}
                </ul>
              </div>
            </div>
          ) : (
            <div data-testid="analytics-search-unavailable">
              <EmptyState
                title="No Search Console data yet"
                description={search.data?.message ?? 'Connect Google Search Console and run a search sync to populate this report.'}
              />
              <p className="mt-3 text-xs text-gray-500">This report never estimates or invents search metrics.</p>
            </div>
          )}
        </Card>
      </div>

      <Card title="Recent consented events">
        <Table
          headers={['Event', 'Path', 'Device', 'Campaign', 'When']}
          empty={<EmptyState title="No events in this period" description="Events appear after visitors accept analytics." />}
        >
          {(events.data?.events ?? []).map((event, index) => (
            <tr key={`${event.event}-${event.occurred_at}-${index}`}>
              <td className="px-4 py-3"><Badge tone="gray">{event.event}</Badge></td>
              <td className="px-4 py-3 font-mono text-xs text-gray-700">{event.path ?? '—'}</td>
              <td className="px-4 py-3 capitalize text-gray-600">{event.device ?? '—'}</td>
              <td className="px-4 py-3 text-gray-600">{event.utm_campaign ?? '—'}</td>
              <td className="px-4 py-3 text-gray-500">{event.occurred_at ? formatDateTime(event.occurred_at) : '—'}</td>
            </tr>
          ))}
        </Table>
      </Card>

      <div className="flex flex-wrap gap-2 text-xs text-gray-500">
        <Badge tone="gray">Consent required before ingestion</Badge>
        <Badge tone="gray">No raw PII stored</Badge>
        <Badge tone="gray">Server-side form conversions</Badge>
      </div>
    </div>
  )
}
