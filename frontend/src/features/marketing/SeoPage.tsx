import { useState, type FormEvent } from 'react'
import { Alert, Badge, Button, Card, EmptyState, Field, PageHeader, PageLoader, Select, StatCard } from '../../components/ui'
import { ApiError } from '../../lib/api/client'
import { useCurrentUser } from '../../lib/auth/hooks'
import {
  useCreateSeoRedirect,
  useDeleteSeoRedirect,
  useSeoDiagnostics,
  useSeoRedirects,
  useSeoVitals,
  useUpdateSeoRedirect,
  type RedirectInput,
  type SeoDiagnostics,
} from '../../lib/marketing/seoHooks'

const issueLabels: { key: keyof SeoDiagnostics['summary']; label: string }[] = [
  { key: 'missing_metadata', label: 'Missing metadata' },
  { key: 'duplicate_titles', label: 'Duplicate titles' },
  { key: 'duplicate_descriptions', label: 'Duplicate descriptions' },
  { key: 'broken_links', label: 'Broken internal links' },
  { key: 'canonical_conflicts', label: 'Canonical conflicts' },
  { key: 'broken_canonicals', label: 'Broken canonicals' },
  { key: 'redirect_chains', label: 'Redirect chains' },
  { key: 'orphan_pages', label: 'Orphan pages' },
]

function formatMs(value: number | null): string {
  return value === null ? '—' : `${Math.round(value)} ms`
}

function formatMetric(value: number | null, digits = 3): string {
  return value === null ? '—' : value.toFixed(digits)
}

