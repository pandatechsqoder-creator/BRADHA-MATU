import type { ReactNode } from 'react'
import { Badge, Button } from '../../components/ui'
import { ApiError } from '../../lib/api/client'
import { formatStatusLabel } from '../../lib/formatting'
import type { StocktakeTimelineEntry } from '../../lib/api/stocktake'

export function describeError(error: unknown): string {
  if (error instanceof ApiError) {
    const first = Object.values(error.details)[0]?.[0]
    return first ?? error.message
  }
  if (error instanceof Error && error.message) return error.message
  return 'The change could not be saved. Try again.'
}

export function statusTone(status: string): 'gray' | 'green' | 'red' | 'amber' {
  if (['approved_for_execution', 'awaiting_assignment', 'closed', 'completion_confirmed', 'paid'].includes(status)) return 'green'
  if (['cancelled', 'rejected', 'refunded', 'failed', 'disputed', 'overdue'].includes(status)) return 'red'
  if (
    [
      'awaiting_deposit', 'deposit_pending_verification', 'submitted', 'under_review',
      'needs_tenant_information', 'quote_issued', 'awaiting_quote_acceptance',
      'awaiting_balance_payment', 'pending', 'partially_paid', 'required',
    ].includes(status)
  ) {
    return 'amber'
  }
  return 'gray'
}

export function StatusBadge({ status, label }: { status: string; label?: string }) {
  return <Badge tone={statusTone(status)}>{label ?? formatStatusLabel(status)}</Badge>
}

export function PriorityBadge({ priority }: { priority: string }) {
  const tone = priority === 'critical' ? 'red' : priority === 'high' ? 'amber' : 'gray'
  return <Badge tone={tone}>{priority}</Badge>
}

export function SummaryRow({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="flex items-start justify-between gap-4 text-sm">
      <dt className="text-gray-500">{label}</dt>
      <dd className="text-right font-medium text-gray-900">{children}</dd>
    </div>
  )
}

export function Pager({
  meta,
  onChange,
}: {
  meta: { current_page: number; last_page: number; total: number }
  onChange: (page: number) => void
}) {
  return (
    <div className="flex items-center justify-between text-xs text-gray-500">
      <span>
        Page {meta.current_page} of {meta.last_page} · {meta.total} requests
      </span>
      <div className="flex gap-2">
        <Button variant="secondary" disabled={meta.current_page <= 1} onClick={() => onChange(meta.current_page - 1)}>
          Previous
        </Button>
        <Button variant="secondary" disabled={meta.current_page >= meta.last_page} onClick={() => onChange(meta.current_page + 1)}>
          Next
        </Button>
      </div>
    </div>
  )
}

export function Timeline({ entries }: { entries: StocktakeTimelineEntry[] }) {
  if (entries.length === 0) {
    return <p className="text-sm text-gray-500">No status history recorded yet.</p>
  }
  return (
    <ol className="space-y-4">
      {entries.map((entry) => (
        <li key={entry.id} className="flex gap-3">
          <span className="mt-1 h-2 w-2 shrink-0 rounded-full bg-brand-500" aria-hidden />
          <div>
            <p className="text-sm font-medium text-gray-900">{entry.to_status_label}</p>
            <p className="text-xs text-gray-500">
              {entry.actor ?? entry.actor_type} · {entry.created_at ? new Date(entry.created_at).toLocaleString('en-KE') : '—'}
            </p>
            {entry.reason && <p className="mt-1 text-sm text-gray-600">{entry.reason}</p>}
          </div>
        </li>
      ))}
    </ol>
  )
}

export function toDateInput(iso: string | null | undefined): string {
  if (!iso) return ''
  const date = new Date(iso)
  if (Number.isNaN(date.getTime())) return ''
  return date.toISOString().slice(0, 16)
}

export function fromDateInput(value: string): string | null {
  if (!value) return null
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return null
  return date.toISOString()
}

export function splitList(value: string): string[] {
  return value
    .split(',')
    .map((part) => part.trim())
    .filter(Boolean)
}
