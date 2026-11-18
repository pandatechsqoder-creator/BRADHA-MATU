import { useEffect, useState } from 'react'
import {
  Alert,
  Badge,
  Button,
  Card,
  EmptyState,
  Field,
  PageHeader,
  Select,
  Table,
} from '../../components/ui'
import { ApiError } from '../../lib/api/client'
import type {
  IntegrationConflict,
  IntegrationConnection,
  IntegrationRun,
} from '../../lib/api/integrations'
import { useCurrentUser } from '../../lib/auth/hooks'
import { formatDateTime } from '../../lib/formatting'
import {
  useCreateIntegration,
  useDeleteIntegration,
  useIntegrationConflicts,
  useIntegrationMapping,
  useIntegrations,
  useIntegrationRuns,
  useIntegrationWebhooks,
  useResolveIntegrationConflict,
  useRunIntegrationSync,
  useSaveIntegrationMapping,
  useTestIntegration,
  useUpdateIntegration,
} from '../../lib/integrations/hooks'

const PROVIDERS = ['csv', 'erpnext', 'odoo', 'quickbooks', 'sap', 'sage', 'generic_api', 'custom'] as const
const ENTITIES = ['products', 'movements'] as const

type Tab = 'mapping' | 'sync' | 'runs' | 'conflicts' | 'webhooks'

const TABS: { id: Tab; label: string }[] = [
  { id: 'mapping', label: 'Field mapping' },
  { id: 'sync', label: 'Run sync' },
  { id: 'runs', label: 'Runs' },
  { id: 'conflicts', label: 'Conflicts' },
  { id: 'webhooks', label: 'Webhooks' },
]

function errorText(error: unknown, fallback: string): string {
  return error instanceof ApiError ? error.message : fallback
}

function statusTone(status: string): 'gray' | 'green' | 'red' | 'amber' {
  if (status === 'active' || status === 'completed' || status === 'processed') return 'green'
  if (status === 'error' || status === 'failed') return 'red'
  if (status === 'paused' || status === 'partial' || status === 'running') return 'amber'
  return 'gray'
}

export function IntegrationsPage() {
  const { data: user } = useCurrentUser()
  const tenantId = user?.current_tenant?.id
  const connections = useIntegrations(tenantId)

  const [selectedId, setSelectedId] = useState<string | null>(null)

  const selected = selectedId
    ? connections.data?.find((connection) => connection.id === selectedId) ?? null
    : null

  return (
    <div className="space-y-6">
      <PageHeader
        title="Integrations"
        description="Connect BRATHAMATU to external POS, ERP and accounting systems. Imports create auditable inventory movements and catalogue versions."
      />

      <div className="grid grid-cols-1 gap-6 xl:grid-cols-12">
        <div className="space-y-4 xl:col-span-4">
          <Card title="Connections">
            {connections.isLoading && <p className="text-sm text-gray-500">Loading connections…</p>}
            {connections.isError && (
              <Alert kind="error">{errorText(connections.error, 'Could not load connections.')}</Alert>
            )}
            {connections.data && connections.data.length === 0 && (
              <EmptyState
                title="No integrations yet"
                description="Create your first connection to start syncing catalogue items and stock movements."
              />
            )}
            <ul className="space-y-2">
              {(connections.data ?? []).map((connection) => (
                <li key={connection.id}>
                  <button
                    type="button"
                    onClick={() => setSelectedId(connection.id)}
                    aria-current={selectedId === connection.id}
                    className={`w-full rounded-lg border px-3 py-2.5 text-left transition-colors ${
                      selectedId === connection.id
                        ? 'border-brand-500 bg-brand-50'
                        : 'border-gray-200 hover:bg-gray-50'
                    }`}
                  >
                    <div className="flex items-center justify-between gap-2">
                      <span className="text-sm font-medium text-gray-900">{connection.name}</span>
                      <Badge tone={statusTone(connection.status)}>{connection.status}</Badge>
                    </div>
                    <p className="mt-1 text-xs text-gray-500">
                      {connection.provider} · {connection.type.toUpperCase()} ·{' '}
                      {connection.runs_count} run(s) · {connection.conflicts_count} open conflict(s)
                    </p>
                    {connection.last_error && (
                      <p className="mt-1 truncate text-xs text-red-600">{connection.last_error}</p>
                    )}
                  </button>
                </li>
              ))}
            </ul>
          </Card>

          <NewConnectionForm tenantId={tenantId} onCreated={setSelectedId} />
        </div>

        <div className="xl:col-span-8">
          {selected ? (
            <ConnectionPanel key={selected.id} tenantId={tenantId} connection={selected} />
          ) : (
            <Card title="Connection workspace">
              <EmptyState
                title="Select a connection"
                description="Choose a connection on the left to manage mapping, sync runs and conflicts."
              />
            </Card>
          )}
        </div>
      </div>
    </div>
  )
}

