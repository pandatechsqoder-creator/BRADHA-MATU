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
  StatCard,
  Table,
} from '../../components/ui'
import { useCurrentUser } from '../../lib/auth/hooks'
import { ApiError } from '../../lib/api/client'
import { PayoutQueuePanel } from './PayoutQueuePanel'
import type {
  FinancePayment,
  FinanceReconciliation,
  FinanceRefund,
  ReconciliationInput,
  TrialBalance as FinanceTrialBalance,
} from '../../lib/api/finance'
import {
  useCreateFinanceReconciliation,
  useCreateFinanceRefund,
  useDecideFinanceRefund,
  useFinanceLedger,
  useFinanceOverview,
  useFinancePayments,
  useFinanceReconciliation,
  useFinanceReconciliations,
  useFinanceRefunds,
  useVerifyFinancePayment,
} from '../../lib/finance/hooks'
import { formatDate, formatDateTime, formatMoney, formatStatusLabel } from '../../lib/formatting'
import { describeError } from '../stocktake/shared'

type Tone = 'gray' | 'green' | 'red' | 'amber'
type Tab = 'overview' | 'payments' | 'ledger' | 'reconciliations' | 'refunds' | 'payouts'

const TAB_LABELS: Record<Tab, string> = {
  overview: 'Overview',
  payments: 'Payments',
  ledger: 'Ledger',
  reconciliations: 'Reconciliations',
  refunds: 'Refunds',
  payouts: 'Payouts',
}

function toneForStatus(status: string): Tone {
  if (['succeeded', 'completed', 'matched', 'balanced', 'waived', 'paid'].includes(status)) return 'green'
  if (['failed', 'cancelled', 'unmatched', 'amount_mismatch', 'missing', 'variance'].includes(status)) {
    return 'red'
  }
  if (['pending', 'processing', 'requested', 'approved'].includes(status)) return 'amber'
  return 'gray'
}

function StatusBadge({ status }: { status: string }) {
  return <Badge tone={toneForStatus(status)}>{formatStatusLabel(status)}</Badge>
}

function describe(error: unknown): string {
  if (error instanceof ApiError) return error.message
  return describeError(error)
}

function Pager({
  current,
  last,
  total,
  label,
  onChange,
}: {
  current: number
  last: number
  total: number
  label: string
  onChange: (page: number) => void
}) {
  return (
    <div className="flex items-center justify-between text-xs text-gray-500">
      <span>
        Page {current} of {last} · {total} {label}
      </span>
      <div className="flex gap-2">
        <Button variant="secondary" disabled={current <= 1} onClick={() => onChange(current - 1)}>
          Previous
        </Button>
        <Button variant="secondary" disabled={current >= last} onClick={() => onChange(current + 1)}>
          Next
        </Button>
      </div>
    </div>
  )
}

function TrialBalance({ trial, currency = 'KES' }: { trial: FinanceTrialBalance; currency?: string }) {
  if (trial.accounts.length === 0) {
    return <EmptyState title="No ledger activity" description="Postings appear here as payments settle and refunds complete." />
  }
  return (
    <div className="space-y-3">
      <Table headers={['Account', 'Debit', 'Credit', 'Balance']}>
        {trial.accounts.map((account) => (
          <tr key={account.code}>
            <td className="px-4 py-2">
              <p className="font-medium text-gray-800">
                {account.code} · {account.name}
              </p>
              <p className="text-xs capitalize text-gray-500">{account.type.replaceAll('_', ' ')}</p>
            </td>
            <td className="px-4 py-2 text-gray-700">{formatMoney(account.debit, currency)}</td>
            <td className="px-4 py-2 text-gray-700">{formatMoney(account.credit, currency)}</td>
            <td className="px-4 py-2 text-gray-900">{formatMoney(account.balance, currency)}</td>
          </tr>
        ))}
        <tr className="bg-gray-50 font-semibold">
          <td className="px-4 py-2 text-gray-700">Totals</td>
          <td className="px-4 py-2 text-gray-900">{formatMoney(trial.totals.debit, currency)}</td>
          <td className="px-4 py-2 text-gray-900">{formatMoney(trial.totals.credit, currency)}</td>
          <td className="px-4 py-2">
            <Badge tone={trial.balanced ? 'green' : 'red'}>{trial.balanced ? 'Balanced' : 'Out of balance'}</Badge>
          </td>
        </tr>
      </Table>
      <p className="text-xs text-gray-500">
        Ledger entries are immutable. Corrections are posted as reversals, never edited in place.
      </p>
    </div>
  )
}

