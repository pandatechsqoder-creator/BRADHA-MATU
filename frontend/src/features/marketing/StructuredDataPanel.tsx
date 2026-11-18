import { useEffect, useState } from 'react'
import { Alert, Badge, Button, Card, EmptyState, Field } from '../../components/ui'
import { ApiError } from '../../lib/api/client'
import { formatDateTime } from '../../lib/formatting'
import {
  STRUCTURED_DATA_TYPES,
  usePageStructuredData,
  useUpdatePageStructuredData,
  useValidatePageStructuredData,
} from '../../lib/marketing/structuredDataHooks'

const TYPE_LABELS: Record<string, string> = {
  faq: 'FAQPage — emitted when the page shows a visible FAQ',
  service: 'Service — stock-taking landing pages with service content',
  software_application: 'SoftwareApplication — BRATHAMATU POS pages with a feature section',
  article: 'Article / BlogPosting — needs a published blog post (Phase 8)',
  local_business: 'LocalBusiness — needs a verified location record',
}

const NODE_LABELS: Record<string, string> = {
  organization: 'Organization',
  website: 'WebSite',
  webpage: 'WebPage',
  breadcrumb: 'BreadcrumbList',
  faq: 'FAQPage',
  service: 'Service',
  software_application: 'SoftwareApplication',
  article: 'Article',
  local_business: 'LocalBusiness',
}

