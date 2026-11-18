import { useEffect, useState } from 'react'
import { Alert, Badge, Button, Card, EmptyState, Field, PageLoader, PageHeader, Select, StatCard, Table } from '../../components/ui'
import { ApiError } from '../../lib/api/client'
import { useCurrentUser } from '../../lib/auth/hooks'
import { formatDateTime, formatMoney } from '../../lib/formatting'
import type { Analytics, AnalyticsInsight, Report, ReportQuery, ReportsIndex } from '../../lib/api/reporting'
import {
  useAnalytics,
  useDownloadReport,
  useGenerateReport,
  usePlatformAnalytics,
  usePlatformReportsIndex,
  useGeneratePlatformReport,
  useDownloadPlatformReport,
  useReportsIndex,
} from '../../lib/reporting/hooks'

const TENANT_REPORT_LABELS: Record<string, string> = {
  inventory_valuation: 'Inventory valuation',
  stock_movements: 'Stock movements',
  pos_sales: 'POS sales',
  stocktake_jobs: 'Stock-taking jobs',
}

const PLATFORM_REPORT_LABELS: Record<string, string> = {
  tenant_activity: 'Tenant activity',
  platform_jobs: 'Platform stock-taking jobs',
  platform_revenue: 'Platform revenue',
}

function errorText(error: unknown, fallback: string): string {
  return error instanceof ApiError ? error.message : fallback
}

function today(): string {
  return new Date().toISOString().slice(0, 10)
}

function daysAgo(days: number): string {
  const date = new Date()
  date.setDate(date.getDate() - days)
  return date.toISOString().slice(0, 10)
}

function saveBlob(blob: Blob, filename: string): void {
  const url = URL.createObjectURL(blob)
  const anchor = document.createElement('a')
  anchor.href = url
  anchor.download = filename
  document.body.appendChild(anchor)
  anchor.click()
  anchor.remove()
  URL.revokeObjectURL(url)
}

function insightTone(severity: AnalyticsInsight['severity']): string {
  if (severity === 'warning') return 'border-amber-200 bg-amber-50 text-amber-900'
  if (severity === 'success') return 'border-brand-200 bg-brand-50 text-brand-900'
  return 'border-blue-200 bg-blue-50 text-blue-900'
}

export function AnalyticsOverview({ data, loading }: { data?: Analytics; loading: boolean }) {
  if (loading) return <PageLoader label="Calculating analytics…" />
  if (!data) return null

  return (
    <div className="space-y-5">
      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
        {data.kpis.map((kpi) => (
          <StatCard
            key={kpi.key}
            label={kpi.label}
            value={kpi.format === 'money' ? formatMoney(kpi.value) : kpi.value.toLocaleString()}
            hint={kpi.hint ?? undefined}
          />
        ))}
      </div>

      <div className="grid grid-cols-1 gap-5 lg:grid-cols-3">
        <Card title="Insights" actions={<span className="text-xs text-gray-500">Deterministic</span>}>
          <ul className="space-y-3">
            {data.insights.map((insight) => (
              <li key={insight.key} className={`rounded-lg border px-4 py-3 text-sm ${insightTone(insight.severity)}`}>
                <p className="font-medium">{insight.title}</p>
                <p className="mt-1 text-xs opacity-80">{insight.detail}</p>
              </li>
            ))}
          </ul>
        </Card>

        <Card title="Trends" actions={<span className="text-xs text-gray-500">Last 14 days</span>}>
          <div className="space-y-4">
            {data.series.map((series) => {
              const max = Math.max(...series.points.map((point) => point.value), 1)
              return (
                <div key={series.key}>
                  <div className="mb-1 flex items-center justify-between text-xs text-gray-500">
                    <span className="font-medium text-gray-700">{series.label}</span>
                    <span>
                      {series.format === 'money'
                        ? formatMoney(series.points[series.points.length - 1]?.value ?? 0)
                        : (series.points[series.points.length - 1]?.value ?? 0).toLocaleString()}
                    </span>
                  </div>
                  <div className="flex h-10 items-end gap-1" aria-hidden>
                    {series.points.map((point) => (
                      <div
                        key={point.date}
                        title={`${point.date}: ${point.value}`}
                        className="flex-1 rounded-sm bg-brand-500/70"
                        style={{ height: `${Math.max((point.value / max) * 100, 3)}%` }}
                      />
                    ))}
                  </div>
                </div>
              )
            })}
          </div>
          <div className="mt-4 space-y-2 border-t border-gray-100 pt-3">
            {data.breakdowns.map((breakdown) => (
              <div key={breakdown.key}>
                <p className="text-xs font-semibold uppercase tracking-wide text-gray-500">{breakdown.label}</p>
                <ul className="mt-1 space-y-1 text-sm text-gray-700">
                  {breakdown.items.length === 0 && <li className="text-xs text-gray-500">No data yet.</li>}
                  {breakdown.items.map((item) => (
                    <li key={item.label} className="flex justify-between gap-3">
                      <span className="truncate">{item.label}</span>
                      <span className="font-medium">
                        {breakdown.format === 'money' ? formatMoney(item.value) : item.value.toLocaleString()}
                      </span>
                    </li>
                  ))}
                </ul>
              </div>
            ))}
          </div>
        </Card>

        <Card title="Intelligence interfaces">
          <div className="space-y-3">
            <div className="flex items-center gap-2">
              <Badge tone={data.ai.enabled ? 'green' : 'gray'}>
                {data.ai.enabled ? 'AI provider connected' : 'AI provider not configured'}
              </Badge>
              {data.ai.provider && <span className="text-xs text-gray-500">{data.ai.provider}</span>}
            </div>
            <p className="text-xs text-gray-500">{data.ai.note}</p>
            <ul className="space-y-2">
              {data.ai.interfaces.map((item) => (
                <li key={item.key} className="rounded-lg border border-gray-200 px-3 py-2">
                  <div className="flex items-center justify-between gap-2">
                    <p className="text-sm font-medium text-gray-800">{item.label}</p>
                    <Badge tone={item.status === 'available' ? 'green' : 'amber'}>{item.status}</Badge>
                  </div>
                  <p className="mt-1 text-xs text-gray-500">{item.description}</p>
                </li>
              ))}
            </ul>
          </div>
        </Card>
      </div>
    </div>
  )
}

