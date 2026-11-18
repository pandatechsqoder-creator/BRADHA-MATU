import { useNavigate } from '@tanstack/react-router'
import { useState } from 'react'
import { Badge, Button, Card, Table } from '../../components/ui'
import { useCurrentUser } from '../../lib/auth/hooks'
import { useTenantSchedules, useCreateTenantSchedule } from '../../lib/schedule/hooks'
import { ScheduleForm } from './ScheduleForm'

export function SchedulePage() {
  const navigate = useNavigate()
  const { data: user } = useCurrentUser()
  const tenantId = user?.current_tenant?.id
  const [creating, setCreating] = useState(false)

  const schedulesQuery = useTenantSchedules(tenantId)
  const createMutation = useCreateTenantSchedule(tenantId)
  const schedules = schedulesQuery.data ?? []

  if (schedulesQuery.isLoading) return <div className="py-16 text-center text-gray-500">Loading schedules…</div>

  const goToSchedule = (id: string) => {
    navigate({ to: '/business/schedules/$scheduleId', params: { scheduleId: id } })
  }

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-lg font-semibold text-gray-900">Recurring stock-takes</h1>
          <p className="text-sm text-gray-500">Schedule weekly, monthly, quarterly or on-demand stock-takes for your business.</p>
        </div>
        <Button onClick={() => setCreating(true)}>New schedule</Button>
      </div>

      {creating && (
        <ScheduleForm tenantId={tenantId!} onSuccess={() => setCreating(false)} onCancel={() => setCreating(false)} />
      )}

      <Card>
        <div className="overflow-x-auto">
          <Table
            headers={['Title', 'Mode', 'Frequency', 'Next occurrence', 'Status', 'Actions']}
            children={
              schedules.length === 0
                ? ''
                : schedules.map((schedule) => (
                  <tr key={schedule.id} className="group hover:bg-gray-50">
                    <td className="px-4 py-3">
                      <span
                        className="text-brand-700 font-medium hover:underline cursor-pointer"
                        onClick={() => goToSchedule(schedule.id)}
                      >
                        {schedule.title}
                      </span>
                      {schedule.description && <p className="text-xs text-gray-500 mt-1">{schedule.description}</p>}
                    </td>
                    <td className="px-4 py-3"><Badge tone="gray">{schedule.mode}</Badge></td>
                    <td className="px-4 py-3">{schedule.frequency.replace('_', ' ')}</td>
                    <td className="px-4 py-3">
                      {schedule.next_occurrence_at ? new Date(schedule.next_occurrence_at).toLocaleDateString('en-KE') : '—'}
                    </td>
                    <td className="px-4 py-3"><Badge tone={schedule.active ? 'green' : 'gray'}>{schedule.active ? 'Active' : 'Paused'}</Badge></td>
                    <td className="px-4 py-3">
                      <Button variant="secondary" onClick={() => goToSchedule(schedule.id)}>
                        Manage
                      </Button>
                    </td>
                  </tr>
                ))
            }
            empty={schedules.length === 0 ? <tr><td colSpan={6} className="px-4 py-8 text-center text-gray-500">No schedules yet. Create your first recurring stock-take.</td></tr> : undefined}
          />
        </div>
      </Card>

      {createMutation.isError && (
        <div className="rounded-lg border border-red-200 bg-red-50 text-red-800 px-4 py-3 text-sm">
          {createMutation.error instanceof Error ? createMutation.error.message : 'Failed to create schedule.'}
        </div>
      )}
    </div>
  )
}