function NewConnectionForm({
  tenantId,
  onCreated,
}: {
  tenantId: string | undefined
  onCreated: (id: string) => void
}) {
  const create = useCreateIntegration(tenantId)
  const [name, setName] = useState('')
  const [provider, setProvider] = useState<string>('csv')
  const [type, setType] = useState<'csv' | 'api'>('csv')
  const [url, setUrl] = useState('')
  const [token, setToken] = useState('')
  const [autoSync, setAutoSync] = useState(false)
  const [webhookSecret, setWebhookSecret] = useState('')
  const [error, setError] = useState<string | null>(null)

  function submit() {
    setError(null)
    create.mutate(
      {
        name: name.trim(),
        provider,
        type,
        settings: {
          ...(type === 'api' && url.trim() ? { url: url.trim() } : {}),
          auto_sync: autoSync,
        },
        credentials: token.trim() ? { token: token.trim() } : null,
        webhook_secret: webhookSecret.trim() || null,
      },
      {
        onSuccess: (connection) => {
          setName('')
          setUrl('')
          setToken('')
          setWebhookSecret('')
          setAutoSync(false)
          onCreated(connection.id)
        },
        onError: (err) => setError(errorText(err, 'Could not create the connection.')),
      },
    )
  }

  return (
    <Card title="New connection">
      <div className="space-y-3">
        {error && <Alert kind="error">{error}</Alert>}
        <Field label="Name" value={name} onChange={(event) => setName(event.target.value)} />
        <Select
          label="Provider"
          value={provider}
          onChange={(event) => setProvider(event.target.value)}
        >
          {PROVIDERS.map((item) => (
            <option key={item} value={item}>
              {item}
            </option>
          ))}
        </Select>
        <Select
          label="Transport"
          value={type}
          onChange={(event) => setType(event.target.value as 'csv' | 'api')}
        >
          <option value="csv">CSV / row import</option>
          <option value="api">HTTPS API pull</option>
        </Select>
        {type === 'api' && (
          <>
            <Field
              label="Remote endpoint (https)"
              value={url}
              onChange={(event) => setUrl(event.target.value)}
              placeholder="https://erp.example.com/api/stock"
            />
            <Field
              label="Bearer token"
              type="password"
              value={token}
              onChange={(event) => setToken(event.target.value)}
              hint="Stored encrypted; never returned by the API."
            />
            <label className="flex items-center gap-2 text-sm text-gray-700">
              <input
                type="checkbox"
                checked={autoSync}
                onChange={(event) => setAutoSync(event.target.checked)}
              />
              Pull automatically every hour
            </label>
          </>
        )}
        <Field
          label="Webhook secret (optional)"
          type="password"
          value={webhookSecret}
          onChange={(event) => setWebhookSecret(event.target.value)}
          hint="Minimum 16 characters. Used to verify X-Brathamatu-Signature."
        />
        <Button onClick={submit} loading={create.isPending} disabled={!name.trim() || !tenantId}>
          Create connection
        </Button>
      </div>
    </Card>
  )
}

