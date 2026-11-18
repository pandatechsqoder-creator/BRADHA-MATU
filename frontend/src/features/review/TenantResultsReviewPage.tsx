import { useParams } from '@tanstack/react-router'
import { useState, type FormEvent } from 'react'
import { Alert, Badge, Button, Card, Field, PageHeader, PageLoader } from '../../components/ui'
import { ApiError } from '../../lib/api/client'
import type { ReviewItem } from '../../lib/api/review'
import {
  useAcceptResults, useFlagItem, useGenerateReconciliation,
  useRaiseDispute, useRequestCorrection, useRequestRecount, useTenantReview,
} from '../../lib/review/hooks'
import { useCurrentUser } from '../../lib/auth/hooks'
import { formatDateTime } from '../../lib/formatting'

const statusTone = (s: string) =>
  s === 'accepted' ? 'green' : s === 'flagged' || s === 'disputed' ? 'red' : s === 'in_progress' ? 'amber' : 'gray'

function DisputeForm({ tenantId, jobId, onClose }: { tenantId: string; jobId: string; onClose: () => void }) {
  const [category, setCategory] = useState('accuracy')
  const [priority, setPriority] = useState('normal')
  const [reason, setReason] = useState('')
  const [message, setMessage] = useState('')
  const [error, setError] = useState<string | null>(null)
  const raise = useRaiseDispute(tenantId, jobId)

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    setError(null)
    try {
      await raise.mutateAsync({ category, priority, reason, message: message || undefined })
      onClose()
    } catch (err) { setError(err instanceof ApiError ? err.message : 'Failed to raise dispute.') }
  }

  return (
    <form onSubmit={submit} className="space-y-3">
      <h4 className="font-semibold text-gray-900">Raise dispute</h4>
      {error && <Alert kind="error">{error}</Alert>}
      <div className="grid grid-cols-2 gap-3">
        <Field label="Category">
          <select value={category} onChange={(e) => setCategory(e.target.value)}
            className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
            <option value="accuracy">Accuracy</option>
            <option value="completeness">Completeness</option>
            <option value="damage">Damage</option>
            <option value="billing">Billing</option>
            <option value="other">Other</option>
          </select>
        </Field>
        <Field label="Priority">
          <select value={priority} onChange={(e) => setPriority(e.target.value)}
            className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
            <option value="low">Low</option>
            <option value="normal">Normal</option>
            <option value="high">High</option>
            <option value="urgent">Urgent</option>
          </select>
        </Field>
      </div>
      <Field label="Reason">
        <textarea value={reason} onChange={(e) => setReason(e.target.value)} rows={2} maxLength={2000}
          className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm" required />
      </Field>
      <Field label="Opening message (optional)">
        <textarea value={message} onChange={(e) => setMessage(e.target.value)} rows={2} maxLength={2000}
          className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm" />
      </Field>
      <div className="flex gap-2">
        <Button type="submit" loading={raise.isPending}>Submit dispute</Button>
        <Button type="button" variant="secondary" onClick={onClose}>Cancel</Button>
      </div>
    </form>
  )
}

function CorrectionForm({ tenantId, jobId, onClose }: { tenantId: string; jobId: string; onClose: () => void }) {
  const [notes, setNotes] = useState('')
  const [error, setError] = useState<string | null>(null)
  const request = useRequestCorrection(tenantId, jobId)

  const submit = async (e: FormEvent) => {
    e.preventDefault(); setError(null)
    try { await request.mutateAsync(notes); onClose() }
    catch (err) { setError(err instanceof ApiError ? err.message : 'Failed.') }
  }

  return (
    <form onSubmit={submit} className="space-y-3">
      <h4 className="font-semibold">Request correction</h4>
      {error && <Alert kind="error">{error}</Alert>}
      <Field label="Notes">
        <textarea value={notes} onChange={(e) => setNotes(e.target.value)} rows={2} maxLength={2000}
          className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm" required />
      </Field>
      <div className="flex gap-2">
        <Button type="submit" loading={request.isPending}>Request</Button>
        <Button type="button" variant="secondary" onClick={onClose}>Cancel</Button>
      </div>
    </form>
  )
}

function RecountForm({ tenantId, jobId, onClose }: { tenantId: string; jobId: string; onClose: () => void }) {
  const [reason, setReason] = useState('')
  const [error, setError] = useState<string | null>(null)
  const request = useRequestRecount(tenantId, jobId)

  const submit = async (e: FormEvent) => {
    e.preventDefault(); setError(null)
    try { await request.mutateAsync(reason); onClose() }
    catch (err) { setError(err instanceof ApiError ? err.message : 'Failed.') }
  }

  return (
    <form onSubmit={submit} className="space-y-3">
      <h4 className="font-semibold">Request recount</h4>
      {error && <Alert kind="error">{error}</Alert>}
      <Field label="Reason">
        <textarea value={reason} onChange={(e) => setReason(e.target.value)} rows={2} maxLength={2000}
          className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm" required />
      </Field>
      <div className="flex gap-2">
        <Button type="submit" loading={request.isPending}>Request recount</Button>
        <Button type="button" variant="secondary" onClick={onClose}>Cancel</Button>
      </div>
    </form>
  )
}

