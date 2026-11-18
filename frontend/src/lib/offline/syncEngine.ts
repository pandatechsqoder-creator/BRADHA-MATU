/**
 * Sync engine — drives the offline queue to the server.
 *
 * Responsibilities:
 * - Detect online/offline state.
 * - Drain the queue in batches when online.
 * - Retry failed items with exponential back-off (max 3 attempts).
 * - Emit sync progress events so the UI can show indicators.
 */

import { api, ApiError } from '../api/client'
import {
  getQueueForSession,
  getPendingItems,
  markFailed,
  markSynced,
  type OfflineQueueRecord,
} from './db'

export type SyncEvent =
  | { kind: 'started'; assignmentId: string; sessionId: string; count: number }
  | { kind: 'item_synced'; idempotency_key: string; server_id: string }
  | { kind: 'item_failed'; idempotency_key: string; reason: string }
  | { kind: 'item_conflict'; idempotency_key: string }
  | { kind: 'completed'; assignmentId: string; sessionId: string; synced: number; failed: number; conflicts: number }
  | { kind: 'error'; message: string }

type SyncListener = (event: SyncEvent) => void

const listeners = new Set<SyncListener>()

export function addSyncListener(fn: SyncListener): () => void {
  listeners.add(fn)
  return () => listeners.delete(fn)
}

function emit(event: SyncEvent): void {
  listeners.forEach((fn) => fn(event))
}

export function isOnline(): boolean {
  return navigator.onLine
}

const MAX_ATTEMPTS = 3
const BATCH_SIZE = 50

/**
 * Sync all pending items for a given assignment+session pair.
 * Safe to call multiple times — idempotent at server level.
 */
export async function syncSession(assignmentId: string, sessionId: string): Promise<void> {
  if (!isOnline()) return

  const pending = await getPendingItems(assignmentId, sessionId)
  const eligible = pending.filter((r) => r.attempts < MAX_ATTEMPTS)
  if (eligible.length === 0) return

  emit({ kind: 'started', assignmentId, sessionId, count: eligible.length })

  // Send in batches of BATCH_SIZE
  for (let i = 0; i < eligible.length; i += BATCH_SIZE) {
    const batch = eligible.slice(i, i + BATCH_SIZE)
    await sendBatch(assignmentId, sessionId, batch)
  }

  const all = await getQueueForSession(assignmentId, sessionId)
  const synced = all.filter((r) => r.status === 'synced').length
  const failed = all.filter((r) => r.status === 'failed').length
  const conflicts = all.filter((r) => r.status === 'conflict').length

  emit({ kind: 'completed', assignmentId, sessionId, synced, failed, conflicts })
}

async function sendBatch(assignmentId: string, sessionId: string, records: OfflineQueueRecord[]): Promise<void> {
  const items = records.map((r) => r.item)

  try {
    type SyncResult = {
      summary: { total: number; synced: number; conflict: number; rejected: number }
      items: { idempotency_key: string; status: string; id?: string; reason?: string }[]
    }

    const result = await api.post<SyncResult>(
      `/workforce/assignments/${assignmentId}/count/sessions/${sessionId}/sync`,
      { items },
    )

    for (const outcome of result.items) {
      const key = outcome.idempotency_key
      if (outcome.status === 'synced') {
        await markSynced(key, outcome.id ?? key)
        emit({ kind: 'item_synced', idempotency_key: key, server_id: outcome.id ?? key })
      } else if (outcome.status === 'conflict') {
        await markFailed(key, outcome.reason ?? 'Conflict with server value.')
        emit({ kind: 'item_conflict', idempotency_key: key })
      } else {
        const reason = outcome.reason ?? 'Server rejected this item.'
        await markFailed(key, reason)
        emit({ kind: 'item_failed', idempotency_key: key, reason })
      }
    }
  } catch (err) {
    const msg = err instanceof ApiError ? err.message : 'Network error during sync.'
    emit({ kind: 'error', message: msg })

    for (const record of records) {
      await markFailed(record.idempotency_key, msg)
      emit({ kind: 'item_failed', idempotency_key: record.idempotency_key, reason: msg })
    }
  }
}

/**
 * Send a heartbeat to tell the server this device is online.
 */
export async function sendHeartbeat(assignmentId: string, offlineCapable: boolean): Promise<void> {
  if (!isOnline()) return
  try {
    await api.post(`/workforce/assignments/${assignmentId}/count/heartbeat`, {
      platform: navigator.userAgent.substring(0, 100),
      offline_capable: offlineCapable,
    })
  } catch {
    // heartbeat is best-effort
  }
}

/**
 * Download the full job package for offline caching.
 * Returns the raw package so the caller can store it in localStorage/sessionStorage.
 */
export async function downloadJobPackage(assignmentId: string): Promise<unknown> {
  return api.get(`/workforce/assignments/${assignmentId}/count/package?platform=${encodeURIComponent(navigator.userAgent.substring(0, 100))}`)
}