interface WorkspaceProps {
  index?: ReportsIndex
  loading: boolean
  labels: Record<string, string>
  onGenerate: (type: string, query: ReportQuery) => Promise<Report>
  onExport: (type: string, query: ReportQuery) => Promise<Blob>
  filePrefix: string
}

export function ReportWorkspace({ index, loading, labels, onGenerate, onExport, filePrefix }: WorkspaceProps) {
  const [type, setType] = useState('')
  const [from, setFrom] = useState(daysAgo(30))
  const [to, setTo] = useState(today())
  const [limit, setLimit] = useState('1000')
  const [report, setReport] = useState<Report | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    if (!type && index?.available.length) setType(index.available[0])
  }, [index, type])

  if (loading) return <PageLoader label="Loading reports…" />

  const query: ReportQuery = { from, to, limit: Number(limit) || undefined }

  async function generate() {
    setError(null)
    setNotice(null)
    setBusy(true)
    try {
      const data = await onGenerate(type, query)
      setReport(data)
      setNotice(`${labels[type] ?? type} generated with ${data.row_count} rows.`)
    } catch (err) {
      setError(errorText(err, 'Could not generate the report.'))
    } finally {
      setBusy(false)
    }
  }

  async function exportCsv() {
    setError(null)
    setBusy(true)
    try {
      const blob = await onExport(type, query)
      saveBlob(blob, `${filePrefix}-${type}-${today()}.csv`)
      setNotice('CSV export downloaded and audit-logged.')
    } catch (err) {
      setError(errorText(err, 'Could not export the report.'))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="space-y-5">
      <Card title="Generate a report">
        <div className="grid grid-cols-1 gap-4 md:grid-cols-4">
          <Select label="Report" value={type} onChange={(event) => setType(event.target.value)} name="report-type">
            {(index?.available ?? []).map((key) => (
              <option key={key} value={key}>
                {labels[key] ?? key}
              </option>
            ))}
          </Select>
          <Field label="From" name="from" type="date" value={from} onChange={(event) => setFrom(event.target.value)} />
          <Field label="To" name="to" type="date" value={to} onChange={(event) => setTo(event.target.value)} />
          <Field label="Row limit" name="row_limit" type="number" min={1} max={5000} value={limit} onChange={(event) => setLimit(event.target.value)} />
        </div>
        <div className="mt-4 flex flex-wrap gap-2">
          <Button onClick={generate} loading={busy} disabled={!type}>
            Generate
          </Button>
          <Button variant="secondary" onClick={exportCsv} loading={busy} disabled={!type || !index?.can_export}>
            Export CSV
          </Button>
        </div>
        {error && <div className="mt-4"><Alert kind="error">{error}</Alert></div>}
        {notice && !error && <div className="mt-4"><Alert kind="success">{notice}</Alert></div>}
      </Card>

      {report && (
        <Card
          title={`${labels[report.report_type] ?? report.report_type} · ${report.period.from} → ${report.period.to}`}
          actions={<span className="text-xs text-gray-500">{report.row_count} rows</span>}
        >
          <div className="mb-5 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
            {report.summary.map((item) => (
              <div key={item.key} className="rounded-lg border border-gray-200 px-4 py-3">
                <p className="text-xs uppercase tracking-wide text-gray-500">{item.label}</p>
                <p className="mt-1 text-lg font-semibold text-gray-900">
                  {item.format === 'money'
                    ? formatMoney(item.value)
                    : typeof item.value === 'number'
                      ? item.value.toLocaleString()
                      : item.value}
                </p>
              </div>
            ))}
          </div>
          <Table
            headers={report.columns.map((column) => column.label)}
            empty={<EmptyState title="No rows in this period" description="Widen the date range or add data, then regenerate." />}
          >
            {report.rows.slice(0, 100).map((row, index) => (
              <tr key={index} className="hover:bg-gray-50">
                {report.columns.map((column) => {
                  const value = row[column.key]
                  const display =
                    column.type === 'money' && typeof value === 'number'
                      ? formatMoney(value)
                      : typeof value === 'number'
                        ? value.toLocaleString()
                        : value === null || value === undefined || value === ''
                          ? '—'
                          : String(value)
                  return (
                    <td key={column.key} className="whitespace-nowrap px-4 py-2 text-gray-700">
                      {display}
                    </td>
                  )
                })}
              </tr>
            ))}
          </Table>
          {report.rows.length > 100 && (
            <p className="mt-3 text-xs text-gray-500">Showing the first 100 rows. Export CSV for the full dataset.</p>
          )}
        </Card>
      )}

      <Card title="Recent report runs">
        {(index?.runs.length ?? 0) === 0 ? (
          <EmptyState title="No reports generated yet" description="Generated and exported reports are recorded here for audit." />
        ) : (
          <Table headers={['Report', 'Scope', 'Rows', 'Format', 'By', 'Generated']}>
            {index?.runs.map((run) => (
              <tr key={run.id}>
                <td className="px-4 py-2 font-medium text-gray-800">{labels[run.report_type] ?? run.report_type}</td>
                <td className="px-4 py-2 text-gray-600">{run.tenant ?? 'Tenant'}</td>
                <td className="px-4 py-2 text-gray-600">{run.row_count}</td>
                <td className="px-4 py-2 uppercase text-gray-600">{run.format}</td>
                <td className="px-4 py-2 text-gray-600">{run.generated_by ?? '—'}</td>
                <td className="px-4 py-2 text-gray-600">{run.generated_at ? formatDateTime(run.generated_at) : '—'}</td>
              </tr>
            ))}
          </Table>
        )}
      </Card>
    </div>
  )
}

