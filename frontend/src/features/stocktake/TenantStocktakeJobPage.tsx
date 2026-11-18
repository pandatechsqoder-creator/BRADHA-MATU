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
  Select,
  Table,
} from '../../components/ui'
import { useCurrentUser } from '../../lib/auth/hooks'
import { formatDate, formatDateTime, formatMoney } from '../../lib/formatting'
import {
  useAcceptStocktakeQuote,
  useAmendStocktakeJob,
  useCancelStocktakeJob,
  useDeclineStocktakeQuote,
  usePayStocktakeJob,
  useSimulateStocktakePayment,
  useTenantStocktakeJob,
} from '../../lib/stocktake/hooks'
import type { StocktakePayment, StocktakeQuote } from '../../lib/api/stocktake'
import { TenantOperationsCard } from '../operations/TenantOperationsCard'
import { JobCountsPanel } from '../count/JobCountsPanel'
import { useTenantJobCounts } from '../../lib/count/hooks'
import { describeError, fromDateInput, PriorityBadge, StatusBadge, SummaryRow, Timeline } from './shared'

type PaymentMethod = 'mpesa' | 'card' | 'bank' | 'manual'

function PaymentForm({
  tenantId,
  jobId,
  purpose,
  outstanding,
  currency,
  onError,
  onNotice,
}: {
  tenantId: string
  jobId: string
  purpose: 'deposit' | 'balance'
  outstanding: number
  currency: string
  onError: (message: string | null) => void
  onNotice: (message: string | null) => void
}) {
  const pay = usePayStocktakeJob(tenantId, jobId)
  const [amount, setAmount] = useState('')
  const [method, setMethod] = useState<PaymentMethod>('mpesa')
  const [phone, setPhone] = useState('')
  const [notes, setNotes] = useState('')
  const [attemptKey, setAttemptKey] = useState(() => crypto.randomUUID())

  return (
    <form
      className="space-y-3 rounded-lg border border-gray-200 bg-gray-50 p-4"
      onSubmit={(event) => {
        event.preventDefault()
        onError(null)
        pay.mutate(
          {
            data: {
              purpose,
              amount: Number(amount || outstanding),
              method,
              phone: method === 'mpesa' ? phone.trim() : null,
              notes: notes.trim() || null,
            },
            idempotencyKey: attemptKey,
          },
          {
            onSuccess: ({ payment }) => {
              setAmount('')
              setNotes('')
              setAttemptKey(crypto.randomUUID())
              onNotice(
                payment.status === 'processing'
                  ? 'Payment initiated. BRATHAMATU only marks it paid after the signed provider callback — use the sandbox simulator below.'
                  : 'Payment submitted. BRATHAMATU finance will verify the funds before it is applied.',
              )
            },
            onError: (err) => onError(describeError(err)),
          },
        )
      }}
    >
      <p className="text-sm font-medium text-gray-800">
        Pay {purpose === 'deposit' ? 'the deposit' : 'the outstanding balance'}
      </p>
      <div className="grid gap-3 sm:grid-cols-2">
        <Field
          label="Amount"
          type="number"
          min={0}
          step="0.01"
          value={amount}
          onChange={(event) => setAmount(event.target.value)}
          placeholder={String(outstanding)}
          hint={`Outstanding: ${formatMoney(outstanding, currency)}`}
        />
        <Select
          label="Method"
          value={method}
          onChange={(event) => setMethod(event.target.value as PaymentMethod)}
        >
          <option value="mpesa">M-Pesa STK Push (sandbox)</option>
          <option value="card">Card gateway (sandbox)</option>
          <option value="bank">Bank transfer</option>
          <option value="manual">Manual / cash</option>
        </Select>
      </div>
      {method === 'mpesa' && (
        <Field
          label="M-Pesa phone number"
          value={phone}
          onChange={(event) => setPhone(event.target.value)}
          placeholder="254712345678"
          hint="The sandbox STK push is addressed to this number."
        />
      )}
      <Field label="Notes" value={notes} onChange={(event) => setNotes(event.target.value)} />
      <Button type="submit" loading={pay.isPending}>
        Start payment
      </Button>
      <p className="text-xs text-gray-500">
        Card and M-Pesa payments settle only from a signed provider callback. Returning from a checkout page does not
        mark this payment paid.
      </p>
    </form>
  )
}

