/**
 * React hooks for the offline sync layer.
 */

import { useCallback, useEffect, useState } from 'react'
import {
  enqueue,
  getQueueForSession,
  type OfflineQueueRecord,
  type QueueItem,
  type SyncStatus,
} from './db'
import {
  addSyncListener,
  downloadJobPackage,
  isOnline,
  sendHeartbeat,
  syncSession,
} from './syncEngine'

/** Returns live online/offline status with event-driven updates. */
export function useOnlineStatus(): boolean {
  const [online, setOnline] = useState(isOnline())

  useEffect(() => {
    const on = () => setOnline(true)
    const off = () => setOnline(false)
    window.addEventListener('online', on)
    window.addEventListener('offline', off)
    return () => { window.removeEventListener('online', on); window.removeEventListener('offline', off) }
  }, [])

  return online
}

export interface SyncState {
  pending: number
  syncing: boolean
  lastSyncAt: string | null
  failedCount: number
  conflictCount: number
}

/**
 * Manages the offline sync lifecycle for a specific count session.
 * Automatically syncs when coming back online.
 */
export function useOfflineSync(assignmentId: string | null | undefined, sessionId: string | null | undefined) {
  const online = useOnlineStatus()
  const [state, setState] = useState<SyncState>({
    pending: 0, syncing: false, lastSyncAt: null, failedCount: 0, conflictCount: 0,
  })

  const aId = assignmentId ?? ''
  const sId = sessionId ?? ''
  const enabled = Boolean(aId && sId)

  // Refresh queue counts from IndexedDB
  const refreshCounts = useCallback(async () => {
    if (!enabled) return
    const records = await getQueueForSession(aId, sId)
    setState((prev) => ({
      ...prev,
      pending: records.filter((r) => r.status === 'queued').length,
      failedCount: records.filter((r) => r.status === 'failed').length,
      conflictCount: records.filter((r) => r.status === 'conflict').length,
    }))
  }, [aId, sId, enabled])

  // Listen to sync events
  useEffect(() => {
    return addSyncListener((event) => {
      if (event.kind === 'started') setState((prev) => ({ ...prev, syncing: true }))
      if (event.kind === 'completed') {
        setState((prev) => ({
          ...prev,
          syncing: false,
          lastSyncAt: new Date().toISOString(),
          pending: 0,
          failedCount: event.failed,
          conflictCount: event.conflicts,
        }))
      }
      if (event.kind === 'error') setState((prev) => ({ ...prev, syncing: false }))
    })
  }, [])

  // Auto-sync when coming online
  useEffect(() => {
    if (online && enabled) {
      void syncSession(aId, sId).then(refreshCounts)
    }
  }, [online, aId, sId, enabled, refreshCounts])

  // Periodic sync while online (every 15s)
  useEffect(() => {
    if (!enabled) return
    const id = setInterval(() => {
      if (isOnline()) void syncSession(aId, sId).then(refreshCounts)
    }, 15_000)
    return () => clearInterval(id)
  }, [aId, sId, enabled, refreshCounts])

  // Heartbeat every 60s
  useEffect(() => {
    if (!enabled || !aId) return
    const id = setInterval(() => {
      void sendHeartbeat(aId, true)
    }, 60_000)
    return () => clearInterval(id)
  }, [aId, enabled])

  // Initial count refresh
  useEffect(() => { void refreshCounts() }, [refreshCounts])

  const addToQueue = useCallback(
    async (item: QueueItem): Promise<void> => {
      if (!enabled) return
      await enqueue({
        idempotency_key: item.idempotency_key,
        assignment_id: aId,
        session_id: sId,
        item,
        status: 'queued',
        queued_at: new Date().toISOString(),
        attempts: 0,
      })
      await refreshCounts()
      // Try to sync immediately if online
      if (isOnline()) void syncSession(aId, sId).then(refreshCounts)
    },
    [aId, sId, enabled, refreshCounts],
  )

  const triggerSync = useCallback(() => {
    if (enabled && isOnline()) void syncSession(aId, sId).then(refreshCounts)
  }, [aId, sId, enabled, refreshCounts])

  return { state, online, addToQueue, triggerSync, refreshCounts }
}

/** Returns the per-item sync status from the local queue. */
export function useQueueStatus(
  assignmentId: string | null | undefined,
  sessionId: string | null | undefined,
  idempotencyKey: string,
): SyncStatus | null {
  const [status, setStatus] = useState<SyncStatus | null>(null)

  useEffect(() => {
    if (!assignmentId || !sessionId) return
    getQueueForSession(assignmentId, sessionId).then((records) => {
      const record = records.find((r) => r.idempotency_key === idempotencyKey) as OfflineQueueRecord | undefined
      if (record) setStatus(record.status)
    })

    return addSyncListener((event) => {
      if (
        (event.kind === 'item_synced' || event.kind === 'item_failed' || event.kind === 'item_conflict') &&
        event.idempotency_key === idempotencyKey
      ) {
        setStatus(event.kind === 'item_synced' ? 'synced' : event.kind === 'item_conflict' ? 'conflict' : 'failed')
      }
    })
  }, [assignmentId, sessionId, idempotencyKey])

  return status
}

/** Download and cache the job package for offline use. */
export function useJobPackageDownload(assignmentId: string | null | undefined) {
  const [downloading, setDownloading] = useState(false)
  const [downloaded, setDownloaded] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const download = useCallback(async () => {
    if (!assignmentId) return
    setDownloading(true)
    setError(null)
    try {
      const pkg = await downloadJobPackage(assignmentId)
      sessionStorage.setItem(`brathamatu.offline.package.${assignmentId}`, JSON.stringify(pkg))
      setDownloaded(true)
    } catch (err) {
      setError('Failed to download offline package. Check your connection and try again.')
    } finally {
      setDownloading(false)
    }
  }, [assignmentId])

  // Check if already downloaded
  useEffect(() => {
    if (assignmentId) {
      setDownloaded(Boolean(sessionStorage.getItem(`brathamatu.offline.package.${assignmentId}`)))
    }
  }, [assignmentId])

  return { download, downloading, downloaded, error }
}
