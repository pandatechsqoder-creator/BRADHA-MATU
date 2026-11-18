import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Alert, Badge, Button } from '../../components/ui'
import { ApiError } from '../../lib/api/client'
import { approveBatch, fetchPendingBatches, processBatch, type PayoutBatch } from '../../lib/api/payout'
import { formatMoney } from '../../lib/formatting'

function statusTone(s: string): 'gray' | 'amber' | 'green' | 'red' {
  if (s === 'completed') return 'green'
  if (s === 'processing' || s === 'approved') return 'amber'
  if (s === 'failed' || s === 'cancelled') return 'red'
  return 'gray'
}

function BatchRow({ batch, canApprove }: { batch: PayoutBatch; canApprove: boolean }) {
  const qc = useQueryClient()
  const invalidate = () => qc.invalidateQueries({ queryKey: ['platform', 'payouts'] })
  const approve = useMutation({ mutationFn: () => approveBatch(batch.id), onSuccess: invalidate })
  const process = useMutation({ mutationFn: () => processBatch(batch.id), onSuccess: invalidate })
  const [error, setError] = useState<string | null>(null)

  const act = (fn: () => Promise<unknown>) => async () => {
    setError(null)
    try { await fn() } catch (err) { setError(err instanceof ApiError ? err.message : 'Action failed.') }
  }

  return (
    <div className="rounded-lg border border-gray-200 bg-white p-4">
      <div className="flex flex-wrap items-center gap-3">
        <span className="font-mono text-sm font-semibold text-gray-800">{batch.reference}</span>
        <Badge tone={statusTone(batch.status)}>{batch.status.replace('_', ' ')}</Badge>
        <span className="ml-auto text-sm font-semibold text-gray-800">
          {formatMoney(batch.total_amount, batch.currency)}
        </span>
        <span className="text-xs text-gray-500">{batch.payout_count} payout{batch.payout_count !== 1 ? 's' : ''}</span>
      </div>

      {error && <Alert kind="error">{error}</Alert>}

      <div className="mt-3 flex flex-wrap gap-2">
        {batch.status === 'pending_approval' && canApprove && (
          <Button loading={approve.isPending} onClick={act(() => approve.mutateAsync())}>
            Approve batch
          </Button>
        )}
        {batch.status === 'approved' && (
          <Button loading={process.isPending} onClick={act(() => process.mutateAsync())}>
            Process payouts
          </Button>
        )}
      </div>

      {/* Per-payout table */}
      {batch.payouts.length > 0 && (
        <table className="mt-3 w-full text-xs">
          <thead className="border-b border-gray-100 text-left text-gray-500">
            <tr>
              <th className="py-1">Recipient</th>
              <th className="py-1">Method</th>
              <th className="py-1 text-right">Amount</th>
              <th className="py-1">Status</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-50">
            {batch.payouts.map((p) => (
              <tr key={p.id}>
                <td className="py-1 text-gray-600">{p.recipient ?? '—'}</td>
                <td className="py-1 uppercase">{p.method}</td>
                <td className="py-1 text-right font-semibold">{formatMoney(p.amount, 'KES')}</td>
                <td className="py-1"><Badge tone={statusTone(p.status)}>{p.status}</Badge></td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </div>
  )
}

export function PayoutQueuePanel({ canApprove = false }: { canApprove?: boolean }) {
  const { data, isLoading, error } = useQuery({
    queryKey: ['platform', 'payouts'],
    queryFn: fetchPendingBatches,
    retry: false,
    refetchInterval: 30_000,
  })

  if (isLoading) return <p className="text-sm text-gray-500">Loading payouts…</p>
  if (error) return <Alert kind="error">{error instanceof ApiError ? error.message : 'Failed to load payouts.'}</Alert>
  if (!data?.length) return <p className="text-sm text-gray-500">No pending payout batches.</p>

  return (
    <div className="space-y-3">
      {data.map((b) => <BatchRow key={b.id} batch={b} canApprove={canApprove} />)}
    </div>
  )
}
