import { Link, useParams } from '@tanstack/react-router'
import { useState, type FormEvent } from 'react'
import {
  Alert,
  Badge,
  Button,
  Card,
  EmptyState,
  Field,
  PageHeader,
  PageLoader,
  Table,
} from '../../components/ui'
import { formatDate, formatDateTime, formatMoney } from '../../lib/formatting'
import {
  useDecidePlatformAmendment,
  usePlatformStocktakeJob,
  useRequestPlatformInformation,
  useStartPlatformReview,
  useVerifyPlatformDeposit,
  useWaivePlatformDeposit,
} from '../../lib/stocktake/hooks'
import { AssignmentPanel } from '../workforce/AssignmentPanel'
import { JobOperationsPanel } from '../operations/JobOperationsPanel'
import { JobCountsPanel } from '../count/JobCountsPanel'
import { usePlatformJobCounts } from '../../lib/count/hooks'
import { QuoteBuilder } from './QuoteBuilder'
import { describeError, PriorityBadge, StatusBadge, SummaryRow, Timeline } from './shared'

export function PlatformStocktakeJobPage() {
  const params = useParams({ strict: false }) as { jobId?: string }
  const jobId = params.jobId
  const job = usePlatformStocktakeJob(jobId)
  const startReview = useStartPlatformReview(jobId)
  const requestInfo = useRequestPlatformInformation(jobId)
  const verifyPayment = useVerifyPlatformDeposit(jobId)
  const waiveDeposit = useWaivePlatformDeposit(jobId)
  const decideAmendment = useDecidePlatformAmendment(jobId)
  const counts = usePlatformJobCounts(
    jobId,
    ['in_progress','paused','count_submitted','quality_review','recount_required','awaiting_tenant_review','completion_confirmed','closed'].includes((job.data as {status?:string})?.status ?? ''),
  )

  const [error, setError] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)
  const [showInfoForm, setShowInfoForm] = useState(false)
  const [infoReason, setInfoReason] = useState('')
  const [paymentReason, setPaymentReason] = useState('')
  const [amendmentNotes, setAmendmentNotes] = useState<Record<string, { reason: string; impact: string }>>({})

  if (job.isLoading) return <PageLoader label="Loading job…" />
  if (job.isError || !job.data) {
    return (
      <div className="mx-auto max-w-3xl space-y-4">
        <EmptyState title="Job not found" description="The stock-taking job may have been removed or the link is invalid." />
        <Link to="/platform/stocktaking-jobs" className="text-sm font-medium text-brand-700 hover:underline">
          ← Back to job queue
        </Link>
      </div>
    )
  }

  const data = job.data
  const currency = data.currency
  const permissions = data.permissions
  const pendingPayments = data.deposit?.payments.filter((payment) => payment.status === 'pending') ?? []
  const pendingAmendments = data.amendments.filter((amendment) => amendment.status === 'pending')
  const quote = data.quote

  function amendmentState(id: string) {
    return amendmentNotes[id] ?? { reason: '', impact: '0' }
  }

  function setAmendmentState(id: string, patch: Partial<{ reason: string; impact: string }>) {
    setAmendmentNotes((current) => ({ ...current, [id]: { ...amendmentState(id), ...patch } }))
  }

  function submitInformationRequest(event: FormEvent) {
    event.preventDefault()
    setError(null)
    requestInfo.mutate(infoReason.trim(), {
      onSuccess: () => {
        setShowInfoForm(false)
        setInfoReason('')
        setNotice('Information request sent to the tenant.')
      },
      onError: (err) => setError(describeError(err)),
    })
  }

  return (
    <div className="mx-auto max-w-7xl space-y-6">
      <div>
        <Link to="/platform/stocktaking-jobs" className="text-sm font-medium text-brand-700 hover:underline">
          ← Job queue
        </Link>
      </div>

      <PageHeader
        title={data.title}
        description={`${data.reference} · ${data.tenant.name ?? 'Tenant'} · ${data.scope.label ?? 'All stock locations'}`}
        actions={
          <div className="flex items-center gap-2">
            <StatusBadge status={data.status} label={data.status_label} />
            <PriorityBadge priority={data.priority} />
          </div>
        }
      />

      {error && <Alert kind="error">{error}</Alert>}
      {notice && <Alert kind="success">{notice}</Alert>}

      <div className="grid gap-6 lg:grid-cols-[1.65fr_1fr]">
        <div className="space-y-4">
          <Card title="Review actions">
            <div className="space-y-4">
              {permissions.can_start_review && (
                <div className="flex flex-wrap items-center gap-3">
                  <Button
                    loading={startReview.isPending}
                    onClick={() => {
                      setError(null)
                      startReview.mutate(undefined, {
                        onSuccess: () => setNotice('Job moved to under review.'),
                        onError: (err) => setError(describeError(err)),
                      })
                    }}
                  >
                    Start review
                  </Button>
                  <p className="text-xs text-gray-500">Confirm the scope and catalogue before preparing a quote.</p>
                </div>
              )}

              {permissions.can_send_information_request && (
                <div>
                  {showInfoForm ? (
                    <form onSubmit={submitInformationRequest} className="space-y-3 rounded-lg border border-gray-200 bg-gray-50 p-4">
                      <label className="block text-sm font-medium text-gray-700">What do you need from the tenant?</label>
                      <textarea
                        required
                        minLength={5}
                        rows={3}
                        value={infoReason}
                        onChange={(event) => setInfoReason(event.target.value)}
                        className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"
                      />
                      <div className="flex gap-2">
                        <Button type="submit" variant="secondary" loading={requestInfo.isPending}>
                          Send information request
                        </Button>
                        <Button type="button" variant="secondary" onClick={() => setShowInfoForm(false)}>
                          Cancel
                        </Button>
                      </div>
                    </form>
                  ) : (
                    <Button variant="secondary" onClick={() => setShowInfoForm(true)}>
                      Request more information
                    </Button>
                  )}
                </div>
              )}

              {!permissions.can_start_review && !permissions.can_send_information_request && !permissions.can_issue_quote && (
                <p className="text-sm text-gray-500">No review actions are available for this status.</p>
              )}
              {permissions.can_issue_quote && (
                <p className="text-sm text-gray-600">Prepare the quote below when the scope is clear.</p>
              )}
            </div>
          </Card>

          {(data.status === 'count_submitted' || data.status === 'quality_review' || data.status === 'recount_required') && (
            <Card title="QA review" actions={<Badge tone="amber">{data.status === 'count_submitted' ? 'Awaiting QA' : data.status === 'quality_review' ? 'QA in progress' : 'Recount required'}</Badge>}>
              <p className="mb-3 text-sm text-gray-600">
                {data.status === 'count_submitted'
                  ? 'The worker has submitted their counts. Start a QA review to verify accuracy.'
                  : data.status === 'quality_review'
                  ? 'A QA review is in progress. Open the review to record findings and make a decision.'
                  : 'A recount has been requested. Review the findings and approve to re-open counting.'}
              </p>
              <Link to="/platform/stocktaking-jobs/$jobId/qa" params={{ jobId: data.id }}
                className="inline-block text-sm font-semibold text-brand-700 hover:underline">
                Open QA review →
              </Link>
            </Card>
          )}

          <AssignmentPanel jobId={data.id} jobStatus={data.status} />

          <JobOperationsPanel jobId={data.id} jobStatus={data.status} />

          {counts.data && <JobCountsPanel counts={counts.data} />}

          {quote && (
            <Card
              title={`Issued quote ${quote.number}`}
              actions={
                <div className="flex items-center gap-2">
                  <StatusBadge status={quote.status} />
                  <Badge tone="gray">v{quote.version}</Badge>
                </div>
              }
            >
              <div className="space-y-2 text-sm">
                {[
                  ['Base service charge', quote.base_service_charge],
                  ['Scope complexity', quote.scope_complexity_charge],
                  ['Travel expenses', quote.travel_expenses],
                  ['Other expenses', quote.other_expenses],
                  ['Discount', -quote.discount],
                ]
                  .filter(([, amount]) => amount !== 0)
                  .map(([label, amount]) => (
                    <div key={String(label)} className="flex justify-between text-gray-600">
                      <span>{label}</span>
                      <span>{formatMoney(Number(amount), currency)}</span>
                    </div>
                  ))}
                <div className="flex justify-between border-t border-gray-100 pt-2 text-gray-700">
                  <span>Subtotal</span>
                  <span>{formatMoney(quote.subtotal, currency)}</span>
                </div>
                <div className="flex justify-between text-gray-700">
                  <span>Tax ({quote.tax_rate}%)</span>
                  <span>{formatMoney(quote.tax, currency)}</span>
                </div>
                <div className="flex justify-between text-base font-semibold text-gray-900">
                  <span>Tenant total</span>
                  <span>{formatMoney(quote.total, currency)}</span>
                </div>
                <div className="flex justify-between text-gray-600">
                  <span>Deposit</span>
                  <span>{formatMoney(quote.deposit_amount, currency)}</span>
                </div>
                <div className="flex justify-between text-gray-600">
                  <span>Balance due</span>
                  <span>{formatMoney(quote.balance_due, currency)}</span>
                </div>
                {quote.valid_until && <p className="text-xs text-gray-500">Valid until {formatDate(quote.valid_until)}</p>}
                {quote.notes && <p className="rounded-lg bg-gray-50 p-3 text-gray-600">{quote.notes}</p>}
              </div>

              <div className="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm">
                <p className="text-xs font-semibold uppercase tracking-wide text-amber-800">Internal economics — confidential</p>
                <dl className="mt-2 space-y-1.5">
                  <div className="flex justify-between">
                    <dt className="text-amber-900">Worker compensation budget</dt>
                    <dd className="font-medium text-gray-900">{formatMoney(quote.worker_compensation_budget, currency)}</dd>
                  </div>
                  <div className="flex justify-between">
                    <dt className="text-amber-900">Platform fee</dt>
                    <dd className="font-medium text-gray-900">{formatMoney(quote.platform_fee, currency)}</dd>
                  </div>
                  <div className="flex justify-between">
                    <dt className="text-amber-900">Margin</dt>
                    <dd className={`font-semibold ${quote.margin_amount < 0 ? 'text-red-600' : 'text-gray-900'}`}>
                      {formatMoney(quote.margin_amount, currency)}
                    </dd>
                  </div>
                </dl>
                {quote.worker_compensation_breakdown && quote.worker_compensation_breakdown.length > 0 && (
                  <ul className="mt-2 space-y-1 text-xs text-amber-900">
                    {quote.worker_compensation_breakdown.map((line) => (
                      <li key={line.label} className="flex justify-between">
                        <span>{line.label}</span>
                        <span>{formatMoney(line.amount, currency)}</span>
                      </li>
                    ))}
                  </ul>
                )}
                {quote.internal_notes && <p className="mt-2 text-xs text-amber-900">{quote.internal_notes}</p>}
              </div>
            </Card>
          )}

          {permissions.can_issue_quote && <QuoteBuilder job={data} onIssued={(message) => setNotice(message)} />}

          <Card title="Deposit">
            {!data.deposit ? (
              <p className="text-sm text-gray-500">No deposit has been raised.</p>
            ) : (
              <div className="space-y-4">
                <dl className="space-y-2">
                  <SummaryRow label="Status">
                    <StatusBadge status={data.deposit.status} />
                  </SummaryRow>
                  <SummaryRow label="Required">{formatMoney(data.deposit.amount, currency)}</SummaryRow>
                  <SummaryRow label="Paid">{formatMoney(data.deposit.paid_amount, currency)}</SummaryRow>
                  <SummaryRow label="Outstanding">{formatMoney(data.deposit.outstanding, currency)}</SummaryRow>
                  <SummaryRow label="Due">{data.deposit.due_at ? formatDateTime(data.deposit.due_at) : '—'}</SummaryRow>
                </dl>

                {data.deposit.payments.length > 0 && (
                  <Table headers={['Date', 'Purpose', 'Amount', 'Method', 'Provider reference', 'Status', '']}>
                    {data.deposit.payments.map((payment) => (
                      <tr key={payment.id}>
                        <td className="px-4 py-2 text-gray-600">{payment.created_at ? formatDate(payment.created_at) : '—'}</td>
                        <td className="px-4 py-2 capitalize text-gray-600">{payment.purpose}</td>
                        <td className="px-4 py-2 text-gray-700">{formatMoney(payment.amount, currency)}</td>
                        <td className="px-4 py-2 uppercase text-gray-600">{payment.method}</td>
                        <td className="px-4 py-2 text-xs text-gray-600">
                          {payment.provider_reference ?? payment.checkout_reference ?? payment.internal_reference}
                        </td>
                        <td className="px-4 py-2">
                          <StatusBadge status={payment.status} />
                        </td>
                        <td className="px-4 py-2 text-right">
                          {payment.status === 'pending' && permissions.can_verify_payment && (
                            <div className="flex justify-end gap-2">
                              <Button
                                loading={verifyPayment.isPending && verifyPayment.variables?.paymentId === payment.id}
                                onClick={() => {
                                  setError(null)
                                  verifyPayment.mutate(
                                    { paymentId: payment.id, decision: 'confirm', reason: paymentReason.trim() || undefined },
                                    {
                                      onSuccess: () => {
                                        setPaymentReason('')
                                        setNotice('Deposit payment confirmed.')
                                      },
                                      onError: (err) => setError(describeError(err)),
                                    },
                                  )
                                }}
                              >
                                Confirm
                              </Button>
                              <Button
                                variant="danger"
                                onClick={() => {
                                  setError(null)
                                  verifyPayment.mutate(
                                    { paymentId: payment.id, decision: 'fail', reason: paymentReason.trim() || undefined },
                                    {
                                      onSuccess: () => setNotice('Payment marked as failed. Tenant has been returned to awaiting deposit.'),
                                      onError: (err) => setError(describeError(err)),
                                    },
                                  )
                                }}
                              >
                                Fail
                              </Button>
                            </div>
                          )}
                        </td>
                      </tr>
                    ))}
                  </Table>
                )}

                {pendingPayments.length > 0 && permissions.can_verify_payment && (
                  <Field
                    label="Verification note"
                    value={paymentReason}
                    onChange={(event) => setPaymentReason(event.target.value)}
                    hint="Optional. Added to the payment record and audit trail."
                  />
                )}

                {permissions.can_waive_deposit && (
                  <Button
                    variant="secondary"
                    loading={waiveDeposit.isPending}
                    onClick={() => {
                      const reason = window.prompt('Why is the deposit being waived?')
                      if (!reason || reason.trim().length < 5) return
                      setError(null)
                      waiveDeposit.mutate(reason.trim(), {
                        onSuccess: () => setNotice('Deposit waived and the request returned to review.'),
                        onError: (err) => setError(describeError(err)),
                      })
                    }}
                  >
                    Waive deposit
                  </Button>
                )}
              </div>
            )}
          </Card>

          {data.balance && (
            <Card title={`Balance · ${data.balance.quote_number}`}>
              <dl className="space-y-2">
                <SummaryRow label="Accepted quote total">{formatMoney(data.balance.total, currency)}</SummaryRow>
                <SummaryRow label="Deposit applied">{formatMoney(data.balance.deposit_applied, currency)}</SummaryRow>
                <SummaryRow label="Balance paid">{formatMoney(data.balance.paid, currency)}</SummaryRow>
                <SummaryRow label="Outstanding balance">{formatMoney(data.balance.due, currency)}</SummaryRow>
                <SummaryRow label="Due">{data.balance.due_at ? formatDateTime(data.balance.due_at) : '—'}</SummaryRow>
              </dl>
              <p className="mt-3 text-xs text-gray-500">
                Every settlement is posted to the double-entry ledger. Open the Finance workspace to reconcile provider
                statements and review refunds.
              </p>
            </Card>
          )}

          <Card title={`Amendments (${data.amendments.length})`}>
            {data.amendments.length === 0 ? (
              <p className="text-sm text-gray-500">No amendments requested by the tenant.</p>
            ) : (
              <ul className="divide-y divide-gray-100">
                {data.amendments.map((amendment) => {
                  const state = amendmentState(amendment.id)
                  return (
                    <li key={amendment.id} className="space-y-2 py-3 text-sm">
                      <div className="flex items-center justify-between gap-3">
                        <p className="font-medium text-gray-900">{amendment.reason}</p>
                        <StatusBadge status={amendment.status} />
                      </div>
                      <p className="text-xs text-gray-500">
                        Requested {amendment.requested_at ? formatDateTime(amendment.requested_at) : '—'}
                        {amendment.financial_impact ? ` · Impact ${formatMoney(amendment.financial_impact, currency)}` : ''}
                      </p>
                      <pre className="overflow-x-auto rounded-lg bg-gray-50 p-2 text-xs text-gray-600">
                        {JSON.stringify(amendment.changes, null, 2)}
                      </pre>
                      {amendment.decision_reason && <p className="text-xs text-gray-500">Decision: {amendment.decision_reason}</p>}

                      {amendment.status === 'pending' && permissions.can_decide_amendment && (
                        <div className="space-y-2 rounded-lg border border-gray-200 bg-gray-50 p-3">
                          <div className="grid gap-2 sm:grid-cols-2">
                            <Field
                              label="Decision note"
                              value={state.reason}
                              onChange={(event) => setAmendmentState(amendment.id, { reason: event.target.value })}
                            />
                            <Field
                              label="Financial impact"
                              type="number"
                              step="0.01"
                              value={state.impact}
                              onChange={(event) => setAmendmentState(amendment.id, { impact: event.target.value })}
                            />
                          </div>
                          <div className="flex gap-2">
                            <Button
                              loading={decideAmendment.isPending}
                              onClick={() => {
                                setError(null)
                                decideAmendment.mutate(
                                  {
                                    amendmentId: amendment.id,
                                    decision: 'approve',
                                    reason: state.reason.trim() || undefined,
                                    financial_impact: Number(state.impact) || 0,
                                  },
                                  {
                                    onSuccess: () => setNotice('Amendment approved. The quote must be reissued.'),
                                    onError: (err) => setError(describeError(err)),
                                  },
                                )
                              }}
                            >
                              Approve
                            </Button>
                            <Button
                              variant="danger"
                              onClick={() => {
                                setError(null)
                                decideAmendment.mutate(
                                  {
                                    amendmentId: amendment.id,
                                    decision: 'reject',
                                    reason: state.reason.trim() || undefined,
                                  },
                                  {
                                    onSuccess: () => setNotice('Amendment rejected.'),
                                    onError: (err) => setError(describeError(err)),
                                  },
                                )
                              }}
                            >
                              Reject
                            </Button>
                          </div>
                        </div>
                      )}
                    </li>
                  )
                })}
              </ul>
            )}
            {pendingAmendments.length > 0 && (
              <p className="mt-2 text-xs text-gray-500">
                Approving an amendment supersedes the current quote and returns the job to review.
              </p>
            )}
          </Card>
        </div>

        <div className="space-y-4">
          <Card title="Request details">
            <dl className="space-y-2">
              <SummaryRow label="Tenant">{data.tenant.name ?? '—'}</SummaryRow>
              <SummaryRow label="Requester">{data.requester.name ?? '—'}</SummaryRow>
              <SummaryRow label="Email">{data.requester.email ?? '—'}</SummaryRow>
              <SummaryRow label="Job type">{data.job_type}</SummaryRow>
              <SummaryRow label="Count method">{data.count_method}</SummaryRow>
              <SummaryRow label="Team size">{data.required_team_size}</SummaryRow>
              <SummaryRow label="Scope">{data.scope.label ?? 'All stock locations'}</SummaryRow>
              <SummaryRow label="Preferred start">
                {data.preferred_start_at ? formatDateTime(data.preferred_start_at) : 'Not set'}
              </SummaryRow>
              <SummaryRow label="Deadline">{data.deadline_at ? formatDateTime(data.deadline_at) : 'Not set'}</SummaryRow>
              <SummaryRow label="Catalogue">
                {data.catalogue.version_number ? `Version ${data.catalogue.version_number} · ${data.catalogue.product_count} products` : '—'}
              </SummaryRow>
              <SummaryRow label="Provisional total">
                {formatMoney(Number((data.provisional_estimate as { total?: number } | null | undefined)?.total ?? 0), currency)}
              </SummaryRow>
              <SummaryRow label="Submitted">
                {data.submitted_at ? formatDateTime(data.submitted_at) : 'Not submitted'}
              </SummaryRow>
            </dl>
            {data.description && <p className="mt-3 rounded-lg bg-gray-50 p-3 text-sm text-gray-600">{data.description}</p>}
          </Card>

          {data.request_declaration && (
            <Card title="Request-specific declaration">
              <p className="text-sm text-gray-700">{data.request_declaration.declaration_text}</p>
              <dl className="mt-3 space-y-2 text-sm">
                <SummaryRow label="Revision">{data.request_declaration.revision}</SummaryRow>
                <SummaryRow label="Catalogue version">
                  {data.request_declaration.catalogue_version_number ?? '—'}
                </SummaryRow>
                <SummaryRow label="Declared by">{data.request_declaration.declared_by ?? '—'}</SummaryRow>
                <SummaryRow label="Accepted">
                  {data.request_declaration.accepted_at
                    ? formatDateTime(data.request_declaration.accepted_at)
                    : '—'}
                </SummaryRow>
              </dl>
              {data.request_declaration.exceptions && (
                <p className="mt-3 rounded-lg bg-amber-50 p-3 text-sm text-amber-900">
                  Exceptions: {data.request_declaration.exceptions}
                </p>
              )}
              {data.request_declaration_history.length > 1 && (
                <ul className="mt-3 space-y-2 border-t border-gray-100 pt-3">
                  {data.request_declaration_history.slice(1).map((declaration) => (
                    <li key={declaration.id} className="text-xs text-gray-600">
                      <p className="font-medium">Revision {declaration.revision}</p>
                      <p>
                        {declaration.declared_by ?? 'Unknown'} ·{' '}
                        {declaration.accepted_at ? formatDateTime(declaration.accepted_at) : '—'}
                      </p>
                      {declaration.exceptions && <p>Exceptions: {declaration.exceptions}</p>}
                    </li>
                  ))}
                </ul>
              )}
            </Card>
          )}

          <Card title="Requirements">
            <dl className="space-y-2 text-sm">
              {[
                ['Safety', data.requirements.safety],
                ['Access', data.requirements.access],
                ['Stock movement cut-off', data.requirements.stock_movement_cutoff],
                ['Skills', (data.requirements.skills ?? []).join(', ') || null],
                ['Qualifications', data.requirements.qualifications],
                ['Evidence', (data.requirements.evidence ?? []).join(', ') || null],
                ['Sign-offs', data.requirements.signoffs],
                ['Special instructions', data.sop.instructions],
              ].map(([label, value]) => (
                <div key={String(label)}>
                  <dt className="text-gray-500">{label}</dt>
                  <dd className="text-gray-900">{value || '—'}</dd>
                </div>
              ))}
            </dl>
          </Card>

          <Card title="Status history">
            <Timeline entries={data.timeline} />
          </Card>
        </div>
      </div>
    </div>
  )
}
