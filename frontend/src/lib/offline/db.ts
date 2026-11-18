/**
 * Offline queue backed by IndexedDB.
 * Each item in the queue is a count operation that hasn't been synced yet.
 * The idempotency_key guarantees server-side deduplication.
 */

export type QueueItemType = 'entry' | 'exception' | 'unknown_product'
export type SyncStatus = 'queued' | 'syncing' | 'synced' | 'failed' | 'conflict'

export interface QueuedEntry {
  idempotency_key: string
  type: 'entry'
  variant_id: string
  raw_quantity: number
  raw_unit_id: string
  observations?: string[]
  notes?: string | null
  captured_at: string
  device_session_id?: string | null
}

export interface QueuedException {
  idempotency_key: string
  type: 'exception'
  exception_type: string
  type_detail: string
  description: string
  barcode?: string | null
  location?: string | null
}

export interface QueuedUnknownProduct {
  idempotency_key: string
  type: 'unknown_product'
  description: string
  barcode?: string | null
  name_guess?: string | null
  location?: string | null
}

export type QueueItem = QueuedEntry | QueuedException | QueuedUnknownProduct

export interface OfflineQueueRecord {
  id?: number
  idempotency_key: string
  assignment_id: string
  session_id: string
  item: QueueItem
  status: SyncStatus
  server_id?: string | null     // set once synced
  error?: string | null
  queued_at: string
  synced_at?: string | null
  attempts: number
}

const DB_NAME = 'brathamatu-count-offline'
const DB_VERSION = 1
const STORE = 'sync_queue'

let dbPromise: Promise<IDBDatabase> | null = null

function openDB(): Promise<IDBDatabase> {
  if (dbPromise) return dbPromise

  dbPromise = new Promise((resolve, reject) => {
    const req = indexedDB.open(DB_NAME, DB_VERSION)

    req.onupgradeneeded = (e) => {
      const db = (e.target as IDBOpenDBRequest).result
      if (!db.objectStoreNames.contains(STORE)) {
        const store = db.createObjectStore(STORE, { keyPath: 'id', autoIncrement: true })
        store.createIndex('by_session', ['assignment_id', 'session_id'], { unique: false })
        store.createIndex('by_key', 'idempotency_key', { unique: true })
        store.createIndex('by_status', 'status', { unique: false })
      }
    }

    req.onsuccess = (e) => resolve((e.target as IDBOpenDBRequest).result)
    req.onerror = () => { dbPromise = null; reject(req.error) }
  })

  return dbPromise
}

function txStore(db: IDBDatabase, mode: IDBTransactionMode): IDBObjectStore {
  return db.transaction(STORE, mode).objectStore(STORE)
}

export async function enqueue(record: Omit<OfflineQueueRecord, 'id'>): Promise<void> {
  const db = await openDB()

  return new Promise((resolve, reject) => {
    const store = txStore(db, 'readwrite')

    // Check if already queued by idempotency key
    const getReq = store.index('by_key').get(record.idempotency_key)
    getReq.onsuccess = () => {
      if (getReq.result) {
        // Already queued — update if still in queued/failed state
        const existing = getReq.result as OfflineQueueRecord
        if (existing.status === 'queued' || existing.status === 'failed') {
          const updateStore = txStore(db, 'readwrite')
          const putReq = updateStore.put({ ...existing, item: record.item, status: 'queued', error: null, attempts: 0 })
          putReq.onsuccess = () => resolve()
          putReq.onerror = () => reject(putReq.error)
        } else {
          resolve() // already synced or syncing
        }
        return
      }
      const putReq = store.put(record)
      putReq.onsuccess = () => resolve()
      putReq.onerror = () => reject(putReq.error)
    }
    getReq.onerror = () => reject(getReq.error)
  })
}

export async function getQueueForSession(assignmentId: string, sessionId: string): Promise<OfflineQueueRecord[]> {
  const db = await openDB()

  return new Promise((resolve, reject) => {
    const store = txStore(db, 'readonly')
    const req = store.index('by_session').getAll([assignmentId, sessionId])
    req.onsuccess = () => resolve(req.result as OfflineQueueRecord[])
    req.onerror = () => reject(req.error)
  })
}

export async function getPendingItems(assignmentId: string, sessionId: string): Promise<OfflineQueueRecord[]> {
  const all = await getQueueForSession(assignmentId, sessionId)
  return all.filter((r) => r.status === 'queued' || r.status === 'failed')
}

export async function updateRecord(id: number, updates: Partial<OfflineQueueRecord>): Promise<void> {
  const db = await openDB()

  return new Promise((resolve, reject) => {
    const store = txStore(db, 'readwrite')
    const getReq = store.get(id)
    getReq.onsuccess = () => {
      if (!getReq.result) { resolve(); return }
      const putReq = store.put({ ...getReq.result, ...updates })
      putReq.onsuccess = () => resolve()
      putReq.onerror = () => reject(putReq.error)
    }
    getReq.onerror = () => reject(getReq.error)
  })
}

export async function markSynced(idempotencyKey: string, serverId: string): Promise<void> {
  const db = await openDB()

  return new Promise((resolve, reject) => {
    const store = txStore(db, 'readwrite')
    const req = store.index('by_key').get(idempotencyKey)
    req.onsuccess = () => {
      if (!req.result) { resolve(); return }
      const putReq = store.put({
        ...req.result, status: 'synced' as SyncStatus, server_id: serverId, synced_at: new Date().toISOString(),
      })
      putReq.onsuccess = () => resolve()
      putReq.onerror = () => reject(putReq.error)
    }
    req.onerror = () => reject(req.error)
  })
}

export async function markFailed(idempotencyKey: string, error: string): Promise<void> {
  const db = await openDB()

  return new Promise((resolve, reject) => {
    const store = txStore(db, 'readwrite')
    const req = store.index('by_key').get(idempotencyKey)
    req.onsuccess = () => {
      if (!req.result) { resolve(); return }
      const existing = req.result as OfflineQueueRecord
      const putReq = store.put({ ...existing, status: 'failed' as SyncStatus, error, attempts: (existing.attempts ?? 0) + 1 })
      putReq.onsuccess = () => resolve()
      putReq.onerror = () => reject(putReq.error)
    }
    req.onerror = () => reject(req.error)
  })
}

export async function clearSyncedForSession(assignmentId: string, sessionId: string): Promise<void> {
  const db = await openDB()
  const records = await getQueueForSession(assignmentId, sessionId)
  const synced = records.filter((r) => r.status === 'synced')

  return new Promise((resolve, reject) => {
    const store = txStore(db, 'readwrite')
    let done = 0
    if (synced.length === 0) { resolve(); return }
    synced.forEach((r) => {
      const req = store.delete(r.id!)
      req.onsuccess = () => { done++; if (done === synced.length) resolve() }
      req.onerror = () => reject(req.error)
    })
  })
}
