import { useEffect, useState } from 'react'
import {
  Alert,
  Badge,
  Button,
  Card,
  EmptyState,
  PageHeader,
  PageLoader,
  StatCard,
  Table,
} from '../../components/ui'
import { ApiError } from '../../lib/api/client'
import {
  useConnectMeta,
  useDisconnectMeta,
  useMetaIntegration,
  useSyncMeta,
  type MetaSyncType,
} from '../../lib/marketing/metaHooks'

const syncButtons: { type: MetaSyncType; label: string }[] = [
  { type: 'assets', label: 'Sync Pages & accounts' },
  { type: 'insights', label: 'Sync insights' },
  { type: 'ads', label: 'Sync ad spend' },
]

function toneFor(status: string): 'green' | 'red' | 'amber' | 'blue' | 'gray' {
  switch (status) {
    case 'connected':
    case 'success':
      return 'green'
    case 'failed':
    case 'expired':
    case 'error':
      return 'red'
    case 'queued':
      return 'amber'
    case 'running':
      return 'blue'
    default:
      return 'gray'
  }
}

function formatDate(value: string | null): string {
  return value ? new Date(value).toLocaleString() : '—'
}

function reasonText(reason: string | null): string {
  switch (reason) {
    case 'denied':
      return 'Meta sign-in was cancelled. No connection was created.'
    case 'meta_oauth_state_invalid':
      return 'This Meta sign-in link is invalid or has already been used. Start the connection again.'
    case 'meta_oauth_failed':
      return 'Meta sign-in could not be completed. Check the app, redirect URI and approved permissions, then try again.'
    case 'meta_not_configured':
      return 'Meta integration is not configured yet. An owner must add the Meta app credentials first.'
    case 'meta_permission_denied':
      return 'The Meta account does not grant the required permissions. Check app review and Page roles.'
    case 'meta_api_unreachable':
      return 'Meta could not be reached. Try connecting again later.'
    default:
      return reason
        ? `Meta sign-in failed (${reason}). Try connecting again.`
        : 'Meta sign-in failed. Try connecting again.'
  }
}

function countSummary(counts: Record<string, number>): string {
  const entries = Object.entries(counts)
  if (entries.length === 0) return '—'
  return entries.map(([key, value]) => `${key.replaceAll('_', ' ')}: ${value.toLocaleString()}`).join(' · ')
}

