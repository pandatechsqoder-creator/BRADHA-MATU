import type { SyncStatus } from '../lib/offline/db'

const configs: Record<SyncStatus, { label: string; classes: string; dot: string }> = {
  queued:   { label: 'Queued',   classes: 'bg-gray-100 text-gray-600',   dot: 'bg-gray-400' },
  syncing:  { label: 'Syncing',  classes: 'bg-blue-100 text-blue-700',   dot: 'bg-blue-500 animate-pulse' },
  synced:   { label: 'Synced',   classes: 'bg-green-100 text-green-700', dot: 'bg-green-500' },
  failed:   { label: 'Failed',   classes: 'bg-red-100 text-red-700',     dot: 'bg-red-500' },
  conflict: { label: 'Conflict', classes: 'bg-amber-100 text-amber-700', dot: 'bg-amber-500' },
}

export function SyncStatusBadge({ status }: { status: SyncStatus | null }) {
  if (!status || status === 'synced') return null

  const cfg = configs[status]
  return (
    <span className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-semibold ${cfg.classes}`}>
      <span className={`h-1.5 w-1.5 rounded-full ${cfg.dot}`} aria-hidden="true" />
      {cfg.label}
    </span>
  )
}
