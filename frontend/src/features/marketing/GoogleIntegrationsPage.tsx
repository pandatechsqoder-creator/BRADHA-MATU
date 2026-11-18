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
  useConnectGoogle,
  useDisconnectGoogle,
  useGoogleIntegration,
  useSyncGoogle,
  type GoogleSyncType,
} from '../../lib/marketing/googleHooks'

const syncButtons: { type: GoogleSyncType; label: string }[] = [
  { type: 'search_console', label: 'Sync Search Console' },
  { type: 'ga4', label: 'Sync Analytics (GA4)' },
  { type: 'business', label: 'Sync Business Profile' },
  { type: 'gtm', label: 'Sync Tag Manager' },
]

function toneFor(status: string): 'green' | 'red' | 'amber' | 'blue' | 'gray' {
  switch (status) {
    case 'connected':
    case 'success':
      return 'green'
    case 'failed':
    case 'expired':
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
      return 'Google sign-in was cancelled. No connection was created.'
    case 'google_oauth_state_invalid':
      return 'This Google sign-in link is invalid or has already been used. Start the connection again.'
    case 'google_oauth_failed':
      return 'Google sign-in could not be completed. Check the OAuth client, redirect URI and scopes, then try again.'
    case 'google_not_configured':
      return 'Google integration is not configured yet. An owner must add the OAuth client credentials first.'
    default:
      return reason
        ? `Google sign-in failed (${reason}). Try connecting again.`
        : 'Google sign-in failed. Try connecting again.'
  }
}

function countSummary(counts: Record<string, number>): string {
  const entries = Object.entries(counts)
  if (entries.length === 0) return '—'
  return entries.map(([key, value]) => `${key.replaceAll('_', ' ')}: ${value.toLocaleString()}`).join(' · ')
}

