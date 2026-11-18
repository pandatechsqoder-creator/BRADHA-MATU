import { Badge } from '../../components/ui'
import { ApiError } from '../../lib/api/client'
import { formatStatusLabel } from '../../lib/formatting'

export const STAGE_LABELS: Record<string, string> = {
  awaiting_assignment: 'Awaiting assignment',
  awaiting_acceptance: 'Awaiting acceptance',
  awaiting_sop: 'Awaiting job SOP',
  awaiting_acknowledgement: 'SOP acknowledgement pending',
  awaiting_checklist: 'Checklist pending',
  awaiting_check_in: 'Awaiting site check-in',
  counting: 'Counting in progress',
  ready: 'Ready',
}

export function stageLabel(stage: string): string {
  return STAGE_LABELS[stage] ?? formatStatusLabel(stage)
}

export function StageBadge({ stage }: { stage: string }) {
  const tone = stage === 'counting' || stage === 'ready' ? 'green' : 'amber'
  return <Badge tone={tone}>{stageLabel(stage)}</Badge>
}

export function ProgressBar({ percent, label }: { percent: number; label?: string }) {
  return (
    <div className="space-y-1">
      <div
        className="h-2 w-full overflow-hidden rounded-full bg-gray-200"
        role="progressbar"
        aria-label={label ?? 'Readiness'}
        aria-valuenow={percent}
        aria-valuemin={0}
        aria-valuemax={100}
      >
        <div
          className="h-2 rounded-full bg-brand-600 transition-all"
          style={{ width: `${Math.min(100, Math.max(0, percent))}%` }}
        />
      </div>
    </div>
  )
}

export function describeError(error: unknown): string {
  if (error instanceof ApiError) {
    const first = Object.values(error.details)[0]?.[0]
    return first ?? error.message
  }
  if (error instanceof Error && error.message) return error.message
  return 'The change could not be saved. Try again.'
}