function OverviewTab({ onOpenPayments }: { onOpenPayments: () => void }) {
  const query = useFinanceOverview()

  if (query.isLoading) return <PageLoader label="Loading finance overview…" />
  if (query.isError) {
    return <Alert kind="error">The finance overview could not be loaded.</Alert>
  }

  const data = query.data
  if (!data) return null

  return (
    <div className="space-y-6">
      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <StatCard label="Collected today" value={formatMoney(data.collected_today)} hint="Settled payments only" />
        <StatCard label="Collected this month" value={formatMoney(data.collected_this_month)} />
        <StatCard
          label="Awaiting verification"
          value={data.awaiting_verification}
          hint="Bank and manual payments"
        />
        <StatCard label="Refunds pending" value={data.refunds_requested} hint="Requested or approved" />
        <StatCard label="Processing" value={data.processing} hint="Provider payments in flight" />
        <StatCard label="Failed payments" value={data.failed_payments} />
        <StatCard label="Jobs awaiting deposit" value={data.awaiting_deposit_jobs} />
        <StatCard label="Jobs awaiting balance" value={data.awaiting_balance_jobs} />
      </div>

      <div className="grid gap-6 lg:grid-cols-2">
        <Card title="Collected by method">
          {data.by_method.length === 0 ? (
            <p className="text-sm text-gray-500">No settled payments yet.</p>
          ) : (
            <ul className="space-y-2 text-sm">
              {data.by_method.map((row) => (
                <li key={row.method} className="flex items-center justify-between">
                  <span className="uppercase text-gray-600">{row.method}</span>
                  <span className="text-gray-900">
                    {formatMoney(row.total)}{' '}
                    <span className="text-xs text-gray-500">({row.payments} payments)</span>
                  </span>
                </li>
              ))}
            </ul>
          )}
        </Card>

        <Card
          title="Recent reconciliations"
          actions={
            <Badge tone={data.ledger.balanced ? 'green' : 'red'}>
              {data.ledger.balanced ? 'Ledger balanced' : 'Ledger out of balance'}
            </Badge>
          }
        >
          {data.recent_reconciliations.length === 0 ? (
            <p className="text-sm text-gray-500">No reconciliations recorded yet.</p>
          ) : (
            <ul className="space-y-2 text-sm">
              {data.recent_reconciliations.map((item) => (
                <li key={item.id} className="flex items-center justify-between gap-3">
                  <div>
                    <p className="font-medium text-gray-800">{item.reference}</p>
                    <p className="text-xs uppercase text-gray-500">
                      {item.provider} · {item.period_start ?? '—'} → {item.period_end ?? '—'}
                    </p>
                  </div>
                  <div className="text-right">
                    <StatusBadge status={item.status} />
                    <p className="mt-1 text-xs text-gray-500">Δ {formatMoney(item.difference)}</p>
                  </div>
                </li>
              ))}
            </ul>
          )}
        </Card>
      </div>

      <Card
        title="Recent payments"
        actions={
          <Button variant="secondary" onClick={onOpenPayments}>
            View all payments
          </Button>
        }
      >
        {data.recent_payments.length === 0 ? (
          <EmptyState title="No payments yet" description="Deposit and balance payments appear here as tenants pay." />
        ) : (
          <Table headers={['Payment', 'Tenant', 'Purpose', 'Amount', 'Method', 'Status', 'Date']}>
            {data.recent_payments.map((payment) => (
              <tr key={payment.id}>
                <td className="px-4 py-2">
                  <p className="font-medium text-gray-800">{payment.internal_reference}</p>
                  <p className="text-xs text-gray-500">{payment.job?.reference ?? '—'}</p>
                </td>
                <td className="px-4 py-2 text-gray-600">{payment.tenant?.name ?? '—'}</td>
                <td className="px-4 py-2 capitalize text-gray-600">{payment.purpose}</td>
                <td className="px-4 py-2 text-gray-700">{formatMoney(payment.amount, payment.currency)}</td>
                <td className="px-4 py-2 uppercase text-gray-600">{payment.method}</td>
                <td className="px-4 py-2">
                  <StatusBadge status={payment.status} />
                </td>
                <td className="px-4 py-2 text-gray-600">
                  {payment.created_at ? formatDateTime(payment.created_at) : '—'}
                </td>
              </tr>
            ))}
          </Table>
        )}
      </Card>

      <Card title="Trial balance · month to date">
        <TrialBalance trial={data.ledger} />
      </Card>
    </div>
  )
}

