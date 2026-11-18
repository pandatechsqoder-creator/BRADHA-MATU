import { useState } from 'react'
import { Alert, Badge, Button, Card, Field, PageHeader, Select, Table } from '../../components/ui'
import { useCurrentUser } from '../../lib/auth/hooks'
import { ApiError } from '../../lib/api/client'
import {
  useClosePosShift,
  usePosShiftReconciliation,
  usePosShifts,
  useReconcilePosShift,
} from '../../lib/pos/hooks'

function money(value: number | null, currency = 'KES'): string {
  if (value === null) return '—'
  return `${currency} ${value.toLocaleString('en-KE', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })}`
}

export function PosShiftsPage() {
  const { data: user } = useCurrentUser()
  const tenantId = user?.current_tenant?.id

  const shiftsQuery = usePosShifts(tenantId)
  const closeShift = useClosePosShift(tenantId)
  const reconcileShift = useReconcilePosShift(tenantId)

  const [statusFilter, setStatusFilter] = useState('')
  const [selectedShiftId, setSelectedShiftId] = useState('')
  const [actualCash, setActualCash] = useState('')
  const [error, setError] = useState<string | null>(null)

  const shifts = (shiftsQuery.data ?? []).filter(
    (shift) => statusFilter === '' || shift.status === statusFilter,
  )

  const selectedShift = (shiftsQuery.data ?? []).find((shift) => shift.id === selectedShiftId)
  const reconciliationQuery = usePosShiftReconciliation(
    tenantId,
    selectedShift?.status === 'closed' || selectedShift?.status === 'reconciled'
      ? selectedShift.id
      : null,
  )

  function handleClose() {
    if (!selectedShift) return
    setError(null)
    closeShift.mutate(
      { shiftId: selectedShift.id, input: { actual_cash: Number(actualCash || 0) } },
      {
        onSuccess: () => {
          setActualCash('')
        },
        onError: (err) =>
          setError(err instanceof ApiError ? err.message : 'Could not close the shift.'),
      },
    )
  }

  function handleReconcile() {
    if (!selectedShift) return
    setError(null)
    reconcileShift.mutate(selectedShift.id, {
      onError: (err) =>
        setError(err instanceof ApiError ? err.message : 'Could not reconcile the shift.'),
    })
  }

  return (
    <div className="space-y-6">
      <PageHeader
        title="POS shifts"
        description="Open shifts are closed with a cash count, then reconciled against expected takings."
      />

      {error && <Alert kind="error">{error}</Alert>}

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-12">
        <div className="space-y-6 lg:col-span-8">
          <Card
            title={`Shifts (${shifts.length})`}
            actions={
              <Select
                label=""
                aria-label="Filter shifts"
                value={statusFilter}
                onChange={(event) => setStatusFilter(event.target.value)}
                className="w-40"
              >
                <option value="">All statuses</option>
                <option value="open">Open</option>
                <option value="closed">Closed</option>
                <option value="reconciled">Reconciled</option>
              </Select>
            }
          >
            {shifts.length === 0 ? (
              <p className="py-8 text-center text-sm text-gray-500">
                No shifts for this filter. Open a shift from the POS terminal.
              </p>
            ) : (
              <Table headers={['Terminal', 'Status', 'Opened', 'Float', 'Expected', 'Actual', 'Variance']}>
                {shifts.map((shift) => (
                  <tr
                    key={shift.id}
                    onClick={() => setSelectedShiftId(shift.id)}
                    className={`cursor-pointer hover:bg-gray-50 ${
                      selectedShiftId === shift.id ? 'bg-brand-50' : ''
                    }`}
                  >
                    <td className="px-4 py-3">
                      <div className="font-medium">{shift.terminal_name ?? '—'}</div>
                      <div className="text-xs text-gray-500">{shift.opened_by ?? '—'}</div>
                    </td>
                    <td className="px-4 py-3">
                      <Badge
                        tone={
                          shift.status === 'open'
                            ? 'green'
                            : shift.status === 'closed'
                              ? 'amber'
                              : 'gray'
                        }
                      >
                        {shift.status}
                      </Badge>
                    </td>
                    <td className="px-4 py-3 text-gray-600">
                      {shift.opened_at ? new Date(shift.opened_at).toLocaleString('en-KE') : '—'}
                    </td>
                    <td className="px-4 py-3 font-mono text-xs">{money(shift.opening_float)}</td>
                    <td className="px-4 py-3 font-mono text-xs">{money(shift.expected_cash)}</td>
                    <td className="px-4 py-3 font-mono text-xs">{money(shift.actual_cash)}</td>
                    <td className="px-4 py-3 font-mono text-xs">{money(shift.cash_variance)}</td>
                  </tr>
                ))}
              </Table>
            )}
          </Card>
        </div>

        <div className="space-y-6 lg:col-span-4">
          <Card title="Shift actions">
            {!selectedShift ? (
              <p className="py-6 text-center text-sm text-gray-500">
                Select a shift to close, reconcile, or review its cash position.
              </p>
            ) : (
              <div className="space-y-4">
                <div className="text-sm">
                  <p className="font-medium text-gray-900">{selectedShift.terminal_name}</p>
                  <p className="text-gray-500">
                    {selectedShift.status} · float {money(selectedShift.opening_float)} · cash sales{' '}
                    {money(selectedShift.total_cash_sales)}
                  </p>
                </div>

                {selectedShift.status === 'open' && (
                  <div className="space-y-3 border-t border-gray-100 pt-4">
                    <Field
                      label="Counted cash"
                      type="number"
                      min={0}
                      step="0.01"
                      value={actualCash}
                      onChange={(event) => setActualCash(event.target.value)}
                      hint="Enter the physical cash in the drawer."
                    />
                    <Button onClick={handleClose} loading={closeShift.isPending}>
                      Close shift
                    </Button>
                  </div>
                )}

                {selectedShift.status === 'closed' && (
                  <div className="space-y-3 border-t border-gray-100 pt-4">
                    <p className="text-sm text-gray-600">
                      Variance {money(selectedShift.cash_variance)} — reconcile to lock the shift.
                    </p>
                    <Button onClick={handleReconcile} loading={reconcileShift.isPending}>
                      Reconcile shift
                    </Button>
                  </div>
                )}

                {selectedShift.status !== 'open' && (
                  <div className="space-y-2 border-t border-gray-100 pt-4 text-sm">
                    {reconciliationQuery.isLoading ? (
                      <p className="text-gray-500">Loading reconciliation…</p>
                    ) : reconciliationQuery.data ? (
                      <>
                        <div className="flex justify-between">
                          <span className="text-gray-500">Transactions</span>
                          <span>{reconciliationQuery.data.transaction_count}</span>
                        </div>
                        <div className="flex justify-between">
                          <span className="text-gray-500">Cash sales</span>
                          <span>{money(reconciliationQuery.data.cash_sales)}</span>
                        </div>
                        <div className="flex justify-between">
                          <span className="text-gray-500">Card sales</span>
                          <span>{money(reconciliationQuery.data.card_sales)}</span>
                        </div>
                        <div className="flex justify-between">
                          <span className="text-gray-500">M-Pesa sales</span>
                          <span>{money(reconciliationQuery.data.mpesa_sales)}</span>
                        </div>
                        <div className="flex justify-between font-medium">
                          <span>Total sales</span>
                          <span>{money(reconciliationQuery.data.total_sales)}</span>
                        </div>
                      </>
                    ) : (
                      <p className="text-gray-500">No reconciliation recorded.</p>
                    )}
                  </div>
                )}
              </div>
            )}
          </Card>
        </div>
      </div>
    </div>
  )
}