function ConnectionPanel({
  tenantId,
  connection,
}: {
  tenantId: string | undefined
  connection: IntegrationConnection
}) {
  const [tab, setTab] = useState<Tab>('mapping')
  const test = useTestIntegration(tenantId)
  const update = useUpdateIntegration(tenantId)
  const remove = useDeleteIntegration(tenantId)
  const [notice, setNotice] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    setNotice(null)
    setError(null)
  }, [connection.id])

  function runTest() {
    setError(null)
    setNotice(null)
    test.mutate(connection.id, {
      onSuccess: (result) => {
        if (result.status === 'ok') setNotice(result.message)
        else setError(result.message)
      },
      onError: (err) => setError(errorText(err, 'Connection test failed.')),
    })
  }

  function toggleStatus() {
    update.mutate(
      {
        connectionId: connection.id,
        input: { status: connection.status === 'paused' ? 'active' : 'paused' },
      },
      { onError: (err) => setError(errorText(err, 'Could not update the connection.')) },
    )
  }

  function deleteConnection() {
    if (!window.confirm(`Delete the "${connection.name}" connection? Sync history will be removed.`)) {
      return
    }
    remove.mutate(connection.id, {
      onError: (err) => setError(errorText(err, 'Could not delete the connection.')),
    })
  }

  return (
    <div className="space-y-4">
      <Card
        title={connection.name}
        actions={
          <div className="flex items-center gap-2">
            <Badge tone={statusTone(connection.status)}>{connection.status}</Badge>
            <Button variant="secondary" onClick={runTest} loading={test.isPending}>
              Test
            </Button>
            <Button variant="secondary" onClick={toggleStatus} loading={update.isPending}>
              {connection.status === 'paused' ? 'Resume' : 'Pause'}
            </Button>
            <Button variant="danger" onClick={deleteConnection} loading={remove.isPending}>
              Delete
            </Button>
          </div>
        }
      >
        <dl className="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
          <div>
            <dt className="text-xs uppercase tracking-wide text-gray-500">Provider</dt>
            <dd className="font-medium text-gray-900">
              {connection.provider} · {connection.type.toUpperCase()}
            </dd>
          </div>
          <div>
            <dt className="text-xs uppercase tracking-wide text-gray-500">Last synced</dt>
            <dd className="text-gray-900">
              {connection.last_synced_at ? formatDateTime(connection.last_synced_at) : 'Never'}
            </dd>
          </div>
          <div>
            <dt className="text-xs uppercase tracking-wide text-gray-500">Credentials</dt>
            <dd className="text-gray-900">
              {connection.has_credentials ? 'Configured (encrypted)' : 'None'}
            </dd>
          </div>
          <div>
            <dt className="text-xs uppercase tracking-wide text-gray-500">Webhook receiver</dt>
            <dd className="break-all font-mono text-xs text-gray-700">
              POST /api/v1/integrations/webhooks/{connection.id}
            </dd>
          </div>
        </dl>
        {connection.last_error && (
          <div className="mt-3">
            <Alert kind="error">{connection.last_error}</Alert>
          </div>
        )}
        {notice && (
          <div className="mt-3">
            <Alert kind="success">{notice}</Alert>
          </div>
        )}
        {error && (
          <div className="mt-3">
            <Alert kind="error">{error}</Alert>
          </div>
        )}
      </Card>

      <div className="flex flex-wrap gap-2 border-b border-gray-200 pb-2" role="tablist">
        {TABS.map((item) => (
          <button
            key={item.id}
            type="button"
            role="tab"
            aria-selected={tab === item.id}
            onClick={() => setTab(item.id)}
            className={`rounded-lg px-3 py-1.5 text-sm font-medium transition-colors ${
              tab === item.id
                ? 'bg-brand-700 text-white'
                : 'bg-gray-100 text-gray-700 hover:bg-gray-200'
            }`}
          >
            {item.label}
          </button>
        ))}
      </div>

      {tab === 'mapping' && <MappingPanel tenantId={tenantId} connection={connection} />}
      {tab === 'sync' && <SyncPanel tenantId={tenantId} connection={connection} />}
      {tab === 'runs' && <RunsPanel tenantId={tenantId} connection={connection} />}
      {tab === 'conflicts' && <ConflictsPanel tenantId={tenantId} connection={connection} />}
      {tab === 'webhooks' && <WebhooksPanel tenantId={tenantId} connection={connection} />}
    </div>
  )
}