interface ProvisionalEstimate {
  lines?: { label: string; amount: number }[]
  total?: number
  currency?: string
}

function QuoteBreakdown({ quote, currency }: { quote: StocktakeQuote; currency: string }) {
  const lines: [string, number][] = [
    ['Base service charge', quote.base_service_charge],
    ['Scope complexity', quote.scope_complexity_charge],
    ['Travel expenses', quote.travel_expenses],
    ['Other expenses', quote.other_expenses],
    ['Discount', -quote.discount],
  ]
  return (
    <div className="space-y-2 text-sm">
      <ul className="space-y-1.5">
        {lines
          .filter(([, amount]) => amount !== 0)
          .map(([label, amount]) => (
            <li key={label} className="flex justify-between gap-3 text-gray-600">
              <span>{label}</span>
              <span>{formatMoney(amount, currency)}</span>
            </li>
          ))}
      </ul>
      <div className="space-y-1 border-t border-gray-100 pt-2 text-gray-700">
        <div className="flex justify-between gap-3">
          <span>Subtotal</span>
          <span>{formatMoney(quote.subtotal, currency)}</span>
        </div>
        <div className="flex justify-between gap-3">
          <span>Tax ({quote.tax_rate}%)</span>
          <span>{formatMoney(quote.tax, currency)}</span>
        </div>
      </div>
      <div className="flex justify-between gap-3 text-base font-semibold text-gray-900">
        <span>Total</span>
        <span>{formatMoney(quote.total, currency)}</span>
      </div>
      <div className="flex justify-between gap-3 text-gray-600">
        <span>Deposit</span>
        <span>{formatMoney(quote.deposit_amount, currency)}</span>
      </div>
      <div className="flex justify-between gap-3 font-medium text-gray-900">
        <span>Balance after deposit</span>
        <span>{formatMoney(quote.balance_due, currency)}</span>
      </div>
      {quote.valid_until && <p className="text-xs text-gray-500">Valid until {formatDate(quote.valid_until)}</p>}
      {quote.notes && <p className="rounded-lg bg-gray-50 p-3 text-sm text-gray-600">{quote.notes}</p>}
    </div>
  )
}