export function GoogleIntegrationsPage() {
  const integration = useGoogleIntegration()
  const connect = useConnectGoogle()
  const disconnect = useDisconnectGoogle()
  const sync = useSyncGoogle()

  const [callback, setCallback] = useState<{ kind: 'success' | 'error'; text: string } | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)
  const [syncing, setSyncing] = useState<GoogleSyncType | null>(null)

  useEffect(() => {
    const params = new URLSearchParams(window.location.search)
    const flag = params.get('google')
    if (flag === 'connected') {
      setCallback({ kind: 'success', text: 'Google account connected. Run a sync to import your data.' })
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
      onError: (err) => report(err, 'Could not start the Google connection.'),
    })
  }

  function runDisconnect() {
    if (!window.confirm('Disconnect Google? Synced Search Console, Analytics, Business Profile and Tag Manager data will be cleared and the token revoked.')) {
      return
    }
    setError(null)
    setNotice(null)
    disconnect.mutate(undefined, {
      onSuccess: () => setNotice('Google account disconnected and synced data cleared.'),
      onError: (err) => report(err, 'Could not disconnect the Google account.'),
    })
  }

  function runSync(type: GoogleSyncType) {
    setError(null)
    setNotice(null)
    setSyncing(type)
    sync.mutate(type, {
      onSuccess: (data) => {
        setNotice(`Queued ${data.runs.length} sync run${data.runs.length === 1 ? '' : 's'}. Refresh this page to see results.`)
      },
      onError: (err) => report(err, 'Could not queue the Google sync.'),
      onSettled: () => setSyncing(null),
    })
  }

  if (integration.isLoading) return <PageLoader label="Loading Google integration…" />

  if (integration.isError) {
    return <Alert kind="error">{(integration.error as ApiError).message}</Alert>
  }

  const status = integration.data
  const connected = status?.connection?.status === 'connected'
  const canSync = status?.configured === true && connected

  return (
    <div className="space-y-6" data-testid="google-integration-page">
      <PageHeader
        title="Google integrations"
        description="Connect Search Console, GA4, Business Profile and Tag Manager with OAuth. Data is imported from Google only; nothing is estimated."
        actions={
          status && (
            status.configured ? (
              <div className="flex items-center gap-2">
                <Button
                  variant={connected ? 'secondary' : 'primary'}
                  loading={connect.isPending}
                  onClick={startConnect}
                  data-testid="google-connect-button"
                >
                  {connected ? 'Reconnect Google' : 'Connect Google'}
                </Button>
                {connected && (
                  <Button
                    variant="danger"
                    loading={disconnect.isPending}
                    onClick={runDisconnect}
                    data-testid="google-disconnect-button"
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
        <div data-testid="google-callback-banner">
          <Alert kind={callback.kind === 'success' ? 'success' : 'error'}>{callback.text}</Alert>
        </div>
      )}
      {error && <Alert kind="error">{error}</Alert>}
      {notice && !error && <Alert kind="success">{notice}</Alert>}

      {status && !status.configured && (
        <div data-testid="google-config-missing">
          <Alert kind="info">
            <p className="font-medium">Owner setup required</p>
            <p className="mt-1">
              Google integration is not configured. An owner must create a Google Cloud OAuth client and set
              <code className="mx-1 rounded bg-white px-1">GOOGLE_CLIENT_ID</code>,
              <code className="mx-1 rounded bg-white px-1">GOOGLE_CLIENT_SECRET</code> and
              <code className="mx-1 rounded bg-white px-1">GOOGLE_REDIRECT_URI</code> on the backend. Until then no
              connection can be started and no Google data is shown.
            </p>
          </Alert>
        </div>
      )}

      <Card
        title="Connection"
        actions={
          status?.connection && (
            <span data-testid="google-connection-status">
              <Badge tone={toneFor(status.connection.status)}>{status.connection.status}</Badge>
            </span>
          )
        }
      >
        {status?.connection ? (
          <dl className="grid grid-cols-1 gap-4 text-sm sm:grid-cols-2 xl:grid-cols-4">
            <div>
              <dt className="text-xs font-medium uppercase tracking-wide text-gray-500">Account</dt>
              <dd className="mt-1 font-medium text-gray-900">{status.connection.account_label ?? 'Google account'}</dd>
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
              <dt className="text-xs font-medium uppercase tracking-wide text-gray-500">Access token expires</dt>
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
                  <Badge key={scope} tone="blue">{scope.replace('https://www.googleapis.com/auth/', '')}</Badge>
                ))}
              </dd>
            </div>
          </dl>
        ) : (
          <EmptyState
            title="No Google account connected"
            description={
              status?.configured
                ? 'Use Connect Google to grant read-only Search Console, Analytics, Business Profile and Tag Manager access.'
                : 'Connection stays unavailable until an owner configures the Google OAuth client.'
            }
          />
        )}
      </Card>

      <div className="grid grid-cols-1 gap-4 xl:grid-cols-2">
        <Card title="Capabilities">
          <ul className="space-y-3 text-sm" data-testid="google-capabilities">
            {[
              {
                label: 'Search Console',
                detail: 'Verified properties and 28-day daily search performance.',
                available: status?.capabilities.search_console ?? false,
              },
              {
                label: 'Google Analytics 4',
                detail: 'Properties, sessions, users and conversions for the last 28 days.',
                available: status?.capabilities.ga4 ?? false,
              },
              {
                label: 'Business Profile',
                detail: 'Accounts and locations. Ratings and reviews are not imported.',
                available: status?.capabilities.business_profile ?? false,
              },
              {
                label: 'Tag Manager',
                detail: 'Containers and public container IDs for audit.',
                available: status?.capabilities.tag_manager ?? false,
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
            <li className="flex items-start justify-between gap-3" data-testid="google-reviews-unavailable">
              <div>
                <p className="font-medium text-gray-900">Business Profile reviews</p>
                <p className="text-gray-500">
                  The current Google API access does not expose reviews. No review totals, ratings or sentiment are
                  stored or displayed.
                </p>
              </div>
              <Badge tone="red">Unavailable</Badge>
            </li>
          </ul>
        </Card>

        <Card title="Synced data">
          <div className="grid grid-cols-2 gap-3">
            <StatCard label="Search properties" value={status?.counts.search_properties ?? 0} hint={`${status?.counts.search_metric_rows ?? 0} daily rows`} />
            <StatCard label="GA4 properties" value={status?.counts.ga4_properties ?? 0} hint={`${status?.counts.ga4_metric_rows ?? 0} daily rows`} />
            <StatCard label="Business accounts" value={status?.counts.business_accounts ?? 0} hint={`${status?.counts.business_locations ?? 0} locations`} />
            <StatCard label="Tag Manager containers" value={status?.counts.gtm_containers ?? 0} hint="Read-only container audit" />
          </div>
        </Card>
      </div>

      <Card
        title="Sync"
        actions={
          <Button
            variant="secondary"
            loading={syncing === 'all'}
            disabled={!canSync || syncing !== null}
            onClick={() => runSync('all')}
            data-testid="google-sync-all"
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
              data-testid={`google-sync-${button.type}`}
            >
              {button.label}
            </Button>
          ))}
        </div>
        {!canSync && (
          <p className="mt-3 text-sm text-gray-500">
            {status?.configured
              ? 'Connect a Google account before syncing.'
              : 'Syncing is unavailable until Google OAuth credentials are configured and an account is connected.'}
          </p>
        )}
      </Card>

      <Card title="Recent sync runs">
        <div data-testid="google-runs">
          <Table
            headers={['Type', 'Status', 'Started', 'Finished', 'Imported', 'Error']}
            empty={<EmptyState title="No sync runs yet" description="Run a sync to import your Google data." />}
          >
            {(status?.runs ?? []).map((run) => (
              <tr key={run.id} data-testid={`google-run-${run.id}`}>
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
        <div data-testid="google-logs">
          {(status?.logs ?? []).length === 0 ? (
            <EmptyState title="No Google activity recorded" description="Connection and sync events appear here." />
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