function MappingPanel({
  tenantId,
  connection,
}: {
  tenantId: string | undefined
  connection: IntegrationConnection
}) {
  const [entity, setEntity] = useState<'products' | 'movements'>('products')
  const mapping = useIntegrationMapping(tenantId, connection.id, entity)
  const save = useSaveIntegrationMapping(tenantId)
  const [values, setValues] = useState<Record<string, string>>({})
  const [notice, setNotice] = useState<string | null>(null)

  useEffect(() => {
    setValues(mapping.data?.field_map ?? {})
  }, [mapping.data])

  useEffect(() => {
    setNotice(null)
  }, [entity, connection.id])

  const canonical = Object.entries(mapping.data?.canonical ?? {})

  function submit() {
    const cleaned = Object.fromEntries(
      Object.entries(values).filter(([, value]) => value.trim() !== ''),
    )
    save.mutate(
      { connectionId: connection.id, entity, fieldMap: cleaned },
      { onSuccess: () => setNotice('Mapping saved.') },
    )
  }

  return (
    <Card title="Field mapping">
      <div className="space-y-4">
        <Select
          label="Entity"
          value={entity}
          onChange={(event) => setEntity(event.target.value as 'products' | 'movements')}
        >
          {ENTITIES.map((item) => (
            <option key={item} value={item}>
              {item}
            </option>
          ))}
        </Select>

        {mapping.isLoading && <p className="text-sm text-gray-500">Loading mapping…</p>}
        {mapping.isError && (
          <Alert kind="error">{errorText(mapping.error, 'Could not load the mapping.')}</Alert>
        )}

        {!mapping.isLoading && (
          <div className="space-y-3">
            <p className="text-xs text-gray-500">
              Map each canonical field to the column name in the external payload. Leave blank to
              ignore a field.
            </p>
            {canonical.map(([field, label]) => (
              <Field
                key={field}
                label={`${label} (${field})`}
                value={values[field] ?? ''}
                onChange={(event) =>
                  setValues((current) => ({ ...current, [field]: event.target.value }))
                }
                placeholder={field}
              />
            ))}
            {canonical.length === 0 && (
              <Alert kind="info">No canonical fields were returned for this entity.</Alert>
            )}
            {notice && <Alert kind="success">{notice}</Alert>}
            <Button onClick={submit} loading={save.isPending}>
              Save mapping
            </Button>
          </div>
        )}
      </div>
    </Card>
  )
}

