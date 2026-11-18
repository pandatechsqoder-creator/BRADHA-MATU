import type { SyncState } from '../lib/offline/hooks'

interface Props {
  online: boolean
  syncState: SyncState
  onSync: () => void
}

export function OfflineBanner({ online, syncState, onSync }: Props) {
  if (online && syncState.pending === 0 && syncState.failedCount === 0 && syncState.conflictCount === 0) {
    return null
  }

  if (!online) {
    return (
      <div
        role="status"
        aria-live="polite"
        className="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800"
      >
        <div className="flex items-center gap-2">
          <span
            className="inline-block h-2 w-2 rounded-full bg-amber-500"
            aria-hidden="true"
          />
          <strong>You are offline.</strong> Entries are saved locally and will sync automatically when your connection returns.
          {syncState.pending > 0 && (
            <span className="ml-2 rounded-full bg-amber-200 px-2 py-0.5 text-xs font-semibold">
              {syncState.pending} pending
            </span>
          )}
        </div>
      </div>
    )
  }

  // Online but items pending or failed
  return (
    <div
      role="status"
      aria-live="polite"
      className="rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-800"
    >
      <div className="flex items-center justify-between gap-3">
        <div className="flex items-center gap-2">
          {syncState.syncing ? (
            <>
              <span className="inline-block h-2 w-2 animate-pulse rounded-full bg-blue-500" aria-hidden="true" />
              Syncing {syncState.pending} item{syncState.pending !== 1 ? 's' : ''}…
            </>
          ) : syncState.failedCount > 0 ? (
            <>
              <span className="inline-block h-2 w-2 rounded-full bg-red-500" aria-hidden="true" />
              <span className="text-red-700">
                {syncState.failedCount} item{syncState.failedCount !== 1 ? 's' : ''} failed to sync.
              </span>
            </>
          ) : syncState.conflictCount > 0 ? (
            <>
              <span className="inline-block h-2 w-2 rounded-full bg-amber-500" aria-hidden="true" />
              <span className="text-amber-700">
                {syncState.conflictCount} conflict{syncState.conflictCount !== 1 ? 's' : ''} — server value preserved.
              </span>
            </>
          ) : (
            <>
              <span className="inline-block h-2 w-2 rounded-full bg-blue-500" aria-hidden="true" />
              {syncState.pending} item{syncState.pending !== 1 ? 's' : ''} waiting to sync.
            </>
          )}
        </div>
        {!syncState.syncing && syncState.pending > 0 && (
          <button
            onClick={onSync}
            className="rounded-md bg-blue-100 px-2.5 py-1 text-xs font-semibold text-blue-800 hover:bg-blue-200 focus:outline-none focus:ring-2 focus:ring-blue-400"
          >
            Sync now
          </button>
        )}
        {syncState.lastSyncAt && syncState.pending === 0 && syncState.failedCount === 0 && (
          <span className="text-xs text-blue-600">
            Last synced {new Date(syncState.lastSyncAt).toLocaleTimeString()}
          </span>
        )}
      </div>
    </div>
  )
}