function PaymentsTab({
  canRecord,
  onRefund,
}: {
  canRecord: boolean
  onRefund: (payment: FinancePayment) => void
}) {
  const [status, setStatus] = useState('')
  const [method, setMethod] = useState('')
  const [purpose, setPurpose] = useState('')
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [notice, setNotice] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)
  const query = useFinancePayments({ status, method, purpose, search, page })
  const verify = useVerifyFinancePayment()

  function verifyPayment(payment: FinancePayment, decision: 'confirm' | 'fail') {
    setError(null)
    setNotice(null)

    if (decision === 'confirm') {
      const reference = window.prompt(`Provider reference for ${payment.internal_reference} (optional):`)
      if (reference === null) return
      verify.mutate(
        { paymentId: payment.id, decision, reference: reference.trim() || undefined },
        {
          onSuccess: () => setNotice(`${payment.internal_reference} confirmed and posted to the ledger.`),
          onError: (err) => setError(describe(err)),
        },
      )
      return
    }

    const reason = window.prompt('Why did this payment fail verification?')
    if (reason === null) return
    verify.mutate(
      { paymentId: payment.id, decision, reason: reason.trim() || undefined },
      {
        onSuccess: () => setNotice(`${payment.internal_reference} marked as failed.`),
        onError: (err) => setError(describe(err)),
      },
    )
  }

  return (
    <div className="space-y-4">
      {notice && <Alert kind="success">{notice}</Alert>}
      {error && <Alert kind="error">{error}</Alert>}

      <div className="flex flex-wrap items-end gap-3 rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
        <div>
          <label htmlFor="finance-payment-search" className="block text-xs font-medium text-gray-500">
            Search
          </label>
          <input
            id="finance-payment-search"
            value={search}
            onChange={(event) => {
              setSearch(event.target.value)
              setPage(1)
            }}
            placeholder="Reference, job or tenant"
            className="mt-1 rounded-lg border border-gray-300 px-3 py-2 text-sm"
          />
        </div>
        <div>
          <label htmlFor="finance-payment-status" className="block text-xs font-medium text-gray-500">
            Status
          </label>
          <select
            id="finance-payment-status"
            value={status}
            onChange={(event) => {
              setStatus(event.target.value)
              setPage(1)
            }}
            className="mt-1 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm"
          >
            <option value="">All statuses</option>
            <option value="pending">Pending</option>
            <option value="processing">Processing</option>
            <option value="succeeded">Succeeded</option>
            <option value="failed">Failed</option>
            <option value="cancelled">Cancelled</option>
          </select>
        </div>
        <div>
          <label htmlFor="finance-payment-method" className="block text-xs font-medium text-gray-500">
            Method
          </label>
          <select
            id="finance-payment-method"
            value={method}
            onChange={(event) => {
              setMethod(event.target.value)
              setPage(1)
            }}
            className="mt-1 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm"
          >
            <option value="">All methods</option>
            <option value="mpesa">M-Pesa</option>
            <option value="card">Card</option>
            <option value="bank">Bank</option>
            <option value="manual">Manual</option>
          </select>
        </div>
        <div>
          <label htmlFor="finance-payment-purpose" className="block text-xs font-medium text-gray-500">
            Purpose
          </label>
          <select
            id="finance-payment-purpose"
            value={purpose}
            onChange={(event) => {
              setPurpose(event.target.value)
              setPage(1)
            }}
            className="mt-1 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm"
          >
            <option value="">All purposes</option>
            <option value="deposit">Deposit</option>
            <option value="balance">Balance</option>
          </select>
        </div>
        <Button
          variant="secondary"
          onClick={() => {
            setSearch('')
            setStatus('')
            setMethod('')
            setPurpose('')
            setPage(1)
          }}
        >
          Reset
        </Button>
      </div>

      {query.isLoading && <PageLoader label="Loading payments…" />}
      {query.isError && <Alert kind="error">Payments could not be loaded.</Alert>}

      {query.data && (
        <>
          {query.data.payments.length === 0 ? (
            <EmptyState title="No payments in this view" description="Adjust the filters or wait for tenants to pay." />
          ) : (
            <Table
              headers={['Payment', 'Tenant / job', 'Purpose', 'Amount', 'Method', 'Status', 'Reference', 'Created', '']}
            >
              {query.data.payments.map((payment) => (
                <tr key={payment.id}>
                  <td className="px-4 py-3">
                    <p className="font-medium text-gray-900">{payment.internal_reference}</p>
                    <p className="text-xs text-gray-500">
                      {payment.sandbox ? 'Sandbox' : 'Live'} · {payment.provider}
                    </p>
                  </td>
                  <td className="px-4 py-3">
                    <p className="text-gray-700">{payment.tenant?.name ?? '—'}</p>
                    <p className="text-xs text-gray-500">{payment.job?.reference ?? '—'}</p>
                  </td>
                  <td className="px-4 py-3 capitalize text-gray-600">{payment.purpose}</td>
                  <td className="px-4 py-3 text-gray-700">{formatMoney(payment.amount, payment.currency)}</td>
                  <td className="px-4 py-3 uppercase text-gray-600">{payment.method}</td>
                  <td className="px-4 py-3">
                    <StatusBadge status={payment.status} />
                  </td>
                  <td className="px-4 py-3 text-gray-600">
                    {payment.provider_reference ?? payment.checkout_reference ?? '—'}
                  </td>
                  <td className="px-4 py-3 text-gray-600">
                    {payment.created_at ? formatDate(payment.created_at) : '—'}
                  </td>
                  <td className="px-4 py-3">
                    <div className="flex justify-end gap-2">
                      {canRecord && payment.can_verify && (
                        <>
                          <Button
                            loading={
                              verify.isPending &&
                              verify.variables?.paymentId === payment.id &&
                              verify.variables?.decision === 'confirm'
                            }
                            onClick={() => verifyPayment(payment, 'confirm')}
                          >
                            Confirm
                          </Button>
                          <Button variant="danger" onClick={() => verifyPayment(payment, 'fail')}>
                            Fail
                          </Button>
                        </>
                      )}
                      {canRecord && payment.status === 'succeeded' && (
                        <Button variant="secondary" onClick={() => onRefund(payment)}>
                          Refund
                        </Button>
                      )}
                    </div>
                  </td>
                </tr>
              ))}
            </Table>
          )}
          <Pager
            current={query.data.pagination.current_page}
            last={query.data.pagination.last_page}
            total={query.data.pagination.total}
            label="payments"
            onChange={setPage}
          />
        </>
      )}
    </div>
  )
}