export function TenantStocktakeJobPage() {
  const params = useParams({ strict: false }) as { jobId?: string }
  const jobId = params.jobId
  const { data: user, isLoading } = useCurrentUser()
  const tenantId = user?.current_tenant?.id
  const job = useTenantStocktakeJob(tenantId, jobId)
  const acceptQuote = useAcceptStocktakeQuote(tenantId, jobId)
  const declineQuote = useDeclineStocktakeQuote(tenantId, jobId)
  const simulatePayment = useSimulateStocktakePayment(tenantId, jobId)
  const amendJob = useAmendStocktakeJob(tenantId, jobId)
  const cancelJob = useCancelStocktakeJob(tenantId, jobId)
  const counts = useTenantJobCounts(tenantId, jobId)

  const [error, setError] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)
  const [showDecline, setShowDecline] = useState(false)
  const [declineReason, setDeclineReason] = useState('')
  const [showCancel, setShowCancel] = useState(false)
  const [cancelReason, setCancelReason] = useState('')
  const [showAmend, setShowAmend] = useState(false)
  const [amendReason, setAmendReason] = useState('')
  const [amendTitle, setAmendTitle] = useState('')
  const [amendTeamSize, setAmendTeamSize] = useState('')
  const [amendDeadline, setAmendDeadline] = useState('')
  const [amendInstructions, setAmendInstructions] = useState('')

  function simulate(payment: StocktakePayment, outcome: 'success' | 'failure') {
    setError(null)
    setNotice(null)
    simulatePayment.mutate(
      { paymentId: payment.id, outcome },
      {
        onSuccess: ({ result }) =>
          setNotice(
            result.status === 'accepted'
              ? outcome === 'success'
                ? 'Signed sandbox callback accepted. The payment is settled and posted to the ledger.'
                : 'Signed sandbox callback marked the payment failed. You can start another attempt.'
              : result.message ?? 'The provider callback was not accepted.',
          ),
        onError: (err) => setError(describeError(err)),
      },
    )
  }

  if (isLoading) return <PageLoader label="Loading request…" />
  if (!tenantId) return <Alert kind="error">Select a business before viewing stock-taking requests.</Alert>
  if (job.isLoading) return <PageLoader label="Loading request…" />
  if (job.isError || !job.data) {
    return (
      <div className="mx-auto max-w-3xl space-y-4">
        <EmptyState title="Request not found" description="This stock-taking request may belong to another organisation." />
        <Link to="/business/stocktaking" className="text-sm font-medium text-brand-700 hover:underline">
          ← Back to stock-taking jobs
        </Link>
      </div>
    )
  }

  const data = job.data
  const currency = data.currency
  const permissions = data.permissions
  const provisional = data.provisional_estimate as ProvisionalEstimate | null | undefined
  const latestInfoRequest = [...data.timeline]
    .reverse()
    .find((entry) => entry.to_status === 'needs_tenant_information')
  const openDeposit = Boolean(data.deposit && data.deposit.outstanding > 0 && data.deposit.status !== 'waived')
  const payableAmount = data.deposit?.outstanding ?? data.deposit_amount
  const canViewOperations = [
    'stocktake.request.create',
    'stocktake.quote.view',
    'stocktake.results.review',
    'finance.invoice.view',
    'report.view',
  ].some((permission) => user?.permissions.includes(permission))

  function submitAmendment(event: FormEvent) {
    event.preventDefault()
    setError(null)
    setNotice(null)
    const changes: Record<string, unknown> = {}
    if (amendTitle.trim()) changes.title = amendTitle.trim()
    if (amendTeamSize.trim()) changes.required_team_size = Number(amendTeamSize)
    if (amendDeadline) changes.deadline_at = fromDateInput(amendDeadline)
    if (amendInstructions.trim()) changes.custom_instructions = amendInstructions.trim()
    if (Object.keys(changes).length === 0) {
      setError('Describe at least one change besides the reason.')
      return
    }
    amendJob.mutate(
      { reason: amendReason.trim(), changes },
      {
        onSuccess: () => {
          setShowAmend(false)
          setAmendReason('')
          setAmendTitle('')
          setAmendTeamSize('')
          setAmendDeadline('')
          setAmendInstructions('')
          setNotice('Amendment requested. BRATHAMATU will review it before reissuing the quote.')
        },
        onError: (err) => setError(describeError(err)),
      },
    )
  }

  return (
    <div className="mx-auto max-w-7xl space-y-6">
      <div>
        <Link to="/business/stocktaking" className="text-sm font-medium text-brand-700 hover:underline">
          ← Stock-taking jobs
        </Link>
      </div>

      <PageHeader
        title={data.title}
        description={`${data.reference} · ${data.scope.label ?? 'All stock locations'}`}
        actions={
          <div className="flex items-center gap-2">
            <StatusBadge status={data.status} label={data.status_label} />
            <PriorityBadge priority={data.priority} />
          </div>
        }
      />

      {error && <Alert kind="error">{error}</Alert>}
      {notice && <Alert kind="success">{notice}</Alert>}

      {data.status === 'needs_tenant_information' && (
        <Alert kind="info">
          <p className="font-medium">BRATHAMATU needs more information.</p>
          {latestInfoRequest?.reason && <p className="mt-1">{latestInfoRequest.reason}</p>}
          <Link
            to="/business/stocktaking/$jobId/edit"
            params={{ jobId: data.id }}
            className="mt-2 inline-block font-medium underline"
          >
            Update and resubmit →
          </Link>
        </Alert>
      )}

      <div className="grid gap-6 lg:grid-cols-[1.6fr_1fr]">
        <div className="space-y-4">
          <Card
            title={data.quote ? `Quote ${data.quote.number}` : 'Provisional estimate'}
            actions={data.quote ? <StatusBadge status={data.quote.status} /> : <Badge tone="amber">Provisional</Badge>}
          >
            {data.quote ? (
              <div className="space-y-4">
                <QuoteBreakdown quote={data.quote} currency={currency} />
                {permissions.can_accept_quote && (
                  <div className="space-y-3">
                    {openDeposit && (
                      <p className="text-xs text-amber-700">Pay the required deposit below before accepting this quote.</p>
                    )}
                    <div className="flex flex-wrap gap-2">
                      <Button
                        loading={acceptQuote.isPending}
                        disabled={openDeposit}
                        onClick={() => {
                          if (!window.confirm(`Accept quote ${data.quote?.number} for ${formatMoney(data.quote?.total ?? 0, currency)}?`)) return
                          setError(null)
                          acceptQuote.mutate(undefined, {
                            onSuccess: () => setNotice('Quote accepted. The job is now approved for execution.'),
                            onError: (err) => setError(describeError(err)),
                          })
                        }}
                      >
                        Accept quote
                      </Button>
                      <Button variant="secondary" onClick={() => setShowDecline((current) => !current)}>
                        Request revision
                      </Button>
                    </div>
                    {showDecline && (
                      <form
                        className="space-y-3 rounded-lg border border-gray-200 bg-gray-50 p-3"
                        onSubmit={(event) => {
                          event.preventDefault()
                          setError(null)
                          declineQuote.mutate(declineReason.trim(), {
                            onSuccess: () => {
                              setShowDecline(false)
                              setDeclineReason('')
                              setNotice('Revision requested. BRATHAMATU will issue an updated quote.')
                            },
                            onError: (err) => setError(describeError(err)),
                          })
                        }}
                      >
                        <label className="block text-sm font-medium text-gray-700">What should change?</label>
                        <textarea
                          required
                          minLength={5}
                          rows={3}
                          value={declineReason}
                          onChange={(event) => setDeclineReason(event.target.value)}
                          className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"
                        />
                        <Button type="submit" variant="secondary" loading={declineQuote.isPending}>
                          Send revision request
                        </Button>
                      </form>
                    )}
                  </div>
                )}
                {data.quote.status === 'accepted' && (
                  <Alert kind="success">You accepted this quote on {data.quote.accepted_at ? formatDate(data.quote.accepted_at) : '—'}.</Alert>
                )}
                {data.quote.decline_reason && (
                  <Alert kind="info">Revision requested: {data.quote.decline_reason}</Alert>
                )}
              </div>
            ) : provisional?.lines ? (
              <div className="space-y-2 text-sm">
                <ul className="space-y-1.5">
                  {provisional.lines.map((line) => (
                    <li key={line.label} className="flex justify-between gap-3 text-gray-600">
                      <span>{line.label}</span>
                      <span>{formatMoney(line.amount, currency)}</span>
                    </li>
                  ))}
                </ul>
                <div className="flex justify-between gap-3 border-t border-gray-100 pt-2 text-base font-semibold text-gray-900">
                  <span>Provisional total</span>
                  <span>{formatMoney(provisional.total ?? data.deposit_amount, currency)}</span>
                </div>
                <p className="text-xs text-gray-500">
                  BRATHAMATU reviews the request and issues a formal quote. The final charge may differ from this estimate.
                </p>
              </div>
            ) : (
              <p className="text-sm text-gray-500">A provisional estimate will appear once the request is created.</p>
            )}
          </Card>

          <Card title="Deposit">
            {!data.deposit ? (
              <p className="text-sm text-gray-500">No deposit has been raised for this request yet.</p>
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
                {data.deposit.overdue && <Alert kind="error">This deposit is overdue. Pay now to keep the request in review.</Alert>}

                {permissions.can_pay_deposit && tenantId && jobId && (
                  <PaymentForm
                    tenantId={tenantId}
                    jobId={jobId}
                    purpose="deposit"
                    outstanding={payableAmount}
                    currency={currency}
                    onError={setError}
                    onNotice={setNotice}
                  />
                )}
              </div>
            )}
          </Card>

          {data.balance && (
            <Card title={`Balance · ${data.balance.quote_number}`}>
              <div className="space-y-4">
                <dl className="space-y-2">
                  <SummaryRow label="Accepted quote total">{formatMoney(data.balance.total, currency)}</SummaryRow>
                  <SummaryRow label="Deposit applied">{formatMoney(data.balance.deposit_applied, currency)}</SummaryRow>
                  <SummaryRow label="Balance paid">{formatMoney(data.balance.paid, currency)}</SummaryRow>
                  <SummaryRow label="Outstanding balance">{formatMoney(data.balance.due, currency)}</SummaryRow>
                  <SummaryRow label="Due">{data.balance.due_at ? formatDateTime(data.balance.due_at) : '—'}</SummaryRow>
                </dl>
                {data.balance.due <= 0 ? (
                  <Alert kind="success">The balance is settled in full. This job is approved for execution.</Alert>
                ) : (
                  <>
                    {permissions.can_pay_balance && tenantId && jobId && (
                      <PaymentForm
                        tenantId={tenantId}
                        jobId={jobId}
                        purpose="balance"
                        outstanding={data.balance.due}
                        currency={currency}
                        onError={setError}
                        onNotice={setNotice}
                      />
                    )}
                    {!permissions.can_pay_balance && (
                      <p className="text-sm text-gray-500">
                        A balance payment is already in progress. It continues only after the provider callback or finance
                        verification.
                      </p>
                    )}
                  </>
                )}
              </div>
            </Card>
          )}

          <Card title={`Payments (${data.payments.length})`}>
            {data.payments.length === 0 ? (
              <p className="text-sm text-gray-500">No payments have been initiated for this request.</p>
            ) : (
              <div className="space-y-3">
                <Table headers={['Date', 'Purpose', 'Amount', 'Method', 'Status', 'Provider reference', '']}>
                  {data.payments.map((payment) => (
                    <tr key={payment.id}>
                      <td className="px-4 py-2 text-gray-600">{payment.created_at ? formatDateTime(payment.created_at) : '—'}</td>
                      <td className="px-4 py-2 capitalize text-gray-600">{payment.purpose}</td>
                      <td className="px-4 py-2 text-gray-700">{formatMoney(payment.amount, currency)}</td>
                      <td className="px-4 py-2 uppercase text-gray-600">{payment.method}</td>
                      <td className="px-4 py-2">
                        <StatusBadge status={payment.status} />
                        {payment.failure_reason && <p className="mt-1 text-xs text-red-600">{payment.failure_reason}</p>}
                      </td>
                      <td className="px-4 py-2 text-xs text-gray-600">
                        {payment.provider_reference ?? payment.checkout_reference ?? payment.internal_reference}
                      </td>
                      <td className="px-4 py-2">
                        {payment.can_simulate && (
                          <div className="flex flex-wrap gap-1">
                            <Button
                              variant="secondary"
                              loading={simulatePayment.isPending}
                              onClick={() => simulate(payment, 'success')}
                            >
                              Simulate success
                            </Button>
                            <Button
                              variant="secondary"
                              loading={simulatePayment.isPending}
                              onClick={() => simulate(payment, 'failure')}
                            >
                              Simulate failure
                            </Button>
                          </div>
                        )}
                      </td>
                    </tr>
                  ))}
                </Table>
                {data.payments.some((payment) => payment.can_simulate) && (
                  <p className="text-xs text-gray-500">
                    Sandbox mode only: simulation sends a signed callback through the production callback pipeline. A
                    browser redirect alone never settles a payment.
                  </p>
                )}
              </div>
            )}
          </Card>

          <Card
            title={`Amendments (${data.amendments.length})`}
            actions={
              permissions.can_amend ? (
                <Button variant="secondary" onClick={() => setShowAmend((current) => !current)}>
                  Request amendment
                </Button>
              ) : undefined
            }
          >
            <div className="space-y-4">
              {showAmend && permissions.can_amend && (
                <form onSubmit={submitAmendment} className="space-y-3 rounded-lg border border-gray-200 bg-gray-50 p-4">
                  <label className="block text-sm font-medium text-gray-700">Reason for the change</label>
                  <textarea
                    required
                    minLength={5}
                    rows={2}
                    value={amendReason}
                    onChange={(event) => setAmendReason(event.target.value)}
                    className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"
                  />
                  <div className="grid gap-3 sm:grid-cols-3">
                    <Field label="New title" value={amendTitle} onChange={(event) => setAmendTitle(event.target.value)} />
                    <Field
                      label="New team size"
                      type="number"
                      min={1}
                      value={amendTeamSize}
                      onChange={(event) => setAmendTeamSize(event.target.value)}
                    />
                    <Field
                      label="New deadline"
                      type="datetime-local"
                      value={amendDeadline}
                      onChange={(event) => setAmendDeadline(event.target.value)}
                    />
                  </div>
                  <label className="block text-sm font-medium text-gray-700">New instructions</label>
                  <textarea
                    rows={2}
                    value={amendInstructions}
                    onChange={(event) => setAmendInstructions(event.target.value)}
                    className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"
                  />
                  <Button type="submit" loading={amendJob.isPending}>
                    Submit amendment
                  </Button>
                </form>
              )}

              {data.amendments.length === 0 ? (
                <p className="text-sm text-gray-500">No amendments requested.</p>
              ) : (
                <ul className="divide-y divide-gray-100">
                  {data.amendments.map((amendment) => (
                    <li key={amendment.id} className="space-y-1 py-3 text-sm">
                      <div className="flex items-center justify-between gap-3">
                        <p className="font-medium text-gray-900">{amendment.reason}</p>
                        <StatusBadge status={amendment.status} />
                      </div>
                      <p className="text-xs text-gray-500">
                        Requested {amendment.requested_at ? formatDateTime(amendment.requested_at) : '—'}
                        {amendment.decided_at ? ` · Decided ${formatDateTime(amendment.decided_at)}` : ''}
                      </p>
                      <pre className="overflow-x-auto rounded-lg bg-gray-50 p-2 text-xs text-gray-600">
                        {JSON.stringify(amendment.changes, null, 2)}
                      </pre>
                      {amendment.decision_reason && <p className="text-xs text-gray-500">Decision: {amendment.decision_reason}</p>}
                    </li>
                  ))}
                </ul>
              )}
            </div>
          </Card>
        </div>

        <div className="space-y-4">
          <Card title="Request details">
            <dl className="space-y-2">
              <SummaryRow label="Reference">{data.reference}</SummaryRow>
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
              <SummaryRow label="Submitted">
                {data.submitted_at ? formatDateTime(data.submitted_at) : 'Not submitted'}
              </SummaryRow>
            </dl>
            {data.description && <p className="mt-3 rounded-lg bg-gray-50 p-3 text-sm text-gray-600">{data.description}</p>}
          </Card>

          <TenantOperationsCard
            tenantId={tenantId}
            jobId={data.id}
            enabled={canViewOperations && ['scheduled', 'in_progress', 'paused', 'count_submitted', 'quality_review', 'recount_required', 'awaiting_tenant_review'].includes(data.status)}
          />

          {counts.data && ['count_submitted','quality_review','recount_required','awaiting_tenant_review','completion_confirmed','closed'].includes(data.status) && (
            <JobCountsPanel counts={counts.data} />
          )}

          {/* Review results card — shown when results are ready for tenant review */}
          {['awaiting_tenant_review', 'tenant_correction_requested', 'disputed', 'completion_confirmed'].includes(data.status) && (
            <div className="rounded-xl border border-brand-200 bg-brand-50 px-4 py-4">
              <div className="flex items-center justify-between gap-3">
                <div>
                  <p className="font-semibold text-brand-900">
                    {data.status === 'awaiting_tenant_review' ? 'Count results are ready for your review.' :
                     data.status === 'completion_confirmed' ? 'Results accepted.' :
                     data.status === 'disputed' ? 'Dispute in progress.' :
                     'Correction requested.'}
                  </p>
                  <p className="mt-0.5 text-sm text-brand-700">
                    {data.status === 'awaiting_tenant_review' ? 'Review the counted quantities, flag discrepancies, or accept the results.' :
                     data.status === 'completion_confirmed' ? 'The count results have been confirmed and the job is complete.' :
                     data.status === 'disputed' ? 'A dispute has been raised and is being reviewed by BRATHAMATU.' :
                     'Your correction request has been submitted.'}
                  </p>
                </div>
                <Link to="/business/stocktaking/$jobId/review" params={{ jobId: data.id }}
                  className="shrink-0 rounded-lg bg-brand-600 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-700">
                  {data.status === 'completion_confirmed' ? 'View results' : 'Open review →'}
                </Link>
              </div>
            </div>
          )}

          {permissions.can_edit && data.status !== 'needs_tenant_information' && (
            <Card title="Draft actions">
              <div className="space-y-3">
                <Link
                  to="/business/stocktaking/$jobId/edit"
                  params={{ jobId: data.id }}
                  className="inline-block text-sm font-medium text-brand-700 hover:underline"
                >
                  Edit this draft →
                </Link>
                <p className="text-xs text-gray-500">Submit the request when the scope and requirements are final.</p>
              </div>
            </Card>
          )}

          {permissions.can_cancel && (
            <Card title="Cancel request">
              {showCancel ? (
                <form
                  className="space-y-3"
                  onSubmit={(event) => {
                    event.preventDefault()
                    setError(null)
                    cancelJob.mutate(cancelReason.trim(), {
                      onSuccess: (updated) => {
                        setShowCancel(false)
                        const refund = updated.cancellation?.refund_due
                        setNotice(
                          typeof refund === 'number'
                            ? `Request cancelled. Refund due: ${formatMoney(refund, currency)}.`
                            : 'Request cancelled.',
                        )
                      },
                      onError: (err) => setError(describeError(err)),
                    })
                  }}
                >
                  <textarea
                    required
                    minLength={5}
                    rows={3}
                    value={cancelReason}
                    onChange={(event) => setCancelReason(event.target.value)}
                    placeholder="Why are you cancelling?"
                    className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"
                  />
                  <div className="flex gap-2">
                    <Button type="submit" variant="danger" loading={cancelJob.isPending}>
                      Confirm cancellation
                    </Button>
                    <Button type="button" variant="secondary" onClick={() => setShowCancel(false)}>
                      Keep request
                    </Button>
                  </div>
                  <p className="text-xs text-gray-500">
                    Cancellation fees can apply once a quote has been accepted. Refunds are processed by BRATHAMATU finance.
                  </p>
                </form>
              ) : (
                <Button variant="danger" onClick={() => setShowCancel(true)}>
                  Cancel request
                </Button>
              )}
            </Card>
          )}

          <Card title="Status history">
            <Timeline entries={data.timeline} />
          </Card>
        </div>
      </div>
    </div>
  )
}
