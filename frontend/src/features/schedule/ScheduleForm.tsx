import { useState } from 'react'
import { Button, Card, Field } from '../../components/ui'
import { ApiError } from '../../lib/api/client'
import {
  createScheduleInputSchema,
  type CreateScheduleInput,
} from '../../lib/api/schemas'
import { useCreateTenantSchedule } from '../../lib/schedule/hooks'

const MODES = [
  { value: 'reminder', label: 'Reminder only (no job created)' },
  { value: 'proposed', label: 'Proposed (tenant authorises)' },
] as const

const FREQUENCIES = [
  'weekly', 'fortnightly', 'monthly', 'quarterly', 'half_yearly', 'annual', 'on_demand',
] as const

interface ScheduleFormProps {
  tenantId: string
  onSuccess: () => void
  onCancel: () => void
}

export function ScheduleForm({ tenantId, onSuccess, onCancel }: ScheduleFormProps) {
  const [mode, setMode] = useState<CreateScheduleInput['mode']>('reminder')
  const [frequency, setFrequency] = useState<CreateScheduleInput['frequency']>('monthly')
  const [dayOfMonth, setDayOfMonth] = useState('1')
  const [startsAt, setStartsAt] = useState('')
  const [endsAt, setEndsAt] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [submitting, setSubmitting] = useState(false)
  const createMutation = useCreateTenantSchedule(tenantId)

  const showDOM = ['monthly', 'quarterly', 'half_yearly', 'annual'].includes(frequency)

  function handleSubmit(event: React.FormEvent) {
    event.preventDefault()
    setError(null)
    const form = event.target as HTMLFormElement
    const title = (form.elements.namedItem('title') as HTMLInputElement)?.value
    const desc = (form.elements.namedItem('description') as HTMLTextAreaElement)?.value || undefined

    const input: CreateScheduleInput = {
      title,
      mode,
      frequency,
      scope_type: 'all',
      recurrence_config: frequency === 'on_demand'
        ? {}
        : showDOM
          ? { day_of_month: Math.max(1, Math.min(31, Number(dayOfMonth) || 1)) }
          : {},
      starts_at: startsAt || undefined,
      ends_at: endsAt || undefined,
      ...(desc ? { description: desc } : {}),
    }

    const parsed = createScheduleInputSchema.safeParse(input)
    if (!parsed.success) {
      setError(parsed.error.issues.map((e) => e.message).join('. '))
      return
    }

    setSubmitting(true)
    createMutation.mutate(parsed.data, {
      onSuccess: () => { setSubmitting(false); onSuccess() },
      onError: (err) => { setSubmitting(false); setError(err instanceof ApiError ? err.message : 'Failed to create schedule.') },
    })
  }
  if (createMutation.isPending) setSubmitting(true)

  return (
    <Card>
      <header className="flex items-center justify-between border-b border-gray-100 px-5 py-4">
        <h2 className="text-sm font-semibold text-gray-900">New recurring stock-take</h2>
        <Button variant="secondary" onClick={onCancel}>Cancel</Button>
      </header>
      <form onSubmit={handleSubmit} className="px-5 py-4 space-y-4">
        <Field label="Title" id="title" required>
          <input name="title" className="block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm focus:outline-2 focus:outline-brand-600" required />
        </Field>
        <Field label="Description" id="description" hint="Optional internal description.">
          <textarea name="description" className="block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm focus:outline-2 focus:outline-brand-600" rows={2} />
        </Field>
        <Field label="Mode" id="mode">
          <select name="mode" value={mode} onChange={(e) => setMode(e.target.value as any)} className="block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm focus:outline-2 focus:outline-brand-600">
            {MODES.map((m) => <option key={m.value} value={m.value}>{m.label}</option>)}
          </select>
        </Field>
        <Field label="Frequency" id="frequency">
          <select name="frequency" value={frequency} onChange={(e) => setFrequency(e.target.value as any)} className="block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm focus:outline-2 focus:outline-brand-600">
            {FREQUENCIES.map((f) => <option key={f} value={f}>{f.replace('_', ' ')}</option>)}
          </select>
        </Field>
        {showDOM && (
          <Field label="Day of month" id="day_of_month" hint="Day 1-31.">
            <input name="day_of_month" type="number" min={1} max={31} defaultValue={dayOfMonth} onChange={(e) => setDayOfMonth(e.target.value)} className="block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm focus:outline-2 focus:outline-brand-600" />
          </Field>
        )}
        <Field label="Starts at" id="starts_at" hint="First scheduled date (optional).">
          <input name="starts_at" type="datetime-local" value={startsAt} onChange={(e) => setStartsAt(e.target.value)} className="block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm focus:outline-2 focus:outline-brand-600" />
        </Field>
        <Field label="Ends at" id="ends_at" hint="Optional final date (optional).">
          <input name="ends_at" type="datetime-local" value={endsAt} onChange={(e) => setEndsAt(e.target.value)} className="block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm focus:outline-2 focus:outline-brand-600" />
        </Field>
        {error && <p className="text-xs text-red-600">{error}</p>}
        <div className="flex items-center justify-end gap-3 pt-4">
          <Button type="button" variant="secondary" onClick={onCancel} disabled={submitting}>Cancel</Button>
          <Button type="submit" loading={submitting} disabled={submitting}>Create schedule</Button>
        </div>
      </form>
    </Card>
  )
}