function LedgerTab() {
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')
  const [tenantId, setTenantId] = useState('')
  const [jobId, setJobId] = useState('')
  const [page, setPage] = useState(1)
  const query = useFinanceLedger({ from, to, tenant_id: tenantId, job_id: jobId, page })

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-end gap-3 rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
        <Field
          label="From"
          type="date"
          value={from}
          onChange={(event) => {
            setFrom(event.target.value)
            setPage(1)
          }}
        />
        <Field
          label="To"
          type="date"
          value={to}
          onChange={(event) => {
            setTo(event.target.value)
            setPage(1)
          }}
        />
        <Field
          label="Tenant ID"
          value={tenantId}
          onChange={(event) => {
            setTenantId(event.target.value)
            setPage(1)
          }}
          placeholder="Tenant UUID"
          hint="Optional filter"
        />
        <Field
          label="Job ID"
          value={jobId}
          onChange={(event) => {
            setJobId(event.target.value)
            setPage(1)
          }}
          placeholder="Job UUID"
          hint="Optional filter"
        />
        <Button
          variant="secondary"
          onClick={() => {
            setFrom('')
            setTo('')
            setTenantId('')
            setJobId('')
            setPage(1)
          }}
        >
          Reset
        </Button>
      </div>

      {query.isLoading && <PageLoader label="Loading ledger…" />}
      {query.isError && <Alert kind="error">The ledger could not be loaded.</Alert>}

      {query.data && (
        <div className="space-y-6">
          {query.data.trialBalance && (
            <Card title="Trial balance for this view">
              <TrialBalance trial={query.data.trialBalance} />
            </Card>
          )}

          <Card title={`Journals (${query.data.pagination.total})`}>
            {query.data.journals.length === 0 ? (
              <EmptyState title="No journals in this range" description="Adjust the dates or filters." />
            ) : (
              <div className="space-y-4">
                {query.data.journals.map((journal) => (
                  <div key={journal.id} className="rounded-lg border border-gray-200">
                    <div className="flex flex-wrap items-start justify-between gap-3 border-b border-gray-100 bg-gray-50 px-4 py-3">
                      <div>
                        <p className="text-sm font-semibold text-gray-900">
                          {journal.reference}
                          {journal.reversal_of && (
                            <span className="ml-2 rounded bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800">
                              Reversal
                            </span>
                          )}
                        </p>
                        <p className="text-xs text-gray-500">{journal.description}</p>
                      </div>
                      <div className="text-right text-xs text-gray-500">
                        <p>{journal.transaction_date ?? '—'}</p>
                        <p>
                          {journal.tenant ?? 'Platform'} · {journal.job?.reference ?? '—'}
                        </p>
                        <p>{journal.actor ?? journal.source_type}</p>
                      </div>
                    </div>
                    <table className="min-w-full text-sm">
                      <thead>
                        <tr className="text-xs uppercase tracking-wide text-gray-500">
                          <th className="px-4 py-2 text-left">Account</th>
                          <th className="px-4 py-2 text-right">Debit</th>
                          <th className="px-4 py-2 text-right">Credit</th>
                          <th className="px-4 py-2 text-left">Memo</th>
                        </tr>
                      </thead>
                      <tbody>
                        {journal.lines.map((line, index) => (
                          <tr key={`${journal.id}-${index}`} className="border-t border-gray-100">
                            <td className="px-4 py-2 text-gray-700">
                              {line.account ?? '—'} · {line.account_name ?? ''}
                            </td>
                            <td className="px-4 py-2 text-right text-gray-700">
                              {line.debit ? formatMoney(line.debit, journal.currency) : '—'}
                            </td>
                            <td className="px-4 py-2 text-right text-gray-700">
                              {line.credit ? formatMoney(line.credit, journal.currency) : '—'}
                            </td>
                            <td className="px-4 py-2 text-gray-500">{line.memo ?? '—'}</td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                ))}
              </div>
            )}
            <div className="mt-4">
              <Pager
                current={query.data.pagination.current_page}
                last={query.data.pagination.last_page}
                total={query.data.pagination.total}
                label="journals"
                onChange={setPage}
              />
            </div>
          </Card>
        </div>
      )}
    </div>
  )
}

const EMPTY_LINES = ''

function parseStatement(text: string): { lines: ReconciliationInput['statement_lines']; error: string | null } {
  const rows = text
    .split('\n')
    .map((row) => row.trim())
    .filter(Boolean)

  if (rows.length === 0) {
    return { lines: [], error: 'Add at least one statement line.' }
  }

  const lines: ReconciliationInput['statement_lines'] = []
  for (const row of rows) {
    const [reference, rawAmount] = row.split(/[,;\t]/).map((part) => part.trim())
    const amount = Number(rawAmount)
    if (!reference || !Number.isFinite(amount)) {
      return { lines: [], error: `Could not read "${row}". Use reference,amount.` }
    }
    lines.push({ provider_reference: reference, amount })
  }

  return { lines, error: null }
}

function ReconciliationsTab({ canRecord }: { canRecord: boolean }) {
  const query = useFinanceReconciliations()
  const create = useCreateFinanceReconciliation()
  const [provider, setProvider] = useState<ReconciliationInput['provider']>('mpesa')
  const [periodStart, setPeriodStart] = useState('')
  const [periodEnd, setPeriodEnd] = useState('')
  const [notes, setNotes] = useState('')
  const [statement, setStatement] = useState(EMPTY_LINES)
  const [error, setError] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)
  const [result, setResult] = useState<FinanceReconciliation | null>(null)
  const [selectedId, setSelectedId] = useState<string | null>(null)
  const detail = useFinanceReconciliation(selectedId)

  function submit(event: FormEvent) {
    event.preventDefault()
    setError(null)
    setNotice(null)

    if (!periodStart || !periodEnd) {
      setError('Choose the statement period.')
      return
    }

    const parsed = parseStatement(statement)
    if (parsed.error) {
      setError(parsed.error)
      return
    }

    create.mutate(
      {
        provider,
        period_start: periodStart,
        period_end: periodEnd,
        notes: notes.trim() || undefined,
        statement_lines: parsed.lines,
      },
      {
        onSuccess: (reconciliation) => {
          setResult(reconciliation)
          setNotice(`${reconciliation.reference} recorded with ${reconciliation.exception_count} exception(s).`)
          setStatement(EMPTY_LINES)
          setNotes('')
        },
        onError: (err) => setError(describe(err)),
      },
    )
  }

  return (
    <div className="space-y-4">
      {notice && <Alert kind="success">{notice}</Alert>}
      {error && <Alert kind="error">{error}</Alert>}

      {canRecord && (
        <Card title="Reconcile a provider statement">
          <form className="space-y-3" onSubmit={submit}>
            <div className="grid gap-3 sm:grid-cols-3">
              <Select
                label="Provider"
                value={provider}
                onChange={(event) => setProvider(event.target.value as ReconciliationInput['provider'])}
              >
                <option value="mpesa">M-Pesa</option>
                <option value="card">Card</option>
                <option value="bank">Bank</option>
                <option value="manual">Manual</option>
              </Select>
              <Field
                label="Period start"
                type="date"
                value={periodStart}
                onChange={(event) => setPeriodStart(event.target.value)}
              />
              <Field
                label="Period end"
                type="date"
                value={periodEnd}
                onChange={(event) => setPeriodEnd(event.target.value)}
              />
            </div>
            <div className="space-y-1">
              <label htmlFor="statement-lines" className="block text-sm font-medium text-gray-700">
                Statement lines
              </label>
              <textarea
                id="statement-lines"
                rows={5}
                value={statement}
                onChange={(event) => setStatement(event.target.value)}
                placeholder={'MPESA-REF-001, 5000.00\nMPESA-REF-002, 12500.00'}
                className="w-full rounded-lg border border-gray-300 px-3 py-2 font-mono text-sm"
              />
              <p className="text-xs text-gray-500">One line per provider transaction: reference,amount.</p>
            </div>
            <Field label="Notes" value={notes} onChange={(event) => setNotes(event.target.value)} />
            <Button type="submit" loading={create.isPending}>
              Run reconciliation
            </Button>
          </form>
        </Card>
      )}

      {result && (
        <Card
          title={`${result.reference} · ${result.status}`}
          actions={<StatusBadge status={result.status} />}
        >
          <div className="space-y-3">
            <div className="grid gap-3 sm:grid-cols-4 text-sm">
              <div>
                <p className="text-xs uppercase text-gray-500">Statement</p>
                <p className="font-medium text-gray-900">{formatMoney(result.statement_total, result.currency)}</p>
              </div>
              <div>
                <p className="text-xs uppercase text-gray-500">Payments</p>
                <p className="font-medium text-gray-900">{formatMoney(result.payment_total, result.currency)}</p>
              </div>
              <div>
                <p className="text-xs uppercase text-gray-500">Ledger clearing</p>
                <p className="font-medium text-gray-900">{formatMoney(result.ledger_total, result.currency)}</p>
              </div>
              <div>
                <p className="text-xs uppercase text-gray-500">Difference</p>
                <p className="font-medium text-gray-900">{formatMoney(result.difference, result.currency)}</p>
              </div>
            </div>
            {result.items && result.items.length > 0 && (
              <Table headers={['Provider reference', 'Amount', 'Status', 'Note']}>
                {result.items.map((item) => (
                  <tr key={item.provider_reference}>
                    <td className="px-4 py-2 font-mono text-xs text-gray-700">{item.provider_reference}</td>
                    <td className="px-4 py-2 text-gray-700">{formatMoney(item.amount, result.currency)}</td>
                    <td className="px-4 py-2">
                      <StatusBadge status={item.status} />
                    </td>
                    <td className="px-4 py-2 text-gray-500">{item.note ?? '—'}</td>
                  </tr>
                ))}
              </Table>
            )}
          </div>
        </Card>
      )}

      {query.isLoading && <PageLoader label="Loading reconciliations…" />}
      {query.isError && <Alert kind="error">Reconciliations could not be loaded.</Alert>}

      {query.data && (
        <Card title="Reconciliation history">
          {query.data.reconciliations.length === 0 ? (
            <EmptyState title="No reconciliations yet" description="Match a provider statement to posted payments." />
          ) : (
            <div className="space-y-3">
              {query.data.reconciliations.map((reconciliation) => (
                <div key={reconciliation.id} className="rounded-lg border border-gray-200 p-4">
                  <div className="flex flex-wrap items-start justify-between gap-3">
                    <div>
                      <p className="font-medium text-gray-900">{reconciliation.reference}</p>
                      <p className="text-xs uppercase text-gray-500">
                        {reconciliation.provider} · {reconciliation.period_start ?? '—'} →{' '}
                        {reconciliation.period_end ?? '—'}
                      </p>
                    </div>
                    <div className="flex items-center gap-3">
                      <StatusBadge status={reconciliation.status} />
                      <Button
                        variant="secondary"
                        onClick={() => setSelectedId(selectedId === reconciliation.id ? null : reconciliation.id)}
                      >
                        {selectedId === reconciliation.id ? 'Hide details' : 'View details'}
                      </Button>
                    </div>
                  </div>
                  <div className="mt-3 grid gap-2 text-sm sm:grid-cols-4">
                    <p className="text-gray-500">
                      Statement <span className="block text-gray-900">{formatMoney(reconciliation.statement_total, reconciliation.currency)}</span>
                    </p>
                    <p className="text-gray-500">
                      Payments <span className="block text-gray-900">{formatMoney(reconciliation.payment_total, reconciliation.currency)}</span>
                    </p>
                    <p className="text-gray-500">
                      Ledger <span className="block text-gray-900">{formatMoney(reconciliation.ledger_total, reconciliation.currency)}</span>
                    </p>
                    <p className="text-gray-500">
                      Exceptions <span className="block text-gray-900">{reconciliation.exception_count}</span>
                    </p>
                  </div>

                  {selectedId === reconciliation.id && (
                    <div className="mt-4 border-t border-gray-100 pt-4">
                      {detail.isLoading && <PageLoader label="Loading reconciliation…" />}
                      {detail.isError && <Alert kind="error">Details could not be loaded.</Alert>}
                      {detail.data?.items && detail.data.items.length > 0 && (
                        <Table headers={['Provider reference', 'Amount', 'Status', 'Note']}>
                          {detail.data.items.map((item) => (
                            <tr key={item.provider_reference}>
                              <td className="px-4 py-2 font-mono text-xs text-gray-700">{item.provider_reference}</td>
                              <td className="px-4 py-2 text-gray-700">
                                {formatMoney(item.amount, reconciliation.currency)}
                              </td>
                              <td className="px-4 py-2">
                                <StatusBadge status={item.status} />
                              </td>
                              <td className="px-4 py-2 text-gray-500">{item.note ?? '—'}</td>
                            </tr>
                          ))}
                        </Table>
                      )}
                    </div>
                  )}
                </div>
              ))}
            </div>
          )}
        </Card>
      )}
    </div>
  )
}

function RefundsTab({
  canRecord,
  canApprove,
  prefill,
}: {
  canRecord: boolean
  canApprove: boolean
  prefill: { id: string; amount: number } | null
}) {
  const [status, setStatus] = useState('')
  const [page, setPage] = useState(1)
  const [paymentId, setPaymentId] = useState(prefill?.id ?? '')
  const [amount, setAmount] = useState(prefill ? prefill.amount.toFixed(2) : '')
  const [reason, setReason] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)
  const query = useFinanceRefunds({ status, page })
  const create = useCreateFinanceRefund()
  const decide = useDecideFinanceRefund()

  function submit(event: FormEvent) {
    event.preventDefault()
    setError(null)
    setNotice(null)

    const value = Number(amount)
    if (!paymentId.trim() || !Number.isFinite(value) || value <= 0 || reason.trim().length < 5) {
      setError('Provide the payment ID, a positive amount and a reason of at least 5 characters.')
      return
    }

    create.mutate(
      { payment_id: paymentId.trim(), amount: value, reason: reason.trim() },
      {
        onSuccess: (refund) => {
          setNotice(`Refund requested for ${formatMoney(refund.amount, refund.currency)}. Finance approval is required.`)
          setPaymentId('')
          setAmount('')
          setReason('')
        },
        onError: (err) => setError(describe(err)),
      },
    )
  }

  function decideRefund(refund: FinanceRefund, decision: 'approve' | 'process' | 'fail' | 'cancel') {
    setError(null)
    setNotice(null)

    if (decision === 'process') {
      const reference = window.prompt('Refund provider reference (optional):')
      if (reference === null) return
      decide.mutate(
        { refundId: refund.id, decision, reference: reference.trim() || undefined },
        {
          onSuccess: () => setNotice('Refund processed and posted to the ledger.'),
          onError: (err) => setError(describe(err)),
        },
      )
      return
    }

    if (decision === 'fail' || decision === 'cancel') {
      const reason = window.prompt(decision === 'fail' ? 'Why did the refund fail?' : 'Why is the refund being cancelled?')
      if (reason === null) return
      decide.mutate(
        { refundId: refund.id, decision, reason: reason.trim() || undefined },
        {
          onSuccess: () => setNotice(decision === 'fail' ? 'Refund marked as failed.' : 'Refund cancelled.'),
          onError: (err) => setError(describe(err)),
        },
      )
      return
    }

    decide.mutate(
      { refundId: refund.id, decision },
      {
        onSuccess: () => setNotice('Refund approved. It can now be processed.'),
        onError: (err) => setError(describe(err)),
      },
    )
  }

  return (
    <div className="space-y-4">
      {notice && <Alert kind="success">{notice}</Alert>}
      {error && <Alert kind="error">{error}</Alert>}

      {canRecord && (
        <Card title="Request a refund">
          <form className="space-y-3" onSubmit={submit}>
            <div className="grid gap-3 sm:grid-cols-2">
              <Field
                label="Payment ID"
                value={paymentId}
                onChange={(event) => setPaymentId(event.target.value)}
                placeholder="Settled payment UUID"
                hint="Copy it from the Payments tab."
              />
              <Field
                label="Amount"
                type="number"
                min={0}
                step="0.01"
                value={amount}
                onChange={(event) => setAmount(event.target.value)}
              />
            </div>
            <div className="space-y-1">
              <label htmlFor="refund-reason" className="block text-sm font-medium text-gray-700">
                Reason
              </label>
              <textarea
                id="refund-reason"
                required
                minLength={5}
                rows={2}
                value={reason}
                onChange={(event) => setReason(event.target.value)}
                className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"
              />
            </div>
            <Button type="submit" loading={create.isPending}>
              Request refund
            </Button>
            <p className="text-xs text-gray-500">
              Refunds post to the ledger only when processed. Approval is recorded separately for audit.
            </p>
          </form>
        </Card>
      )}

      <div className="flex flex-wrap items-end gap-3 rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
        <div>
          <label htmlFor="refund-status" className="block text-xs font-medium text-gray-500">
            Status
          </label>
          <select
            id="refund-status"
            value={status}
            onChange={(event) => {
              setStatus(event.target.value)
              setPage(1)
            }}
            className="mt-1 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm"
          >
            <option value="">All statuses</option>
            <option value="requested">Requested</option>
            <option value="approved">Approved</option>
            <option value="processing">Processing</option>
            <option value="completed">Completed</option>
            <option value="failed">Failed</option>
            <option value="cancelled">Cancelled</option>
          </select>
        </div>
      </div>

      {query.isLoading && <PageLoader label="Loading refunds…" />}
      {query.isError && <Alert kind="error">Refunds could not be loaded.</Alert>}

      {query.data && (
        <>
          {query.data.refunds.length === 0 ? (
            <EmptyState title="No refunds in this view" description="Refunds requested by finance appear here." />
          ) : (
            <Table headers={['Refund', 'Payment', 'Job', 'Amount', 'Status', 'Requested by', 'Created', '']}>
              {query.data.refunds.map((refund) => (
                <tr key={refund.id}>
                  <td className="px-4 py-3 text-gray-600">{refund.id.slice(0, 8)}…</td>
                  <td className="px-4 py-3">
                    <p className="text-gray-700">{refund.payment?.reference ?? '—'}</p>
                    <p className="text-xs uppercase text-gray-500">
                      {refund.payment?.purpose} · {refund.payment?.method}
                    </p>
                  </td>
                  <td className="px-4 py-3 text-gray-600">{refund.job?.reference ?? '—'}</td>
                  <td className="px-4 py-3 text-gray-700">{formatMoney(refund.amount, refund.currency)}</td>
                  <td className="px-4 py-3">
                    <StatusBadge status={refund.status} />
                    {refund.failure_reason && (
                      <p className="mt-1 text-xs text-gray-500">{refund.failure_reason}</p>
                    )}
                  </td>
                  <td className="px-4 py-3 text-gray-600">{refund.requested_by ?? '—'}</td>
                  <td className="px-4 py-3 text-gray-600">
                    {refund.created_at ? formatDate(refund.created_at) : '—'}
                  </td>
                  <td className="px-4 py-3">
                    {canApprove && (
                      <div className="flex justify-end gap-2">
                        {refund.status === 'requested' && (
                          <>
                            <Button onClick={() => decideRefund(refund, 'approve')}>Approve</Button>
                            <Button variant="secondary" onClick={() => decideRefund(refund, 'cancel')}>
                              Cancel
                            </Button>
                          </>
                        )}
                        {(refund.status === 'approved' || refund.status === 'processing') && (
                          <>
                            <Button onClick={() => decideRefund(refund, 'process')}>Process</Button>
                            <Button variant="danger" onClick={() => decideRefund(refund, 'fail')}>
                              Fail
                            </Button>
                            {refund.status === 'approved' && (
                              <Button variant="secondary" onClick={() => decideRefund(refund, 'cancel')}>
                                Cancel
                              </Button>
                            )}
                          </>
                        )}
                      </div>
                    )}
                  </td>
                </tr>
              ))}
            </Table>
          )}
          <Pager
            current={query.data.pagination.current_page}
            last={query.data.pagination.last_page}
            total={query.data.pagination.total}
            label="refunds"
            onChange={setPage}
          />
        </>
      )}
    </div>
  )
}