function SyncPanel({
  tenantId,
  connection,
}: {
  tenantId: string | undefined
  connection: IntegrationConnection
}) {
  const [entity, setEntity] = useState<'products' | 'movements'>('products')
  const [direction, setDirection] = useState<'import' | 'pull'>(
    connection.type === 'api' ? 'pull' : 'import',
  )
  const [rows, setRows] = useState('[\n  { "item_name": "Sugar 2kg", "item_code": "SUG-2KG", "buying_price": 240 }\n]')
  const [result, setResult] = useState<IntegrationRun | null>(null)
  const [error, setError] = useState<string | null>(null)
  const sync = useRunIntegrationSync(tenantId)

  function submit() {
    setError(null)
    setResult(null)

    let parsed: Record<string, unknown>[] = []
    if (direction === 'import') {
      try {
        const value = JSON.parse(rows)
        if (!Array.isArray(value)) throw new Error('Rows must be a JSON array.')
        parsed = value as Record<string, unknown>[]
      } catch (err) {
        setError(err instanceof Error ? err.message : 'Rows must be valid JSON.')
        return
      }
    }

    sync.mutate(
      { connectionId: connection.id, input: { entity, direction, rows: parsed } },
      {
        onSuccess: (run) => setResult(run),
        onError: (err) => setError(errorText(err, 'The sync could not be started.')),
      },
    )
  }

  return (
    <Card title="Run sync">
      <div className="space-y-3">
        <Select
          label="Entity"
          value={entity}
          onChange={(event) => setEntity(event.target.value as 'products' | 'movements')}
        >
          {ENTITIES.map((item) => (
            <option key={item} value={item}>
              {item}
            </option>
          ))}
        </Select>
        <Select
          label="Direction"
          value={direction}
          onChange={(event) => setDirection(event.target.value as 'import' | 'pull')}
        >
          <option value="import">Import submitted rows</option>
          <option value="pull">Pull from remote endpoint</option>
        </Select>

        {direction === 'import' ? (
          <label className="block space-y-1">
            <span className="block text-sm font-medium text-gray-700">Rows (JSON array)</span>
            <textarea
              value={rows}
              onChange={(event) => setRows(event.target.value)}
              rows={10}
              className="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 font-mono text-xs shadow-sm focus:outline-2 focus:outline-brand-600"
            />
          </label>
        ) : (
          <p className="text-sm text-gray-600">
            Rows are fetched from the configured remote endpoint. Ensure an endpoint URL and bearer
            token are saved on the connection.
          </p>
        )}

        {error && <Alert kind="error">{error}</Alert>}
        {result && (
          <Alert kind={result.status === 'failed' ? 'error' : result.status === 'partial' ? 'info' : 'success'}>
            <p className="font-medium">
              {result.status} · {result.records_upserted} applied · {result.records_skipped} skipped ·{' '}
              {result.conflict_count} conflict(s)
            </p>
            {result.message && <p className="mt-1 text-xs">{result.message}</p>}
          </Alert>
        )}
        <Button onClick={submit} loading={sync.isPending}>
          Start sync
        </Button>
      </div>
    </Card>
  )
}

function RunsPanel({
  tenantId,
  connection,
}: {
  tenantId: string | undefined
  connection: IntegrationConnection
}) {
  const runs = useIntegrationRuns(tenantId, connection.id)

  if (runs.isLoading) return <p className="text-sm text-gray-500">Loading runs…</p>
  if (runs.isError) {
    return <Alert kind="error">{errorText(runs.error, 'Could not load sync runs.')}</Alert>
  }

  return (
    <Table
      headers={['Started', 'Entity', 'Direction', 'Status', 'In', 'Applied', 'Skipped', 'Conflicts', 'Message']}
      empty={<EmptyState title="No sync runs yet" description="Start a sync to see it here." />}
    >
      {(runs.data ?? []).map((run) => (
        <tr key={run.id}>
          <td className="whitespace-nowrap px-4 py-3 text-gray-600">
            {run.started_at ? formatDateTime(run.started_at) : '—'}
          </td>
          <td className="px-4 py-3 text-gray-900">{run.entity}</td>
          <td className="px-4 py-3 text-gray-600">{run.direction}</td>
          <td className="px-4 py-3">
            <Badge tone={statusTone(run.status)}>{run.status}</Badge>
          </td>
          <td className="px-4 py-3 text-gray-600">{run.records_in}</td>
          <td className="px-4 py-3 text-gray-600">{run.records_upserted}</td>
          <td className="px-4 py-3 text-gray-600">{run.records_skipped}</td>
          <td className="px-4 py-3 text-gray-600">{run.conflict_count}</td>
          <td className="px-4 py-3 text-xs text-gray-500">{run.message ?? '—'}</td>
        </tr>
      ))}
    </Table>
  )
}

