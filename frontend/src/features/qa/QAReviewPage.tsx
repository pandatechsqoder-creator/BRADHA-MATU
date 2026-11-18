import { Link, useParams } from '@tanstack/react-router'
import { useState, type FormEvent } from 'react'
import { Alert, Badge, Button, Card, Field, PageHeader, PageLoader } from '../../components/ui'
import { ApiError } from '../../lib/api/client'
import type { ReviewEntry } from '../../lib/api/qa'
import {
  useApproveRecount, useCompleteRecount, useCompleteReview,
  useCreateVerificationTask, useQaReview, useRecordFinding,
  useStartQaReview,
} from '../../lib/qa/hooks'

function FindingForm({ jobId, entry, onClose }: { jobId: string; entry?: ReviewEntry; onClose: () => void }) {
  const [type, setType] = useState('excess')
  const [counted, setCounted] = useState(entry?.raw_quantity ?? '')
  const [verified, setVerified] = useState('')
  const [severity, setSeverity] = useState('minor')
  const [classification, setClassification] = useState('worker_error')
  const [notes, setNotes] = useState('')
  const [error, setError] = useState<string | null>(null)
  const record = useRecordFinding(jobId)

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    setError(null)
    try {
      await record.mutateAsync({
        variant_id: entry?.variant_id,
        type, counted_quantity: counted || undefined, verified_quantity: verified || undefined,
        severity, classification, notes: notes || undefined,
      })
      onClose()
    } catch (err) { setError(err instanceof ApiError ? err.message : 'Failed to record finding.') }
  }

  return (
    <form onSubmit={submit} className="space-y-3">
      <h4 className="font-semibold text-gray-900">Record finding</h4>
      {error && <Alert kind="error">{error}</Alert>}
      <div className="grid grid-cols-2 gap-3">
        <Field label="Type">
          <select value={type} onChange={(e) => setType(e.target.value)}
            className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
            <option value="excess">Excess</option>
            <option value="missing">Missing</option>
            <option value="damaged">Damaged</option>
            <option value="mismatched">Mismatched</option>
            <option value="wrong_unit">Wrong unit</option>
            <option value="other">Other</option>
          </select>
        </Field>
        <Field label="Severity">
          <select value={severity} onChange={(e) => setSeverity(e.target.value)}
            className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
            <option value="minor">Minor</option>
            <option value="moderate">Moderate</option>
            <option value="major">Major</option>
            <option value="critical">Critical</option>
          </select>
        </Field>
      </div>
      <div className="grid grid-cols-2 gap-3">
        <Field label="Counted qty">
          <input type="number" step="any" value={counted} onChange={(e) => setCounted(e.target.value)}
            className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm" />
        </Field>
        <Field label="Verified qty">
          <input type="number" step="any" value={verified} onChange={(e) => setVerified(e.target.value)}
            className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm" />
        </Field>
      </div>
      <Field label="Classification">
        <select value={classification} onChange={(e) => setClassification(e.target.value)}
          className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
          <option value="worker_error">Worker error</option>
          <option value="system_error">System error</option>
          <option value="expected_variance">Expected variance</option>
          <option value="unknown">Unknown</option>
        </select>
      </Field>
      <Field label="Notes">
        <textarea value={notes} onChange={(e) => setNotes(e.target.value)} rows={2} maxLength={2000}
          className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm" />
      </Field>
      <div className="flex gap-2">
        <Button type="submit" loading={record.isPending}>Record</Button>
        <Button type="button" variant="secondary" onClick={onClose}>Cancel</Button>
      </div>
    </form>
  )
}

function CompleteReviewForm({ jobId, onClose }: { jobId: string; onClose: () => void }) {
  const [decision, setDecision] = useState('pass')
  const [summary, setSummary] = useState('')
  const [reason, setReason] = useState('')
  const [cost, setCost] = useState('undetermined')
  const [error, setError] = useState<string | null>(null)
  const complete = useCompleteReview(jobId)

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    setError(null)
    try {
      await complete.mutateAsync({
        decision, summary: summary || undefined,
        recount_reason: decision === 'recount' ? reason : undefined,
        cost_responsibility: decision === 'recount' ? cost : undefined,
      })
      onClose()
    } catch (err) { setError(err instanceof ApiError ? err.message : 'Failed to complete review.') }
  }

  return (
    <form onSubmit={submit} className="space-y-3">
      <h4 className="font-semibold text-gray-900">Complete QA review</h4>
      {error && <Alert kind="error">{error}</Alert>}
      <Field label="Decision">
        <select value={decision} onChange={(e) => setDecision(e.target.value)}
          className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
          <option value="pass">Pass — counts accepted</option>
          <option value="fail">Fail — issues noted, send to tenant</option>
          <option value="recount">Recount required</option>
        </select>
      </Field>
      <Field label="Summary">
        <textarea value={summary} onChange={(e) => setSummary(e.target.value)} rows={2} maxLength={2000}
          className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm" />
      </Field>
      {decision === 'recount' && (
        <>
          <Field label="Recount reason">
            <textarea value={reason} onChange={(e) => setReason(e.target.value)} rows={2} maxLength={2000}
              className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm" />
          </Field>
          <Field label="Cost responsibility">
            <select value={cost} onChange={(e) => setCost(e.target.value)}
              className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
              <option value="undetermined">Undetermined</option>
              <option value="platform">Platform bears cost</option>
              <option value="worker_error">Worker error — worker bears</option>
              <option value="tenant_request">Tenant request — tenant pays</option>
              <option value="split">Split</option>
            </select>
          </Field>
        </>
      )}
      <div className="flex gap-2">
        <Button type="submit" loading={complete.isPending}>Complete review</Button>
        <Button type="button" variant="secondary" onClick={onClose}>Cancel</Button>
      </div>
    </form>
  )
}