export function FinanceWorkspacePage() {
  const { data: user, isLoading } = useCurrentUser()
  const [tab, setTab] = useState<Tab>('overview')
  const [refundPrefill, setRefundPrefill] = useState<{ id: string; amount: number } | null>(null)

  if (isLoading) return <PageLoader label="Loading finance workspace…" />

  const permissions = new Set(user?.permissions ?? [])
  const canRecord = permissions.has('finance.payment.record')
  const canLedger = permissions.has('finance.ledger.view')
  const canApproveRefunds = permissions.has('finance.refund.approve')

  if (!canRecord && !canLedger) {
    return (
      <div className="mx-auto max-w-4xl space-y-4">
        <PageHeader title="Finance" description="Ledger, payments, reconciliation and refund controls." />
        <Alert kind="error">You do not have permission to view the finance workspace.</Alert>
      </div>
    )
  }

  const tabs = (Object.keys(TAB_LABELS) as Tab[]).filter((key) => {
    if (key === 'ledger') return canLedger
    if (key === 'reconciliations') return canLedger
    if (key === 'refunds') return canRecord || canApproveRefunds
    if (key === 'payouts') return permissions.has('finance.payout.prepare') || permissions.has('finance.payout.approve')
    if (key === 'overview' || key === 'payments') return canRecord || canLedger
    return false
  })

  return (
    <div className="mx-auto max-w-7xl space-y-6">
      <PageHeader
        title="Finance"
        description="Settled payments post to an immutable double-entry ledger. Sandbox callbacks are signed; browser redirects never settle a payment."
        actions={<Badge tone="amber">Sandbox mode</Badge>}
      />

      <div className="flex flex-wrap gap-1 rounded-xl border border-gray-200 bg-white p-1 shadow-sm" role="tablist">
        {tabs.map((key) => (
          <button
            key={key}
            type="button"
            role="tab"
            aria-selected={tab === key}
            onClick={() => setTab(key)}
            className={`rounded-lg px-4 py-2 text-sm font-medium transition-colors ${
              tab === key ? 'bg-brand-700 text-white' : 'text-gray-600 hover:bg-gray-100'
            }`}
          >
            {TAB_LABELS[key]}
          </button>
        ))}
      </div>

      {tab === 'overview' && <OverviewTab onOpenPayments={() => setTab('payments')} />}
      {tab === 'payments' && (
        <PaymentsTab
          canRecord={canRecord}
          onRefund={(payment) => {
            setRefundPrefill({ id: payment.id, amount: payment.amount })
            setTab('refunds')
          }}
        />
      )}
      {tab === 'ledger' && <LedgerTab />}
      {tab === 'reconciliations' && <ReconciliationsTab canRecord={canRecord} />}
      {tab === 'refunds' && (
        <RefundsTab
          key={refundPrefill?.id ?? 'none'}
          canRecord={canRecord}
          canApprove={canApproveRefunds}
          prefill={refundPrefill}
        />
      )}
      {tab === 'payouts' && (
        <Card title="Payout queue">
          <PayoutQueuePanel canApprove={permissions.has('finance.payout.approve')} />
        </Card>
      )}
    </div>
  )
}