function ConflictsPanel({
  tenantId,
  connection,
}: {
  tenantId: string | undefined
  connection: IntegrationConnection
}) {
  const conflicts = useIntegrationConflicts(tenantId, connection.id)
  const resolve = useResolveIntegrationConflict(tenantId)
  const [error, setError] = useState<string | null>(null)

  function decide(conflict: IntegrationConflict, resolution: 'accept_external' | 'keep_local' | 'ignored') {
    setError(null)
    resolve.mutate(
      { connectionId: connection.id, conflictId: conflict.id, resolution },
      { onError: (err) => setError(errorText(err, 'Could not resolve the conflict.')) },
    )
  }

  if (conflicts.isLoading) return <p className="text-sm text-gray-500">Loading conflicts…</p>
  if (conflicts.isError) {
    return <Alert kind="error">{errorText(conflicts.error, 'Could not load conflicts.')}</Alert>
  }

  return (
    <div className="space-y-3">
      {error && <Alert kind="error">{error}</Alert>}
      <Table
        headers={['Detected', 'Entity', 'Reference', 'Reason', 'Resolution', 'Actions']}
        empty={<EmptyState title="No conflicts" description="Conflicting records appear here for review." />}
      >
        {(conflicts.data ?? []).map((conflict) => (
          <tr key={conflict.id}>
            <td className="whitespace-nowrap px-4 py-3 text-gray-600">
              {conflict.created_at ? formatDateTime(conflict.created_at) : '—'}
            </td>
            <td className="px-4 py-3 text-gray-900">{conflict.entity}</td>
            <td className="px-4 py-3 font-mono text-xs text-gray-700">{conflict.reference}</td>
            <td className="px-4 py-3 text-gray-600">{conflict.reason}</td>
            <td className="px-4 py-3">
              {conflict.resolution && conflict.resolution !== 'pending' ? (
                <Badge tone="green">{conflict.resolution}</Badge>
              ) : (
                <Badge tone="amber">open</Badge>
              )}
            </td>
            <td className="px-4 py-3">
              {(!conflict.resolution || conflict.resolution === 'pending') && (
                <div className="flex flex-wrap gap-2">
                  <Button
                    variant="secondary"
                    onClick={() => decide(conflict, 'accept_external')}
                    loading={resolve.isPending}
                  >
                    Accept external
                  </Button>
                  <Button
                    variant="secondary"
                    onClick={() => decide(conflict, 'keep_local')}
                    loading={resolve.isPending}
                  >
                    Keep local
                  </Button>
                  <Button
                    variant="secondary"
                    onClick={() => decide(conflict, 'ignored')}
                    loading={resolve.isPending}
                  >
                    Ignore
                  </Button>
                </div>
              )}
            </td>
          </tr>
        ))}
      </Table>
    </div>
  )
}

function WebhooksPanel({
  tenantId,
  connection,
}: {
  tenantId: string | undefined
  connection: IntegrationConnection
}) {
  const events = useIntegrationWebhooks(tenantId, connection.id)

  if (events.isLoading) return <p className="text-sm text-gray-500">Loading webhook events…</p>
  if (events.isError) {
    return <Alert kind="error">{errorText(events.error, 'Could not load webhook events.')}</Alert>
  }

  return (
    <Card title="Webhook events">
      <p className="mb-3 text-xs text-gray-500">
        Signed with HMAC-SHA256 over the raw request body and sent in the{' '}
        <code className="font-mono">X-Brathamatu-Signature</code> header.
      </p>
      <Table
        headers={['Received', 'Event', 'Signature', 'Status', 'Attempts', 'Processed', 'Error']}
        empty={<EmptyState title="No webhook events" description="Inbound events will be listed here." />}
      >
        {(events.data ?? []).map((event) => (
          <tr key={event.id}>
            <td className="whitespace-nowrap px-4 py-3 text-gray-600">
              {event.created_at ? formatDateTime(event.created_at) : '—'}
            </td>
            <td className="px-4 py-3 text-gray-900">{event.event}</td>
            <td className="px-4 py-3">
              <Badge tone={event.signature_valid ? 'green' : 'red'}>
                {event.signature_valid ? 'valid' : 'invalid'}
              </Badge>
            </td>
            <td className="px-4 py-3">
              <Badge tone={statusTone(event.status)}>{event.status}</Badge>
            </td>
            <td className="px-4 py-3 text-gray-600">{event.attempts}</td>
            <td className="whitespace-nowrap px-4 py-3 text-gray-600">
              {event.processed_at ? formatDateTime(event.processed_at) : '—'}
            </td>
            <td className="px-4 py-3 text-xs text-red-600">{event.last_error ?? ''}</td>
          </tr>
        ))}
      </Table>
    </Card>
  )
}
