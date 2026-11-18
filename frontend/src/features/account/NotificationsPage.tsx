import { useEffect, useState } from 'react'
import { Alert, Badge, Button, Card, EmptyState, PageHeader, Select } from '../../components/ui'
import { ApiError } from '../../lib/api/client'
import { formatDateTime } from '../../lib/formatting'
import {
  useMarkAllNotificationsRead,
  useMarkNotificationRead,
  useNotificationPreferences,
  useNotifications,
  useUpdateNotificationPreferences,
} from '../../lib/reporting/hooks'

const EVENT_LABELS: Record<string, string> = {
  stocktake_updates: 'Stock-taking updates',
  pos_alerts: 'POS alerts',
  finance: 'Finance & payments',
  support: 'Support tickets',
  reports: 'Reports',
  security: 'Security',
}

const DIGEST_LABELS: Record<string, string> = {
  instant: 'Send instantly',
  daily: 'Daily digest',
  weekly: 'Weekly digest',
  off: 'Off',
}

function errorText(error: unknown, fallback: string): string {
  return error instanceof ApiError ? error.message : fallback
}

export function NotificationsPage() {
  const [filter, setFilter] = useState<'all' | 'unread'>('all')
  const [error, setError] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)

  const notifications = useNotifications(filter === 'unread' ? 'unread' : undefined)
  const markRead = useMarkNotificationRead()
  const markAll = useMarkAllNotificationsRead()

  const preferences = useNotificationPreferences()
  const updatePreferences = useUpdateNotificationPreferences()

  const [inApp, setInApp] = useState(true)
  const [email, setEmail] = useState(true)
  const [digest, setDigest] = useState('instant')
  const [events, setEvents] = useState<Record<string, boolean>>({})

  useEffect(() => {
    if (!preferences.data) return
    setInApp(preferences.data.in_app_enabled)
    setEmail(preferences.data.email_enabled)
    setDigest(preferences.data.digest_frequency)
    setEvents(
      Object.fromEntries(
        preferences.data.events.map((event) => [
          event,
          preferences.data?.event_preferences?.[event] !== false,
        ]),
      ),
    )
  }, [preferences.data])

  function savePreferences() {
    setError(null)
    setNotice(null)
    updatePreferences.mutate(
      {
        in_app_enabled: inApp,
        email_enabled: email,
        digest_frequency: digest,
        event_preferences: events,
      },
      {
        onSuccess: () => setNotice('Notification preferences saved.'),
        onError: (err) => setError(errorText(err, 'Could not save preferences.')),
      },
    )
  }

  const unread = notifications.data?.unread_count ?? 0

  return (
    <div className="mx-auto max-w-4xl space-y-6">
      <PageHeader
        title="Notifications"
        description="Your in-app notification centre and delivery preferences."
        actions={
          <div className="flex items-center gap-2">
            <Badge tone={unread > 0 ? 'amber' : 'gray'}>{unread} unread</Badge>
            <Button
              variant="secondary"
              loading={markAll.isPending}
              disabled={unread === 0}
              onClick={() =>
                markAll.mutate(undefined, {
                  onError: (err) => setError(errorText(err, 'Could not mark notifications as read.')),
                })
              }
            >
              Mark all read
            </Button>
          </div>
        }
      />

      {error && <Alert kind="error">{error}</Alert>}
      {notice && !error && <Alert kind="success">{notice}</Alert>}

      <div className="flex gap-2">
        <button
          type="button"
          onClick={() => setFilter('all')}
          className={`rounded-lg px-3 py-1.5 text-sm font-medium ${
            filter === 'all' ? 'bg-brand-700 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'
          }`}
        >
          All
        </button>
        <button
          type="button"
          onClick={() => setFilter('unread')}
          className={`rounded-lg px-3 py-1.5 text-sm font-medium ${
            filter === 'unread' ? 'bg-brand-700 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200'
          }`}
        >
          Unread
        </button>
      </div>

      <Card title="Notification centre">
        {(notifications.data?.notifications.length ?? 0) === 0 ? (
          <EmptyState
            title={filter === 'unread' ? 'You are all caught up' : 'No notifications yet'}
            description="Ticket replies, report exports, stock-take updates and finance events will show here."
          />
        ) : (
          <ul className="divide-y divide-gray-100" data-testid="notification-list">
            {notifications.data?.notifications.map((notification) => (
              <li key={notification.id} className="flex items-start justify-between gap-4 py-3">
                <div className="min-w-0">
                  <div className="flex flex-wrap items-center gap-2">
                    <p className={`text-sm ${notification.read_at ? 'font-medium text-gray-700' : 'font-semibold text-gray-900'}`}>
                      {notification.title}
                    </p>
                    <Badge tone={notification.read_at ? 'gray' : 'green'}>
                      {notification.read_at ? 'read' : 'new'}
                    </Badge>
                  </div>
                  {notification.body && <p className="mt-1 text-sm text-gray-600">{notification.body}</p>}
                  <p className="mt-1 text-xs text-gray-500">
                    {EVENT_LABELS[notification.type] ?? notification.type} ·{' '}
                    {notification.created_at ? formatDateTime(notification.created_at) : ''}
                  </p>
                </div>
                {!notification.read_at && (
                  <Button
                    variant="secondary"
                    onClick={() =>
                      markRead.mutate(notification.id, {
                        onError: (err) => setError(errorText(err, 'Could not mark the notification as read.')),
                      })
                    }
                    loading={markRead.isPending}
                  >
                    Mark read
                  </Button>
                )}
              </li>
            ))}
          </ul>
        )}
      </Card>

      <Card title="Preferences">
        <div className="space-y-4">
          <label className="flex items-center justify-between gap-4 rounded-lg border border-gray-200 px-4 py-3">
            <span>
              <span className="block text-sm font-medium text-gray-800">In-app notifications</span>
              <span className="block text-xs text-gray-500">Show alerts in this notification centre.</span>
            </span>
            <input type="checkbox" checked={inApp} onChange={(event) => setInApp(event.target.checked)} />
          </label>
          <label className="flex items-center justify-between gap-4 rounded-lg border border-gray-200 px-4 py-3">
            <span>
              <span className="block text-sm font-medium text-gray-800">Email notifications</span>
              <span className="block text-xs text-gray-500">Receive email copies for the events you select.</span>
            </span>
            <input type="checkbox" checked={email} onChange={(event) => setEmail(event.target.checked)} />
          </label>
          <Select label="Email digest" value={digest} onChange={(event) => setDigest(event.target.value)}>
            {(preferences.data?.digest_frequencies ?? Object.keys(DIGEST_LABELS)).map((frequency) => (
              <option key={frequency} value={frequency}>
                {DIGEST_LABELS[frequency] ?? frequency}
              </option>
            ))}
          </Select>

          <div>
            <p className="text-sm font-medium text-gray-800">Events</p>
            <div className="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-2">
              {(preferences.data?.events ?? []).map((event) => (
                <label key={event} className="flex items-center gap-2 rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-700">
                  <input
                    type="checkbox"
                    checked={events[event] !== false}
                    onChange={(changeEvent) =>
                      setEvents((current) => ({ ...current, [event]: changeEvent.target.checked }))
                    }
                  />
                  {EVENT_LABELS[event] ?? event}
                </label>
              ))}
            </div>
          </div>

          <Button onClick={savePreferences} loading={updatePreferences.isPending}>
            Save preferences
          </Button>
        </div>
      </Card>
    </div>
  )
}
