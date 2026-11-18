import { useParams } from '@tanstack/react-router'
import { useState } from 'react'
import { Badge, Button, Card, Table } from '../../components/ui'
import { useCurrentUser } from '../../lib/auth/hooks'
import {
  useTenantSchedule,
  useGenerateScheduleOccurrences,
  useTriggerOnDemandSchedule,
  useSkipScheduleOccurrence,
  useUpdateTenantSchedule,
} from '../../lib/schedule/hooks'

export function ScheduleDetailPage() {
  const params = useParams({ strict: false }) as { scheduleId?: string }
  const { data: user } = useCurrentUser()
  const tenantId = user?.current_tenant?.id

  const scheduleQuery = useTenantSchedule(tenantId, params.scheduleId)
  const schedule = scheduleQuery.data
  const occurrences = schedule?.occurrences ?? []

  const generate = useGenerateScheduleOccurrences(tenantId, params.scheduleId)
  const trigger = useTriggerOnDemandSchedule(tenantId, params.scheduleId)
  const skip = useSkipScheduleOccurrence(tenantId, params.scheduleId)
  const update = useUpdateTenantSchedule(tenantId, params.scheduleId)

  const [count, setCount] = useState(3)
  const [skipReason, setSkipReason] = useState('')
  const [skipTarget, setSkipTarget] = useState<string | null>(null)

  if (scheduleQuery.isLoading) return <div className="py-16 text-center text-gray-500">Loading schedule…</div>
  if (!schedule) return <div className="py-16 text-center text-gray-500">Schedule not found.</div>

  return (
    <div className="space-y-6">
      <Card>
        <div className="px-5 py-4">
          <h2 className="text-lg font-semibold text-gray-900">{schedule.title}</h2>
          {schedule.description && <p className="text-sm text-gray-500 mt-1">{schedule.description}</p>}
          <div className="mt-4 grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
            <div className="flex flex-col"><span className="text-xs text-gray-500 uppercase">Mode</span><Badge tone="gray">{schedule.mode}</Badge></div>
            <div className="flex flex-col"><span className="text-xs text-gray-500 uppercase">Frequency</span><span>{schedule.frequency.replace('_', ' ')}</span></div>
            <div className="flex flex-col"><span className="text-xs text-gray-500 uppercase">Status</span><Badge tone={schedule.active ? 'green' : 'gray'}>{schedule.active ? 'Active' : 'Paused'}</Badge></div>
            <div className="flex flex-col"><span className="text-xs text-gray-500 uppercase">Next occurrence</span><span>{schedule.next_occurrence_at ? new Date(schedule.next_occurrence_at).toLocaleString('en-KE') : '—'}</span></div>
          </div>
          <div className="mt-5 flex gap-3">
            <Button onClick={() => update.mutate({ active: !schedule.active })} disabled={update.isPending} variant={schedule.active ? 'secondary' : 'primary'}>
              {schedule.active ? 'Pause schedule' : 'Resume schedule'}
            </Button>
            {schedule.frequency !== 'on_demand' && (
              <>
                <div className="flex items-center gap-2"><label className="text-xs text-gray-500">Generate</label><input type="number" min={1} max={12} value={count} onChange={(e) => setCount(Number(e.target.value) || 1)} className="w-16 rounded-lg border border-gray-300 bg-white px-2 py-1.5 text-sm focus:outline-2 focus:outline-brand-600" /></div>
                <Button onClick={() => generate.mutate({ count })} disabled={generate.isPending}>Generate occurrences</Button>
              </>
            )}
            {schedule.frequency === 'on_demand' && <Button onClick={() => trigger.mutate()} disabled={trigger.isPending}>Trigger now</Button>}
          </div>
          {generate.isError && <div className="mt-3 rounded-lg border border-red-200 bg-red-50 text-red-800 px-3 py-2 text-sm">{generate.error instanceof Error ? generate.error.message : 'Failed to generate occurrences.'}</div>}
        </div>
      </Card>
      <Card>
        <div className="overflow-x-auto">
          <div className="px-5 py-3 border-b border-gray-100"><h3 className="text-sm font-semibold text-gray-900 uppercase tracking-wider">Occurrences ({occurrences.length})</h3></div>
          <Table headers={['Scheduled at', 'Status', 'Skip reason', 'Actions']} children={
            occurrences.length === 0 ? '' : occurrences.map((occ) => (
              <tr key={occ.id} className="hover:bg-gray-50">
                <td className="px-4 py-3">{new Date(occ.scheduled_at).toLocaleString('en-KE')}</td>
                <td className="px-4 py-3"><Badge tone={occ.status === 'pending' ? 'amber' : occ.status === 'skipped' ? 'gray' : 'green'}>{occ.status.replace('_', ' ')}</Badge></td>
                <td className="px-4 py-3">{occ.skip_reason ?? '—'}</td>
                <td className="px-4 py-3">{occ.status === 'pending' && <Button variant="danger" onClick={() => setSkipTarget(occ.id)} disabled={skip.isPending}>Skip</Button>}</td>
              </tr>
            ))
          } empty={occurrences.length === 0 ? <tr><td colSpan={4} className="px-4 py-8 text-center text-gray-500">No occurrences yet. Generate some above.</td></tr> : undefined} />
        </div>
      </Card>
      {skipTarget && (
        <Card>
          <div className="mt-4 px-5 py-4">
            <h3 className="text-sm font-semibold text-gray-900">Skip occurrence</h3>
            <input className="block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:outline-2 focus:outline-brand-600 mb-4" value={skipReason} onChange={(e) => setSkipReason(e.target.value)} placeholder="Reason…" />
            <div className="flex items-center justify-end gap-3">
              <Button variant="secondary" onClick={() => setSkipTarget(null)}>Cancel</Button>
              <Button onClick={() => { skip.mutate({ occurrenceId: skipTarget!, reason: skipReason }); setSkipTarget(null); setSkipReason('') }} disabled={skip.isPending || !skipReason.trim()}>Confirm skip</Button>
            </div>
          </div>
        </Card>
      )}
      {skip.isError && <div className="rounded-lg border border-red-200 bg-red-50 text-red-800 px-4 py-3 text-sm">{skip.error instanceof Error ? skip.error.message : 'Failed to skip occurrence.'}</div>}
    </div>
  )
}