export function MetaIntegrationsPage() {
  const integration = useMetaIntegration()
  const connect = useConnectMeta()
  const disconnect = useDisconnectMeta()
  const sync = useSyncMeta()

  const [callback, setCallback] = useState<{ kind: 'success' | 'error'; text: string } | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)
  const [syncing, setSyncing] = useState<MetaSyncType | null>(null)

  useEffect(() => {
    const params = new URLSearchParams(window.location.search)
    const flag = params.get('meta')
    if (flag === 'connected') {
      setCallback({ kind: 'success', text: 'Meta account connected. Sync Pages and ad accounts to start publishing.' })
    } else if (flag === 'error') {
      setCallback({ kind: 'error', text: reasonText(params.get('reason')) })
    }
    if (flag) {
      window.history.replaceState({}, '', window.location.pathname)
    }
  }, [])

  function report(err: unknown, fallback: string) {
    setNotice(null)
    setError(err instanceof ApiError ? err.message : fallback)
  }

  function startConnect() {
    setError(null)
    setNotice(null)
    connect.mutate(undefined, {
      onSuccess: (data) => window.location.assign(data.authorization_url),
      onError: (err) => report(err, 'Could not start the Meta connection.'),
    })
  }

  function runDisconnect() {
    if (!window.confirm('Disconnect Meta? Synced Pages, Instagram accounts, ad accounts and campaign records will be cleared and permissions revoked. Draft and published post records are kept.')) {
      return
    }
    setError(null)
    setNotice(null)
    disconnect.mutate(undefined, {
      onSuccess: () => setNotice('Meta account disconnected and synced assets cleared.'),
      onError: (err) => report(err, 'Could not disconnect the Meta account.'),
    })
  }

  function runSync(type: MetaSyncType) {
    setError(null)
    setNotice(null)
    setSyncing(type)
    sync.mutate(type, {
      onSuccess: (data) => {
        setNotice(`Queued ${data.runs.length} sync run${data.runs.length === 1 ? '' : 's'}. Refresh this page to see results.`)
      },
      onError: (err) => report(err, 'Could not queue the Meta sync.'),
      onSettled: () => setSyncing(null),
    })
  }

  if (integration.isLoading) return <PageLoader label="Loading Meta integration…" />

  if (integration.isError) {
    return <Alert kind="error">{(integration.error as ApiError).message}</Alert>
  }

  const status = integration.data
  const connected = status?.connection?.status === 'connected'
  const canSync = status?.configured === true && connected

  return (
    <div className="space-y-6" data-testid="meta-integration-page">
      <PageHeader
        title="Meta integrations"
        description="Connect Facebook Pages, Instagram and ad accounts with Facebook Login. Publishing always requires approval by a second user first."
        actions={
          status && (
            status.configured ? (
              <div className="flex items-center gap-2">
                <Button
                  variant={connected ? 'secondary' : 'primary'}
                  loading={connect.isPending}
                  onClick={startConnect}
                  data-testid="meta-connect-button"
                >
                  {connected ? 'Reconnect Meta' : 'Connect Meta'}
                </Button>
                {connected && (
                  <Button
                    variant="danger"
                    loading={disconnect.isPending}
                    onClick={runDisconnect}
                    data-testid="meta-disconnect-button"
                  >
                    Disconnect
                  </Button>
                )}
              </div>
            ) : undefined
          )
        }
      />

      {callback && (
        <div data-testid="meta-callback-banner">
          <Alert kind={callback.kind === 'success' ? 'success' : 'error'}>{callback.text}</Alert>
        </div>
      )}
      {error && <Alert kind="error">{error}</Alert>}
      {notice && !error && <Alert kind="success">{notice}</Alert>}

      {status && !status.configured && (
        <div data-testid="meta-config-missing">
          <Alert kind="info">
            <p className="font-medium">Owner setup required</p>
            <p className="mt-1">
              Meta integration is not configured. An owner must create a Meta app with Facebook Login, complete app
              review for the publishing permissions and set
              <code className="mx-1 rounded bg-white px-1">META_APP_ID</code>,
              <code className="mx-1 rounded bg-white px-1">META_APP_SECRET</code> and
              <code className="mx-1 rounded bg-white px-1">META_REDIRECT_URI</code> on the backend. Until then no
              connection can be started and no Meta data is shown.
            </p>
          </Alert>
        </div>
      )}

      <Card
        title="Connection"
        actions={
          status?.connection && (
            <span data-testid="meta-connection-status">
              <Badge tone={toneFor(status.connection.status)}>{status.connection.status}</Badge>
            </span>
          )
        }
      >
        {status?.connection ? (
          <dl className="grid grid-cols-1 gap-4 text-sm sm:grid-cols-2 xl:grid-cols-4">
            <div>
              <dt className="text-xs font-medium uppercase tracking-wide text-gray-500">Account</dt>
              <dd className="mt-1 font-medium text-gray-900">{status.connection.account_label ?? 'Meta account'}</dd>
            </div>
            <div>
              <dt className="text-xs font-medium uppercase tracking-wide text-gray-500">Connected by</dt>
              <dd className="mt-1 text-gray-700">{status.connection.connected_by ?? '—'}</dd>
            </div>
            <div>
              <dt className="text-xs font-medium uppercase tracking-wide text-gray-500">Last successful sync</dt>
              <dd className="mt-1 text-gray-700">{formatDate(status.connection.last_synced_at)}</dd>
            </div>
            <div>
              <dt className="text-xs font-medium uppercase tracking-wide text-gray-500">Token expires</dt>
              <dd className="mt-1 text-gray-700">{formatDate(status.connection.expires_at)}</dd>
            </div>
            {status.connection.last_error && (
              <div className="sm:col-span-2 xl:col-span-4">
                <Alert kind="error">{status.connection.last_error}</Alert>
              </div>
            )}
            <div className="sm:col-span-2 xl:col-span-4">
              <dt className="text-xs font-medium uppercase tracking-wide text-gray-500">Granted scopes</dt>
              <dd className="mt-1 flex flex-wrap gap-1">
                {status.connection.scopes.length === 0 && <span className="text-gray-500">No scopes recorded.</span>}
                {status.connection.scopes.map((scope) => (
                  <Badge key={scope} tone="blue">{scope}</Badge>
                ))}
              </dd>
            </div>
          </dl>
        ) : (
          <EmptyState
            title="No Meta account connected"
            description={
              status?.configured
                ? 'Use Connect Meta to grant Page management, Instagram publishing, insights and ads read access.'
                : 'Connection stays unavailable until an owner configures the Meta app credentials.'
            }
          />
        )}
      </Card>

      <div className="grid grid-cols-1 gap-4 xl:grid-cols-2">
        <Card title="Capabilities">
          <ul className="space-y-3 text-sm" data-testid="meta-capabilities">
            {[
              {
                label: 'Facebook Pages',
                detail: 'Connected Pages and their Instagram business accounts.',
                available: status?.capabilities.pages ?? false,
              },
              {
                label: 'Publishing',
                detail: 'Approved posts publish to the Page feed or Instagram feed.',
                available: status?.capabilities.publishing ?? false,
              },
              {
                label: 'Insights',
                detail: 'Page and post impressions sync from Meta only.',
                available: status?.capabilities.insights ?? false,
              },
              {
                label: 'Ad reporting',
                detail: 'Ad accounts, campaigns, budgets and spend are read-only imports.',
                available: status?.capabilities.ad_reporting ?? false,
              },
            ].map((item) => (
              <li key={item.label} className="flex items-start justify-between gap-3">
                <div>
                  <p className="font-medium text-gray-900">{item.label}</p>
                  <p className="text-gray-500">{item.detail}</p>
                </div>
                <Badge tone={item.available ? 'green' : 'gray'}>{item.available ? 'Supported' : 'Unavailable'}</Badge>
              </li>
            ))}
            <li className="flex items-start justify-between gap-3" data-testid="meta-spend-actions-unavailable">
              <div>
                <p className="font-medium text-gray-900">Ad spend changes</p>
                <p className="text-gray-500">
                  Campaigns, budgets and spend are imported for comparison only. BRATHAMATU never creates or changes
                  Meta ad spend from this admin.
                </p>
              </div>
              <Badge tone="red">Read-only</Badge>
            </li>
          </ul>
        </Card>

        <Card title="Synced data">
          <div className="grid grid-cols-2 gap-3">
            <StatCard label="Pages" value={status?.counts.pages ?? 0} hint={`${status?.counts.instagram_accounts ?? 0} Instagram accounts`} />
            <StatCard label="Ad accounts" value={status?.counts.ad_accounts ?? 0} hint={`${status?.counts.business_accounts ?? 0} business accounts`} />
            <StatCard label="Campaigns" value={status?.counts.campaigns ?? 0} hint="Imported ad campaign rows" />
            <StatCard label="Social posts" value={status?.counts.social_posts ?? 0} hint={`${status?.counts.published_posts ?? 0} published`} />
          </div>
          <p className="mt-4 text-sm text-gray-500">
            Draft and schedule posts from{' '}
            <a className="font-medium text-brand-700 underline" href="/platform/marketing/social">
              Social publishing
            </a>
            . Every post needs approval by a second user before it can publish.
          </p>
        </Card>
      </div>

      <Card title="Connected Pages">
        {(status?.assets.pages ?? []).length === 0 ? (
          <EmptyState
            title="No Pages imported"
            description={canSync ? 'Run a Pages & accounts sync to import connected Pages.' : 'Connect Meta and sync assets first.'}
          />
        ) : (
          <ul className="space-y-2 text-sm" data-testid="meta-pages">
            {(status?.assets.pages ?? []).map((page) => (
              <li key={page.id} className="flex flex-wrap items-center gap-2 border-b border-gray-100 pb-2 last:border-0">
                <span className="font-medium text-gray-900">{page.name}</span>
                <Badge tone="gray">{page.id}</Badge>
                {page.category && <span className="text-gray-500">{page.category}</span>}
                {page.instagram_accounts.map((account) => (
                  <Badge key={account.id} tone="blue">@{account.username}</Badge>
                ))}
              </li>
            ))}
          </ul>
        )}
      </Card>

      <Card
        title="Sync"
        actions={
          <Button
            variant="secondary"
            loading={syncing === 'all'}
            disabled={!canSync || syncing !== null}
            onClick={() => runSync('all')}
            data-testid="meta-sync-all"
          >
            Sync all
          </Button>
        }
      >
        <div className="flex flex-wrap gap-2">
          {syncButtons.map((button) => (
            <Button
              key={button.type}
              variant="secondary"
              loading={syncing === button.type}
              disabled={!canSync || syncing !== null}
              onClick={() => runSync(button.type)}
              data-testid={`meta-sync-${button.type}`}
            >
              {button.label}
            </Button>
          ))}
        </div>
        {!canSync && (
          <p className="mt-3 text-sm text-gray-500">
            {status?.configured
              ? 'Connect a Meta account before syncing.'
              : 'Syncing is unavailable until Meta app credentials are configured and an account is connected.'}
          </p>
        )}
      </Card>

      <Card title="Recent sync runs">
        <div data-testid="meta-runs">
          <Table
            headers={['Type', 'Status', 'Started', 'Finished', 'Imported', 'Error']}
            empty={<EmptyState title="No sync runs yet" description="Run a sync to import Meta Pages, insights and ad data." />}
          >
            {(status?.runs ?? []).map((run) => (
              <tr key={run.id} data-testid={`meta-run-${run.id}`}>
                <td className="whitespace-nowrap px-4 py-3 font-medium text-gray-900">{run.type.replaceAll('_', ' ')}</td>
                <td className="px-4 py-3"><Badge tone={toneFor(run.status)}>{run.status}</Badge></td>
                <td className="whitespace-nowrap px-4 py-3 text-gray-600">{formatDate(run.started_at)}</td>
                <td className="whitespace-nowrap px-4 py-3 text-gray-600">{formatDate(run.finished_at)}</td>
                <td className="px-4 py-3 text-gray-600">{countSummary(run.counts)}</td>
                <td className="px-4 py-3 text-gray-600">{run.error ?? '—'}</td>
              </tr>
            ))}
          </Table>
        </div>
      </Card>

      <Card title="Integration log">
        <div data-testid="meta-logs">
          {(status?.logs ?? []).length === 0 ? (
            <EmptyState title="No Meta activity recorded" description="Connection, publishing and sync events appear here." />
          ) : (
            <ul className="space-y-2 text-sm">
              {(status?.logs ?? []).map((log) => (
                <li key={`${log.event}-${log.created_at}-${log.message}`} className="flex flex-wrap items-start gap-2 border-b border-gray-100 pb-2 last:border-0">
                  <Badge tone={log.level === 'error' ? 'red' : 'gray'}>{log.level}</Badge>
                  <span className="font-medium text-gray-900">{log.event}</span>
                  <span className="text-gray-600">{log.message}</span>
                  <span className="ml-auto whitespace-nowrap text-xs text-gray-500">{formatDate(log.created_at)}</span>
                </li>
              ))}
            </ul>
          )}
        </div>
      </Card>

      {status?.redirect_uri && (
        <p className="text-xs text-gray-500">
          OAuth redirect URI: <code className="rounded bg-gray-100 px-1 py-0.5">{status.redirect_uri}</code>
        </p>
      )}
    </div>
  )
}