export function ReportsPage() {
  const { data: user } = useCurrentUser()
  const tenantId = user?.current_tenant?.id
  const index = useReportsIndex(tenantId)
  const analytics = useAnalytics(tenantId)
  const generate = useGenerateReport(tenantId)
  const download = useDownloadReport(tenantId)

  return (
    <div className="space-y-6">
      <PageHeader
        title="Reports & analytics"
        description="Generate operational reports, export CSV, and review tenant KPIs and trends."
      />
      <AnalyticsOverview data={analytics.data} loading={analytics.isLoading} />
      <ReportWorkspace
        index={index.data}
        loading={index.isLoading}
        labels={TENANT_REPORT_LABELS}
        onGenerate={(type, query) => generate.mutateAsync({ type, query })}
        onExport={(type, query) => download.mutateAsync({ type, query })}
        filePrefix="brathamatu"
      />
    </div>
  )
}

export function PlatformReportsPage() {
  const index = usePlatformReportsIndex()
  const analytics = usePlatformAnalytics()
  const generate = useGeneratePlatformReport()
  const download = useDownloadPlatformReport()

  return (
    <div className="space-y-6">
      <PageHeader
        title="Platform reports & analytics"
        description="Cross-tenant reporting, platform revenue, and the operations intelligence surface."
      />
      <AnalyticsOverview data={analytics.data} loading={analytics.isLoading} />
      <ReportWorkspace
        index={index.data}
        loading={index.isLoading}
        labels={PLATFORM_REPORT_LABELS}
        onGenerate={(type, query) => generate.mutateAsync({ type, query })}
        onExport={(type, query) => download.mutateAsync({ type, query })}
        filePrefix="brathamatu-platform"
      />
    </div>
  )
}