export function StructuredDataPanel({ pageId, canEdit }: { pageId: string; canEdit: boolean }) {
  const query = usePageStructuredData(pageId)
  const update = useUpdatePageStructuredData(pageId)
  const validate = useValidatePageStructuredData(pageId)

  const [types, setTypes] = useState<string[]>([])
  const [serviceType, setServiceType] = useState('')
  const [applicationCategory, setApplicationCategory] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)

  const data = query.data

  useEffect(() => {
    if (!data) return
    setTypes(data.settings.types)
    setServiceType(data.settings.overrides.service_type ?? '')
    setApplicationCategory(data.settings.overrides.application_category ?? '')
  }, [data])

  function report(err: unknown, fallback: string) {
    setNotice(null)
    setError(err instanceof ApiError ? err.message : fallback)
  }

  function toggleType(type: string) {
    setTypes((current) => (current.includes(type) ? current.filter((item) => item !== type) : [...current, type]))
  }

  function saveSettings() {
    setError(null)
    setNotice(null)
    update.mutate(
      {
        types,
        overrides: {
          service_type: serviceType || undefined,
          application_category: applicationCategory || undefined,
        },
      },
      {
        onSuccess: () => setNotice('Structured data settings saved.'),
        onError: (err) => report(err, 'Could not save structured data settings.'),
      },
    )
  }

  function runValidation() {
    setError(null)
    setNotice(null)
    validate.mutate(undefined, {
      onSuccess: (result) => setNotice(`Validation ${result.validation.status}. Publish requires a valid validation run.`),
      onError: (err) => report(err, 'Could not validate structured data.'),
    })
  }

  if (query.isLoading) {
    return (
      <Card title="Structured data (JSON-LD)">
        <p className="text-sm text-gray-500">Loading structured data…</p>
      </Card>
    )
  }

  if (query.isError || !data) {
    return (
      <Card title="Structured data (JSON-LD)">
        <Alert kind="error">{(query.error as ApiError | null)?.message ?? 'Structured data is unavailable.'}</Alert>
      </Card>
    )
  }

  const validation = data.validation
  const latestRuns = data.runs.slice(0, 5)

  return (
    <Card
      title="Structured data (JSON-LD)"
      actions={
        canEdit ? (
          <div className="flex flex-wrap gap-2">
            <Button variant="secondary" onClick={runValidation} loading={validate.isPending} data-testid="structured-data-validate">
              Validate now
            </Button>
            <Button onClick={saveSettings} loading={update.isPending} data-testid="structured-data-save">
              Save markup settings
            </Button>
          </div>
        ) : undefined
      }
    >
      <div className="space-y-4 text-sm text-gray-700" data-testid="structured-data-card">
        {error && <Alert kind="error">{error}</Alert>}
        {notice && !error && <Alert kind="success">{notice}</Alert>}

        <div className="flex flex-wrap items-center gap-2">
          <span>Status:</span>
          <Badge tone={validation.status === 'valid' ? 'green' : 'red'}>
            <span data-testid="structured-data-status">{validation.status}</span>
          </Badge>
          <span className="text-xs text-gray-500">Publishing is blocked while validation reports issues.</span>
        </div>

        <div className="flex flex-wrap gap-1" data-testid="structured-data-types">
          {validation.types.map((type) => (
            <Badge key={type} tone="gray">
              {NODE_LABELS[type] ?? type}
            </Badge>
          ))}
        </div>

        <p className="text-xs text-gray-500">
          Only facts shown on the page are emitted. Ratings, review counts, prices and offers are never invented.
        </p>

        <fieldset className="space-y-2">
          <legend className="font-medium text-gray-800">Requested markup</legend>
          {STRUCTURED_DATA_TYPES.map((type) => (
            <label key={type} className="flex items-start gap-2 text-sm">
              <input
                type="checkbox"
                name={`sd-type-${type}`}
                data-testid={`sd-type-${type}`}
                checked={types.includes(type)}
                disabled={!canEdit}
                onChange={() => toggleType(type)}
                className="mt-1"
              />
              <span>{TYPE_LABELS[type]}</span>
            </label>
          ))}
        </fieldset>

        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <Field
            label="Service type override"
            name="sd-service-type"
            value={serviceType}
            disabled={!canEdit}
            placeholder="e.g. Stocktaking"
            onChange={(event) => setServiceType(event.target.value)}
          />
          <Field
            label="Application category override"
            name="sd-application-category"
            value={applicationCategory}
            disabled={!canEdit}
            placeholder="BusinessApplication"
            onChange={(event) => setApplicationCategory(event.target.value)}
          />
        </div>

        <div>
          <p className="font-medium text-gray-800">Validation issues</p>
          {validation.issues.length === 0 ? (
            <p className="text-sm text-green-700" data-testid="structured-data-issues">
              No validation issues. Markup matches the page and its metadata.
            </p>
          ) : (
            <ul className="mt-1 list-disc space-y-1 pl-5 text-sm text-red-700" data-testid="structured-data-issues">
              {validation.issues.map((issue, index) => (
                <li key={`${issue.type}-${issue.field}-${index}`}>
                  <span className="font-medium">
                    {issue.type}.{issue.field}:
                  </span>{' '}
                  {issue.message}
                </li>
              ))}
            </ul>
          )}
        </div>

        <details className="rounded-lg border border-gray-200 bg-gray-50 p-3">
          <summary className="cursor-pointer text-sm font-medium text-gray-800">Emitted JSON-LD</summary>
          <pre className="mt-2 max-h-64 overflow-auto text-xs" data-testid="structured-data-json">
            {JSON.stringify(data.structured_data, null, 2)}
          </pre>
        </details>

        <div>
          <p className="font-medium text-gray-800">Recent validation runs</p>
          {latestRuns.length === 0 ? (
            <EmptyState title="No validation runs" description="Run a validation to record a history entry." />
          ) : (
            <ul className="mt-1 divide-y divide-gray-100" data-testid="structured-data-runs">
              {latestRuns.map((run) => (
                <li key={run.id} className="flex flex-wrap items-center justify-between gap-2 py-2">
                  <span className="text-sm">
                    <Badge tone={run.status === 'valid' ? 'green' : 'red'}>{run.status}</Badge>{' '}
                    {run.issues.length === 0 ? 'no issues' : `${run.issues.length} issue(s)`}
                  </span>
                  <span className="text-xs text-gray-500">{run.created_at ? formatDateTime(run.created_at) : '—'}</span>
                </li>
              ))}
            </ul>
          )}
        </div>

        {!canEdit && (
          <p className="text-xs text-gray-500">Editing structured data requires the cms.edit permission.</p>
        )}
      </div>
    </Card>
  )
}