export function SeoPage() {
  const { data: user } = useCurrentUser()
  const canManage = user?.permissions.includes('cms.publish') ?? false
  const diagnostics = useSeoDiagnostics()
  const redirects = useSeoRedirects()
  const createRedirect = useCreateSeoRedirect()
  const updateRedirect = useUpdateSeoRedirect()
  const deleteRedirect = useDeleteSeoRedirect()

  const [days, setDays] = useState(7)
  const [pathFilter, setPathFilter] = useState('')
  const vitals = useSeoVitals(days, pathFilter)

  const [form, setForm] = useState<RedirectInput>({ from_path: '', to_path: '', status_code: 301, reason: '' })
  const [error, setError] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)

  function report(err: unknown, fallback: string) {
    setNotice(null)
    setError(err instanceof ApiError ? err.message : fallback)
  }

  function submitRedirect(event: FormEvent) {
    event.preventDefault()
    setError(null)
    setNotice(null)
    createRedirect.mutate(
      {
        from_path: form.from_path,
        to_path: form.to_path,
        status_code: form.status_code,
        reason: form.reason || null,
      },
      {
        onSuccess: () => {
          setNotice('Redirect created.')
          setForm({ from_path: '', to_path: '', status_code: 301, reason: '' })
        },
        onError: (err) => report(err, 'Could not create the redirect.'),
      },
    )
  }

  return (
    <div className="space-y-6">
      <PageHeader
        title="Technical SEO"
        description="Indexing diagnostics, redirects and Core Web Vitals samples collected from real visitors."
      />

      {error && <Alert kind="error">{error}</Alert>}
      {notice && !error && <Alert kind="success">{notice}</Alert>}

      <Card
        title="Site diagnostics"
        actions={
          diagnostics.data ? (
            <span className="text-xs text-gray-500">
              Generated {new Date(diagnostics.data.generated_at).toLocaleString()}
            </span>
          ) : undefined
        }
      >
        {diagnostics.isLoading ? (
          <PageLoader label="Scanning published pages…" />
        ) : diagnostics.isError ? (
          <Alert kind="error">{(diagnostics.error as ApiError).message}</Alert>
        ) : (
          diagnostics.data && (
            <div className="space-y-5" data-testid="seo-diagnostics">
              <div className="grid grid-cols-2 gap-3 xl:grid-cols-4">
                <StatCard label="Published pages" value={diagnostics.data.summary.pages} hint={`${diagnostics.data.summary.indexable_pages} indexable`} />
                <StatCard label="Active redirects" value={diagnostics.data.summary.redirects} />
                <StatCard
                  label="Issues found"
                  value={diagnostics.data.summary.issues}
                  hint={diagnostics.data.summary.issues === 0 ? 'No SEO issues detected' : 'Review the sections below'}
                />
                <StatCard
                  label="Orphan pages"
                  value={diagnostics.data.summary.orphan_pages}
                  hint="No inbound internal links"
                />
              </div>

              <div className="grid grid-cols-2 gap-2 sm:grid-cols-4" data-testid="seo-issue-counts">
                {issueLabels.map(({ key, label }) => {
                  const count = diagnostics.data?.summary[key] ?? 0
                  return (
                    <div
                      key={key}
                      className="flex items-center justify-between rounded-lg border border-gray-200 px-3 py-2 text-sm"
                    >
                      <span className="text-gray-600">{label}</span>
                      <Badge tone={count === 0 ? 'green' : 'amber'}>{count}</Badge>
                    </div>
                  )
                })}
              </div>

              <IssueDetails report={diagnostics.data} />
            </div>
          )
        )}
      </Card>

      <div className="grid grid-cols-1 gap-6 xl:grid-cols-2">
        <Card title="Redirects">
          <div className="space-y-4">
            {redirects.isLoading ? (
              <PageLoader />
            ) : redirects.isError ? (
              <Alert kind="error">{(redirects.error as ApiError).message}</Alert>
            ) : (redirects.data?.redirects.length ?? 0) === 0 ? (
              <EmptyState
                title="No redirects yet"
                description="Changing a published page slug automatically creates a 301 redirect. You can add manual redirects below."
              />
            ) : (
              <ul className="space-y-2" data-testid="redirect-list">
                {redirects.data?.redirects.map((redirect) => (
                  <li
                    key={redirect.id}
                    data-testid="redirect-row"
                    data-from={redirect.from_path}
                    className="rounded-lg border border-gray-200 px-3 py-2"
                  >
                    <div className="flex flex-wrap items-center justify-between gap-2">
                      <div className="min-w-0">
                        <p className="truncate text-sm font-medium text-gray-900">
                          {redirect.from_path} → {redirect.to_path}
                        </p>
                        <p className="text-xs text-gray-500">
                          {redirect.status_code} · {redirect.hits} hit{redirect.hits === 1 ? '' : 's'}
                          {redirect.reason ? ` · ${redirect.reason}` : ''}
                          {redirect.page ? ` · ${redirect.page.title}` : ''}
                        </p>
                      </div>
                      <div className="flex shrink-0 items-center gap-2">
                        <Badge tone={redirect.is_active ? 'green' : 'gray'}>
                          {redirect.is_active ? 'active' : 'inactive'}
                        </Badge>
                        {canManage && (
                          <>
                            <Button
                              variant="secondary"
                              onClick={() =>
                                updateRedirect.mutate(
                                  { redirectId: redirect.id, input: { is_active: !redirect.is_active } },
                                  {
                                    onSuccess: () => setNotice(redirect.is_active ? 'Redirect disabled.' : 'Redirect enabled.'),
                                    onError: (err) => report(err, 'Could not update the redirect.'),
                                  },
                                )
                              }
                            >
                              {redirect.is_active ? 'Disable' : 'Enable'}
                            </Button>
                            <Button
                              variant="danger"
                              onClick={() =>
                                deleteRedirect.mutate(redirect.id, {
                                  onSuccess: () => setNotice('Redirect deleted.'),
                                  onError: (err) => report(err, 'Could not delete the redirect.'),
                                })
                              }
                            >
                              Delete
                            </Button>
                          </>
                        )}
                      </div>
                    </div>
                  </li>
                ))}
              </ul>
            )}

            {canManage ? (
              <form onSubmit={submitRedirect} className="space-y-3 border-t border-gray-100 pt-4" data-testid="redirect-form">
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                  <Field
                    label="From path"
                    name="redirect-from"
                    value={form.from_path}
                    onChange={(event) => setForm({ ...form, from_path: event.target.value })}
                    placeholder="/old-page"
                  />
                  <Field
                    label="To path"
                    name="redirect-to"
                    value={form.to_path}
                    onChange={(event) => setForm({ ...form, to_path: event.target.value })}
                    placeholder="/new-page"
                  />
                </div>
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                  <Select
                    label="Status code"
                    name="redirect-status"
                    value={String(form.status_code ?? 301)}
                    onChange={(event) => setForm({ ...form, status_code: Number(event.target.value) })}
                  >
                    <option value="301">301 — permanent</option>
                    <option value="302">302 — temporary</option>
                    <option value="307">307 — temporary (method preserved)</option>
                    <option value="308">308 — permanent (method preserved)</option>
                  </Select>
                  <Field
                    label="Reason (optional)"
                    name="redirect-reason"
                    value={form.reason ?? ''}
                    onChange={(event) => setForm({ ...form, reason: event.target.value })}
                    placeholder="Campaign landing page retired"
                  />
                </div>
                <Button type="submit" loading={createRedirect.isPending} disabled={!form.from_path || !form.to_path}>
                  Add redirect
                </Button>
              </form>
            ) : (
              <p className="border-t border-gray-100 pt-4 text-sm text-gray-500">
                Adding or editing redirects requires the cms.publish permission.
              </p>
            )}
          </div>
        </Card>

        <Card
          title="Core Web Vitals"
          actions={
            <div className="flex items-center gap-2">
              <Select
                label="Window"
                name="vitals-days"
                value={String(days)}
                onChange={(event) => setDays(Number(event.target.value))}
              >
                <option value="7">Last 7 days</option>
                <option value="28">Last 28 days</option>
                <option value="90">Last 90 days</option>
              </Select>
              <Field
                label="Path"
                name="vitals-path"
                value={pathFilter}
                onChange={(event) => setPathFilter(event.target.value)}
                placeholder="/pricing"
              />
            </div>
          }
        >
          {vitals.isLoading ? (
            <PageLoader />
          ) : vitals.isError ? (
            <Alert kind="error">{(vitals.error as ApiError).message}</Alert>
          ) : !vitals.data || vitals.data.summary.samples === 0 ? (
            <EmptyState
              title="No vitals samples yet"
              description="Published pages send anonymised LCP, CLS, INP and TTFB samples as visitors browse."
            />
          ) : (
            <div className="space-y-4" data-testid="vitals-summary">
              <div className="grid grid-cols-2 gap-3 xl:grid-cols-4">
                <StatCard
                  label="LCP p75"
                  value={formatMs(vitals.data.summary.lcp_p75)}
                  hint={`${vitals.data.summary.lcp_good_pct ?? '—'}% good`}
                />
                <StatCard label="CLS p75" value={formatMetric(vitals.data.summary.cls_p75)} />
                <StatCard label="INP p75" value={formatMs(vitals.data.summary.inp_p75)} />
                <StatCard label="TTFB p75" value={formatMs(vitals.data.summary.ttfb_p75)} />
              </div>
              <p className="text-xs text-gray-500">
                {vitals.data.summary.samples} samples in the last {vitals.data.window_days} days ·{' '}
                {Object.entries(vitals.data.summary.devices)
                  .map(([device, count]) => `${device} ${count}`)
                  .join(' · ')}
              </p>
              <div className="overflow-x-auto">
                <table className="min-w-full divide-y divide-gray-200 text-sm">
                  <thead className="bg-gray-50">
                    <tr>
                      {['Path', 'Samples', 'LCP p75', 'CLS p75', 'INP p75', 'Good LCP'].map((header) => (
                        <th
                          key={header}
                          scope="col"
                          className="whitespace-nowrap px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-gray-500"
                        >
                          {header}
                        </th>
                      ))}
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-gray-100">
                    {vitals.data.by_path.map((row) => (
                      <tr key={row.path}>
                        <td className="px-3 py-2 font-medium text-gray-900">{row.path}</td>
                        <td className="px-3 py-2 text-gray-600">{row.samples}</td>
                        <td className="px-3 py-2 text-gray-600">{formatMs(row.lcp_p75)}</td>
                        <td className="px-3 py-2 text-gray-600">{formatMetric(row.cls_p75)}</td>
                        <td className="px-3 py-2 text-gray-600">{formatMs(row.inp_p75)}</td>
                        <td className="px-3 py-2 text-gray-600">{row.lcp_good_pct ?? '—'}%</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          )}
        </Card>
      </div>
    </div>
  )
}

function IssueDetails({ report }: { report: SeoDiagnostics }) {
  const { issues } = report

  const groups: { key: string; title: string; rows: { label: string; detail: string }[] }[] = [
    {
      key: 'missing_metadata',
      title: 'Missing metadata',
      rows: issues.missing_metadata.map((issue) => ({
        label: issue.path,
        detail: `Missing ${issue.problems.join(' and ')} · ${issue.page}`,
      })),
    },
    {
      key: 'duplicate_titles',
      title: 'Duplicate titles',
      rows: issues.duplicate_titles.map((issue) => ({
        label: issue.value,
        detail: issue.paths.join(', '),
      })),
    },
    {
      key: 'duplicate_descriptions',
      title: 'Duplicate descriptions',
      rows: issues.duplicate_descriptions.map((issue) => ({
        label: issue.value,
        detail: issue.paths.join(', '),
      })),
    },
    {
      key: 'broken_links',
      title: 'Broken internal links',
      rows: issues.broken_links.map((issue) => ({
        label: issue.path,
        detail: `Linked from ${issue.from} as ${issue.url}`,
      })),
    },
    {
      key: 'canonical_conflicts',
      title: 'Canonical conflicts',
      rows: issues.canonical_conflicts.map((issue) => ({
        label: issue.canonical,
        detail: issue.paths.join(', '),
      })),
    },
    {
      key: 'broken_canonicals',
      title: 'Broken canonicals',
      rows: issues.broken_canonicals.map((issue) => ({
        label: issue.path,
        detail: issue.canonical,
      })),
    },
    {
      key: 'redirect_chains',
      title: 'Redirect chains',
      rows: issues.redirect_chains.map((issue) => ({
        label: issue.loop ? 'Loop detected' : 'Chain',
        detail: issue.chain.join(' → '),
      })),
    },
    {
      key: 'orphan_pages',
      title: 'Orphan pages',
      rows: issues.orphan_pages.map((issue) => ({
        label: issue.path,
        detail: issue.page,
      })),
    },
  ].filter((group) => group.rows.length > 0)

  if (groups.length === 0) {
    return <Alert kind="success">No technical SEO issues detected on published pages.</Alert>
  }

  return (
    <div className="space-y-4" data-testid="seo-issue-details">
      {groups.map((group) => (
        <div key={group.key}>
          <h3 className="text-sm font-semibold text-gray-900">{group.title}</h3>
          <ul className="mt-2 space-y-1">
            {group.rows.map((row, index) => (
              <li key={`${group.key}-${index}`} className="rounded-lg bg-gray-50 px-3 py-2 text-sm">
                <p className="break-all font-medium text-gray-800">{row.label}</p>
                <p className="break-all text-xs text-gray-500">{row.detail}</p>
              </li>
            ))}
          </ul>
        </div>
      ))}
    </div>
  )
}
