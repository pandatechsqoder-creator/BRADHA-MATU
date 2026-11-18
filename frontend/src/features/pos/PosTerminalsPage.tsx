import { useState } from 'react'
import { Alert, Badge, Button, Card, Field, PageHeader, Select, Table } from '../../components/ui'
import { useCurrentUser } from '../../lib/auth/hooks'
import { ApiError } from '../../lib/api/client'
import {
  useCreatePosTerminal,
  usePosShifts,
  usePosTerminals,
  useUpdatePosTerminal,
} from '../../lib/pos/hooks'

export function PosTerminalsPage() {
  const { data: user } = useCurrentUser()
  const tenantId = user?.current_tenant?.id

  const terminalsQuery = usePosTerminals(tenantId)
  const shiftsQuery = usePosShifts(tenantId)
  const createTerminal = useCreatePosTerminal(tenantId)
  const updateTerminal = useUpdatePosTerminal(tenantId)

  const [name, setName] = useState('')
  const [deviceId, setDeviceId] = useState('')
  const [status, setStatus] = useState<'active' | 'inactive' | 'maintenance'>('active')
  const [error, setError] = useState<string | null>(null)

  const terminals = terminalsQuery.data ?? []
  const openShifts = (shiftsQuery.data ?? []).filter((shift) => shift.status === 'open')

  function handleCreate() {
    if (name.trim() === '') return
    setError(null)
    createTerminal.mutate(
      {
        name: name.trim(),
        device_id: deviceId.trim() || undefined,
        status,
      },
      {
        onSuccess: () => {
          setName('')
          setDeviceId('')
          setStatus('active')
        },
        onError: (err) =>
          setError(err instanceof ApiError ? err.message : 'Could not create the terminal.'),
      },
    )
  }

  function handleStatusChange(terminalId: string, nextStatus: 'active' | 'inactive' | 'maintenance') {
    setError(null)
    updateTerminal.mutate(
      { terminalId, input: { status: nextStatus } },
      {
        onError: (err) =>
          setError(err instanceof ApiError ? err.message : 'Could not update the terminal.'),
      },
    )
  }

  return (
    <div className="space-y-6">
      <PageHeader
        title="POS terminals"
        description="Register devices that can take sales and hold cashier shifts."
      />

      {error && <Alert kind="error">{error}</Alert>}

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-12">
        <div className="lg:col-span-4">
          <Card title="New terminal">
            <div className="space-y-4">
              <Field
                label="Name"
                value={name}
                onChange={(event) => setName(event.target.value)}
                placeholder="Till 1"
              />
              <Field
                label="Device ID"
                value={deviceId}
                onChange={(event) => setDeviceId(event.target.value)}
                placeholder="POS-001"
                hint="Optional hardware or register identifier."
              />
              <Select
                label="Status"
                value={status}
                onChange={(event) =>
                  setStatus(event.target.value as 'active' | 'inactive' | 'maintenance')
                }
              >
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
                <option value="maintenance">Maintenance</option>
              </Select>
              <Button
                onClick={handleCreate}
                loading={createTerminal.isPending}
                disabled={name.trim() === ''}
              >
                Create terminal
              </Button>
            </div>
          </Card>
        </div>

        <div className="lg:col-span-8">
          <Card title={`Terminals (${terminals.length})`}>
            {terminals.length === 0 ? (
              <p className="py-8 text-center text-sm text-gray-500">
                No terminals yet — create one to start selling.
              </p>
            ) : (
              <Table headers={['Terminal', 'Device', 'Status', 'Shift', 'Actions']}>
                {terminals.map((terminal) => {
                  const shift = openShifts.find((candidate) => candidate.terminal_id === terminal.id)
                  return (
                    <tr key={terminal.id} className="hover:bg-gray-50">
                      <td className="px-4 py-3">
                        <div className="font-medium">{terminal.name}</div>
                        <div className="text-xs text-gray-500">{terminal.id}</div>
                      </td>
                      <td className="px-4 py-3 text-gray-600">{terminal.device_id ?? '—'}</td>
                      <td className="px-4 py-3">
                        <Badge
                          tone={
                            terminal.status === 'active'
                              ? 'green'
                              : terminal.status === 'maintenance'
                                ? 'amber'
                                : 'gray'
                          }
                        >
                          {terminal.status}
                        </Badge>
                      </td>
                      <td className="px-4 py-3">
                        {shift ? (
                          <Badge tone="green">open</Badge>
                        ) : (
                          <span className="text-gray-500">closed</span>
                        )}
                      </td>
                      <td className="px-4 py-3">
                        <Select
                          label=""
                          aria-label={`Status for ${terminal.name}`}
                          value={terminal.status}
                          onChange={(event) =>
                            handleStatusChange(
                              terminal.id,
                              event.target.value as 'active' | 'inactive' | 'maintenance',
                            )
                          }
                          className="w-36"
                        >
                          <option value="active">Active</option>
                          <option value="inactive">Inactive</option>
                          <option value="maintenance">Maintenance</option>
                        </Select>
                      </td>
                    </tr>
                  )
                })}
              </Table>
            )}
          </Card>
        </div>
      </div>
    </div>
  )
}