export function QAReviewPage() {
  const { jobId } = useParams({ strict: false }) as { jobId: string }
  const { data, isLoading, error } = useQaReview(jobId)
  const startReview = useStartQaReview(jobId)
  const approveRecount = useApproveRecount(jobId)
  const completeRecount = useCompleteRecount(jobId)
  const createVt = useCreateVerificationTask(jobId)
  const [panel, setPanel] = useState<'finding' | 'complete' | null>(null)
  const [findingEntry, setFindingEntry] = useState<ReviewEntry | undefined>()
  const [sampleSize, setSampleSize] = useState(5)

  if (isLoading) return <PageLoader label="Loading QA review…" />
  if (error) {
    if (error instanceof ApiError && error.status === 404) {
      return (
        <div className="mx-auto max-w-2xl space-y-4">
          <PageHeader title="QA review" description="No active review found for this job." />
          <Button loading={startReview.isPending} onClick={() => startReview.mutate()}>Start QA review</Button>
        </div>
      )
    }
    return <Alert kind="error">{error instanceof ApiError ? error.message : 'Failed to load.'}</Alert>
  }
  if (!data) return null

  const review = data.review
  const acc = data.worker_accuracy
  const isActive = review.status === 'in_progress'

  return (
    <div className="mx-auto max-w-3xl space-y-6">
      <div className="flex items-start justify-between gap-4">
        <PageHeader title="QA review" description={`${data.job.reference} · ${data.job.title}`} />
        <Link to="/platform/stocktaking-jobs/$jobId" params={{ jobId }}
          className="text-sm font-medium text-brand-700 hover:underline shrink-0">← Back to job</Link>
      </div>

      {/* Review status */}
      <Card title="Review status" actions={<Badge tone={review.status === 'passed' ? 'green' : review.status === 'recount_required' ? 'red' : 'amber'}>{review.status.replace('_', ' ')}</Badge>}>
        <div className="grid grid-cols-3 gap-3 text-sm">
          <div><span className="font-semibold text-gray-800">{review.findings_count}</span> <span className="text-gray-500">findings</span></div>
          <div><span className="font-semibold text-gray-800">{review.entries_reviewed}</span> <span className="text-gray-500">entries reviewed</span></div>
          <div><span className="font-semibold text-gray-800">{data.entries.length}</span> <span className="text-gray-500">total entries</span></div>
        </div>
      </Card>

      {/* Worker accuracy */}
      <Card title="Worker accuracy">
        <div className="grid grid-cols-4 gap-3 text-sm">
          <div><span className="font-bold text-gray-800">{acc.accuracy_rate ?? '—'}%</span> <span className="text-gray-500">accuracy</span></div>
          <div><span className="font-bold text-red-600">{acc.worker_errors}</span> <span className="text-gray-500">worker errors</span></div>
          <div><span className="font-bold text-amber-600">{acc.expected_variance}</span> <span className="text-gray-500">expected variance</span></div>
          <div><span className="font-bold text-gray-500">{acc.system_errors}</span> <span className="text-gray-500">system errors</span></div>
        </div>
        <p className="mt-2 text-xs text-gray-500">{acc.note}</p>
      </Card>

      {/* Actions */}
      {isActive && (
        <div className="flex flex-wrap gap-2">
          <Button variant="secondary" onClick={() => { setFindingEntry(undefined); setPanel('finding') }}>Log finding</Button>
          <Button variant="secondary" onClick={() => setPanel('complete')}>Complete review</Button>
        </div>
      )}

      {/* Inline panels */}
      {panel === 'finding' && (
        <Card title={findingEntry ? `Finding for ${findingEntry.sku ?? 'entry'}` : 'New finding'}>
          <FindingForm jobId={jobId} entry={findingEntry} onClose={() => setPanel(null)} />
        </Card>
      )}
      {panel === 'complete' && (
        <Card title="Complete review">
          <CompleteReviewForm jobId={jobId} onClose={() => setPanel(null)} />
        </Card>
      )}

      {/* Count entries */}
      <Card title={`Count entries (${data.entries.length})`}>
        <table className="w-full text-sm">
          <thead className="border-b border-gray-100 text-left text-xs text-gray-500">
            <tr>
              <th className="py-2">SKU</th>
              <th className="py-2">Product</th>
              <th className="py-2 text-right">Qty</th>
              <th className="py-2">Flags</th>
              <th className="py-2">Finding</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-50">
            {data.entries.map((e) => (
              <tr key={e.id} className={e.has_finding ? 'bg-amber-50' : ''}>
                <td className="py-2 font-mono text-xs">{e.sku}</td>
                <td className="py-2 text-gray-800">{e.product_name}</td>
                <td className="py-2 text-right font-semibold">{parseFloat(e.raw_quantity)}</td>
                <td className="py-2 text-xs text-amber-700">{e.observations.join(', ') || '—'}</td>
                <td className="py-2">
                  {e.has_finding ? (
                    <Badge tone="amber">{e.finding?.type}</Badge>
                  ) : isActive ? (
                    <button onClick={() => { setFindingEntry(e); setPanel('finding') }}
                      className="text-xs text-brand-700 hover:underline">Log</button>
                  ) : '—'}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </Card>

      {/* Findings */}
      {data.findings.length > 0 && (
        <Card title={`Findings (${data.findings.length})`}>
          <ul className="divide-y divide-gray-100">
            {data.findings.map((f) => (
              <li key={f.id} className="flex items-center gap-3 py-2 text-sm">
                <Badge tone={f.classification === 'worker_error' ? 'red' : f.classification === 'expected_variance' ? 'amber' : 'gray'}>
                  {f.classification ?? 'unclassified'}
                </Badge>
                <span className="font-medium">{f.type}</span>
                {f.variance && <span className="text-gray-500">variance: {f.variance}</span>}
                <Badge tone={f.severity === 'critical' ? 'red' : f.severity === 'major' ? 'amber' : 'gray'}>{f.severity}</Badge>
                {f.notes && <span className="ml-auto text-xs text-gray-500 truncate max-w-xs">{f.notes}</span>}
              </li>
            ))}
          </ul>
        </Card>
      )}

      {/* Verification tasks */}
      {data.verification_tasks.length > 0 && (
        <Card title="Independent verification">
          {data.verification_tasks.map((vt) => (
            <div key={vt.id} className="border-b border-gray-100 py-3 last:border-0">
              <div className="mb-2 flex items-center gap-3">
                <Badge tone={vt.status === 'completed' ? 'green' : 'amber'}>{vt.status}</Badge>
                <span className="text-sm text-gray-600">{vt.items_matched} matched · {vt.items_mismatched} mismatched · {vt.sample_size} sampled</span>
                {vt.status !== 'completed' && (
                  <span className="ml-auto text-xs text-gray-500">{vt.items_verified}/{vt.sample_size} done</span>
                )}
              </div>
              {vt.samples.length > 0 && (
                <table className="w-full text-xs">
                  <tbody className="divide-y divide-gray-50">
                    {vt.samples.map((s) => (
                      <tr key={s.id}>
                        <td className="py-1 text-gray-600">Original: {s.original_quantity ?? '—'}</td>
                        <td className="py-1 text-gray-600">Verified: {s.verified_quantity ?? '—'}</td>
                        <td className="py-1 text-gray-600">Variance: {s.variance ?? '—'}</td>
                        <td className="py-1"><Badge tone={s.result === 'match' ? 'green' : s.result === 'mismatch' ? 'red' : 'gray'}>{s.result}</Badge></td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              )}
            </div>
          ))}
        </Card>
      )}

      {/* Create verification task */}
      {isActive && (
        <Card title="Create verification task">
          <div className="flex items-end gap-3">
            <Field label="Sample size">
              <input type="number" min={1} max={500} value={sampleSize}
                onChange={(e) => setSampleSize(parseInt(e.target.value) || 1)}
                className="w-24 rounded-lg border border-gray-300 px-3 py-2 text-sm" />
            </Field>
            <Button variant="secondary" loading={createVt.isPending}
              onClick={() => createVt.mutate({ sample_size: sampleSize })}>
              Create sampling task
            </Button>
          </div>
        </Card>
      )}

      {/* Recount requests */}
      {data.recount_requests.length > 0 && (
        <Card title="Recount requests">
          <ul className="divide-y divide-gray-100">
            {data.recount_requests.map((r) => (
              <li key={r.id} className="flex items-center gap-3 py-3 text-sm">
                <Badge tone={r.status === 'approved' ? 'green' : r.status === 'completed' ? 'gray' : 'amber'}>{r.status}</Badge>
                <span className="text-gray-700">{r.reason}</span>
                <Badge tone="gray">{r.cost_responsibility.replace('_', ' ')}</Badge>
                {r.status === 'pending' && (
                  <Button variant="secondary" loading={approveRecount.isPending}
                    onClick={() => approveRecount.mutate(r.id)} className="ml-auto">
                    Approve
                  </Button>
                )}
                {r.status === 'approved' && (
                  <Button variant="secondary" loading={completeRecount.isPending}
                    onClick={() => completeRecount.mutate(r.id)} className="ml-auto">
                    Complete
                  </Button>
                )}
              </li>
            ))}
          </ul>
        </Card>
      )}
    </div>
  )
}