export function TenantResultsReviewPage() {
  const { jobId } = useParams({ strict: false }) as { jobId: string }
  const { data: user } = useCurrentUser()
  const tenantId = user?.current_tenant?.id ?? ''
  const { data, isLoading, error } = useTenantReview(tenantId || null, jobId)
  const accept = useAcceptResults(tenantId, jobId)
  const flagItem = useFlagItem(tenantId, jobId)
  const genRecon = useGenerateReconciliation(tenantId, jobId)
  const [panel, setPanel] = useState<'accept' | 'correction' | 'recount' | 'dispute' | null>(null)
  const [flaggingId, setFlaggingId] = useState<string | null>(null)
  const [flagComment, setFlagComment] = useState('')
  const [acceptNotes, setAcceptNotes] = useState('')

  if (isLoading || !tenantId) return <PageLoader label="Loading review…" />
  if (error) return <Alert kind="error">{error instanceof ApiError ? error.message : 'Failed to load review.'}</Alert>
  if (!data) return null

  const review = data.review
  const isActive = review.status === 'in_progress'
  const reconType = (t: string) => t === 'match' ? 'green' : t === 'variance' ? 'red' : 'gray'

  return (
    <div className="mx-auto max-w-3xl space-y-6">
      <PageHeader
        title="Count results review"
        description={`${data.job.reference} · ${data.job.title}`}
        actions={review.expires_at ? (
          <span className="text-xs text-gray-500">Review window closes {formatDateTime(review.expires_at)}</span>
        ) : undefined}
      />

      {/* Review status */}
      <Card title="Review status" actions={<Badge tone={statusTone(review.status)}>{review.status.replace('_', ' ')}</Badge>}>
        <div className="grid grid-cols-3 gap-3 text-sm">
          <div><span className="font-bold text-gray-800">{review.total_items}</span> <span className="text-gray-500">items</span></div>
          <div><span className="font-bold text-green-600">{review.items_accepted}</span> <span className="text-gray-500">accepted</span></div>
          <div><span className="font-bold text-amber-600">{review.items_flagged}</span> <span className="text-gray-500">flagged</span></div>
        </div>
        {review.decision_notes && (
          <p className="mt-2 text-sm text-gray-600">{review.decision_notes}</p>
        )}
      </Card>

      {/* Actions */}
      {isActive && panel === null && (
        <div className="flex flex-wrap gap-2">
          <Button onClick={() => setPanel('accept')}>Accept results</Button>
          <Button variant="secondary" onClick={() => setPanel('correction')}>Request correction</Button>
          <Button variant="secondary" onClick={() => setPanel('recount')}>Request recount</Button>
          <Button variant="secondary" onClick={() => setPanel('dispute')}>Raise dispute</Button>
          {!data.reconciliation && (
            <Button variant="secondary" loading={genRecon.isPending} onClick={() => genRecon.mutate()}>Generate reconciliation</Button>
          )}
        </div>
      )}

      {/* Inline action panels */}
      {panel === 'accept' && (
        <Card title="Accept results">
          <div className="space-y-3">
            <Field label="Notes (optional)">
              <textarea value={acceptNotes} onChange={(e) => setAcceptNotes(e.target.value)} rows={2} maxLength={2000}
                className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm" />
            </Field>
            <div className="flex gap-2">
              <Button loading={accept.isPending}
                onClick={() => accept.mutateAsync(acceptNotes || undefined).then(() => setPanel(null))}>
                Confirm acceptance
              </Button>
              <Button variant="secondary" onClick={() => setPanel(null)}>Cancel</Button>
            </div>
          </div>
        </Card>
      )}
      {panel === 'correction' && <Card title="Request correction"><CorrectionForm tenantId={tenantId} jobId={jobId} onClose={() => setPanel(null)} /></Card>}
      {panel === 'recount' && <Card title="Request recount"><RecountForm tenantId={tenantId} jobId={jobId} onClose={() => setPanel(null)} /></Card>}
      {panel === 'dispute' && <Card title="Raise dispute"><DisputeForm tenantId={tenantId} jobId={jobId} onClose={() => setPanel(null)} /></Card>}

      {/* Count items table */}
      <Card title={`Count results (${data.items.length} items)`}>
        <table className="w-full text-sm">
          <thead className="border-b border-gray-100 text-left text-xs text-gray-500">
            <tr>
              <th className="py-2">SKU</th>
              <th className="py-2">Product</th>
              <th className="py-2 text-right">Counted</th>
              <th className="py-2">Status</th>
              <th className="py-2"></th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-50">
            {data.items.map((item: ReviewItem) => (
              <tr key={item.id} className={item.status === 'flagged' ? 'bg-amber-50' : ''}>
                <td className="py-2 font-mono text-xs">{item.sku}</td>
                <td className="py-2 text-gray-800">{item.product_name}</td>
                <td className="py-2 text-right font-semibold">{item.counted_quantity ? parseFloat(item.counted_quantity) : '—'}</td>
                <td className="py-2">
                  <Badge tone={statusTone(item.status)}>{item.status}</Badge>
                  {item.tenant_comment && <span className="ml-1 text-xs text-gray-500">{item.tenant_comment}</span>}
                </td>
                <td className="py-2 text-right">
                  {isActive && item.status === 'pending' && (
                    flaggingId === item.id ? (
                      <div className="flex items-center gap-1">
                        <input type="text" value={flagComment} onChange={(e) => setFlagComment(e.target.value)}
                          placeholder="Comment…" className="rounded border border-gray-300 px-2 py-1 text-xs w-32" />
                        <Button loading={flagItem.isPending}
                          onClick={() => flagItem.mutate({ itemId: item.id, comment: flagComment }, { onSuccess: () => { setFlaggingId(null); setFlagComment('') } })}>
                          Flag
                        </Button>
                      </div>
                    ) : (
                      <button onClick={() => { setFlaggingId(item.id); setFlagComment('') }}
                        className="text-xs text-amber-700 hover:underline">Flag</button>
                    )
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </Card>

      {/* Reconciliation report */}
      {data.reconciliation && (
        <Card title="Reconciliation report">
          <div className="mb-3 grid grid-cols-4 gap-3 text-sm">
            {[
              { label: 'Total', value: data.reconciliation.total_items },
              { label: 'Matched', value: data.reconciliation.items_matched, color: 'text-green-600' },
              { label: 'Variance', value: data.reconciliation.items_with_variance, color: 'text-red-600' },
              { label: 'Unknown', value: data.reconciliation.items_unknown, color: 'text-gray-500' },
            ].map(({ label, value, color }) => (
              <div key={label}><span className={`font-bold ${color ?? 'text-gray-800'}`}>{value}</span> <span className="text-gray-500">{label}</span></div>
            ))}
          </div>
          <table className="w-full text-xs">
            <thead className="border-b border-gray-100 text-left text-gray-500">
              <tr>
                <th className="py-1">SKU</th><th className="py-1">Product</th>
                <th className="py-1 text-right">Counted</th><th className="py-1 text-right">Expected</th>
                <th className="py-1 text-right">Variance</th><th className="py-1">Type</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-50">
              {data.reconciliation.items.map((r) => (
                <tr key={r.id}>
                  <td className="py-1 font-mono">{r.sku}</td>
                  <td className="py-1">{r.product_name}</td>
                  <td className="py-1 text-right">{r.counted_quantity ?? '—'}</td>
                  <td className="py-1 text-right text-gray-500">{r.expected_quantity ?? '—'}</td>
                  <td className="py-1 text-right">{r.variance ?? '—'}</td>
                  <td className="py-1"><Badge tone={reconType(r.type)}>{r.type}</Badge></td>
                </tr>
              ))}
            </tbody>
          </table>
        </Card>
      )}

      {/* Disputes */}
      {data.disputes.length > 0 && (
        <Card title="Disputes">
          {data.disputes.map((d) => (
            <div key={d.id} className="border-b border-gray-100 py-3 last:border-0">
              <div className="flex items-center gap-3 mb-2">
                <Badge tone={d.status === 'resolved' ? 'green' : 'red'}>{d.status}</Badge>
                <Badge tone="gray">{d.category}</Badge>
                <Badge tone={d.priority === 'urgent' ? 'red' : 'amber'}>{d.priority}</Badge>
                <span className="text-sm text-gray-700">{d.reason}</span>
              </div>
              {d.messages.length > 0 && (
                <div className="space-y-1 ml-2 border-l-2 border-gray-100 pl-3">
                  {d.messages.map((m) => (
                    <div key={m.id} className={`rounded px-2 py-1 text-xs ${m.sender_role === 'platform' ? 'bg-blue-50 text-blue-800' : 'bg-gray-50 text-gray-700'}`}>
                      <span className="font-semibold capitalize">{m.sender_role}:</span> {m.message}
                    </div>
                  ))}
                </div>
              )}
              {d.decisions.length > 0 && (
                <div className="mt-2">
                  {d.decisions.map((dec) => (
                    <div key={dec.id} className="rounded bg-green-50 px-2 py-1 text-xs text-green-800">
                      <span className="font-semibold">Decision: {dec.decision}</span>
                      {dec.reasoning && <span> — {dec.reasoning}</span>}
                    </div>
                  ))}
                </div>
              )}
            </div>
          ))}
        </Card>
      )}
    </div>
  )
}
