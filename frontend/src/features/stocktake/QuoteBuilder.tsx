import { useState, type FormEvent } from 'react'
import { Alert, Button, Card, Field } from '../../components/ui'
import type { PlatformStocktakeJob, WorkerCompensationLine } from '../../lib/api/stocktake'
import { formatMoney } from '../../lib/formatting'
import { useIssuePlatformQuote } from '../../lib/stocktake/hooks'
import { describeError, fromDateInput, toDateInput } from './shared'

function numberValue(value: string): number {
  const parsed = Number(value)
  return Number.isFinite(parsed) ? parsed : 0
}

export function QuoteBuilder({
  job,
  onIssued,
}: {
  job: PlatformStocktakeJob
  onIssued: (message: string) => void
}) {
  const issue = useIssuePlatformQuote(job.id)
  const existing = job.quote
  const provisional = job.provisional_estimate as { total?: number } | null | undefined

  const [base, setBase] = useState(String(existing?.base_service_charge ?? provisional?.total ?? 0))
  const [complexity, setComplexity] = useState(String(existing?.scope_complexity_charge ?? 0))
  const [travel, setTravel] = useState(String(existing?.travel_expenses ?? 0))
  const [other, setOther] = useState(String(existing?.other_expenses ?? 0))
  const [discount, setDiscount] = useState(String(existing?.discount ?? 0))
  const [taxRate, setTaxRate] = useState(String(existing?.tax_rate ?? 0))
  const [depositAmount, setDepositAmount] = useState(String(existing?.deposit_amount ?? job.deposit?.amount ?? 0))
  const [workerBudget, setWorkerBudget] = useState(String(existing?.worker_compensation_budget ?? 0))
  const [lines, setLines] = useState<WorkerCompensationLine[]>(existing?.worker_compensation_breakdown ?? [])
  const [internalNotes, setInternalNotes] = useState(existing?.internal_notes ?? '')
  const [notes, setNotes] = useState(existing?.notes ?? '')
  const [validUntil, setValidUntil] = useState(toDateInput(existing?.valid_until).slice(0, 10))
  const [error, setError] = useState<string | null>(null)

  const currency = job.currency
  const subtotal = Math.max(
    0,
    numberValue(base) + numberValue(complexity) + numberValue(travel) + numberValue(other) - numberValue(discount),
  )
  const tax = Math.round(subtotal * (numberValue(taxRate) / 100) * 100) / 100
  const total = Math.round((subtotal + tax) * 100) / 100
  const margin = Math.round((subtotal - numberValue(workerBudget)) * 100) / 100

  function submit(event: FormEvent) {
    event.preventDefault()
    setError(null)
    const charges = numberValue(base) + numberValue(complexity) + numberValue(travel) + numberValue(other)
    if (charges <= 0) {
      setError('Enter at least one tenant service charge.')
      return
    }
    issue.mutate(
      {
        base_service_charge: numberValue(base),
        scope_complexity_charge: numberValue(complexity),
        travel_expenses: numberValue(travel),
        other_expenses: numberValue(other),
        discount: numberValue(discount),
        tax_rate: numberValue(taxRate),
        deposit_amount: numberValue(depositAmount),
        worker_compensation_budget: numberValue(workerBudget),
        worker_compensation_breakdown: lines.filter((line) => line.label.trim() && line.amount > 0),
        internal_notes: internalNotes.trim() || null,
        notes: notes.trim() || null,
        valid_until: validUntil ? fromDateInput(`${validUntil}T23:59`) : null,
      },
      {
        onSuccess: (updated) => onIssued(`Quote ${updated.quote?.number ?? ''} issued to ${updated.tenant.name ?? 'the tenant'}.`),
        onError: (err) => setError(describeError(err)),
      },
    )
  }

  return (
    <Card
      title={existing ? 'Revise quote' : 'Build quote'}
      actions={<span className="text-xs text-gray-500">Tenant charge and worker compensation stay separate.</span>}
    >
      <form onSubmit={submit} className="space-y-5">
        {error && <Alert kind="error">{error}</Alert>}

        <div>
          <h3 className="mb-2 text-xs font-semibold uppercase tracking-wide text-gray-500">Tenant charge — shown on the quote</h3>
          <div className="grid gap-3 sm:grid-cols-2">
            <Field label="Base service charge" type="number" min={0} step="0.01" value={base} onChange={(event) => setBase(event.target.value)} />
            <Field label="Scope complexity" type="number" min={0} step="0.01" value={complexity} onChange={(event) => setComplexity(event.target.value)} />
            <Field label="Travel expenses" type="number" min={0} step="0.01" value={travel} onChange={(event) => setTravel(event.target.value)} />
            <Field label="Other expenses" type="number" min={0} step="0.01" value={other} onChange={(event) => setOther(event.target.value)} />
            <Field label="Discount" type="number" min={0} step="0.01" value={discount} onChange={(event) => setDiscount(event.target.value)} />
            <Field label="Tax rate (%)" type="number" min={0} max={100} step="0.01" value={taxRate} onChange={(event) => setTaxRate(event.target.value)} />
            <Field
              label="Deposit amount"
              type="number"
              min={0}
              step="0.01"
              value={depositAmount}
              onChange={(event) => setDepositAmount(event.target.value)}
              hint="Defaults to the deposit already required from the tenant."
            />
            <Field label="Quote valid until" type="date" value={validUntil} onChange={(event) => setValidUntil(event.target.value)} />
          </div>
        </div>

        <div className="rounded-lg border border-gray-200 bg-gray-50 p-4 text-sm">
          <div className="flex justify-between text-gray-600">
            <span>Subtotal</span>
            <span>{formatMoney(subtotal, currency)}</span>
          </div>
          <div className="flex justify-between text-gray-600">
            <span>Tax</span>
            <span>{formatMoney(tax, currency)}</span>
          </div>
          <div className="mt-1 flex justify-between border-t border-gray-200 pt-2 text-base font-semibold text-gray-900">
            <span>Tenant pays</span>
            <span>{formatMoney(total, currency)}</span>
          </div>
        </div>

        <div className="rounded-lg border border-amber-200 bg-amber-50 p-4">
          <h3 className="text-xs font-semibold uppercase tracking-wide text-amber-800">
            Internal — never shown to the tenant or stock takers
          </h3>
          <div className="mt-3 grid gap-3 sm:grid-cols-2">
            <Field
              label="Worker compensation budget"
              type="number"
              min={0}
              step="0.01"
              value={workerBudget}
              onChange={(event) => setWorkerBudget(event.target.value)}
            />
            <div className="rounded-lg bg-white p-3 text-sm shadow-sm">
              <p className="text-gray-500">Platform margin</p>
              <p className={`text-lg font-semibold ${margin < 0 ? 'text-red-600' : 'text-gray-900'}`}>{formatMoney(margin, currency)}</p>
              <p className="text-xs text-gray-500">Tenant subtotal minus worker compensation.</p>
            </div>
          </div>

          <div className="mt-3 space-y-2">
            <p className="text-sm font-medium text-amber-900">Compensation breakdown</p>
            {lines.map((line, index) => (
              <div key={index} className="flex items-center gap-2">
                <input
                  aria-label={`Breakdown label ${index + 1}`}
                  value={line.label}
                  onChange={(event) =>
                    setLines((current) => current.map((item, i) => (i === index ? { ...item, label: event.target.value } : item)))
                  }
                  placeholder="e.g. Three stock takers"
                  className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"
                />
                <input
                  aria-label={`Breakdown amount ${index + 1}`}
                  type="number"
                  min={0}
                  step="0.01"
                  value={line.amount}
                  onChange={(event) =>
                    setLines((current) =>
                      current.map((item, i) => (i === index ? { ...item, amount: numberValue(event.target.value) } : item)),
                    )
                  }
                  className="w-32 rounded-lg border border-gray-300 px-3 py-2 text-sm"
                />
                <Button
                  type="button"
                  variant="secondary"
                  onClick={() => setLines((current) => current.filter((_, i) => i !== index))}
                >
                  Remove
                </Button>
              </div>
            ))}
            <Button type="button" variant="secondary" onClick={() => setLines((current) => [...current, { label: '', amount: 0 }])}>
              Add line
            </Button>
          </div>

          <label className="mt-3 block text-sm font-medium text-amber-900">Internal notes</label>
          <textarea
            rows={2}
            value={internalNotes}
            onChange={(event) => setInternalNotes(event.target.value)}
            className="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"
          />
        </div>

        <div>
          <label className="block text-sm font-medium text-gray-700">Notes to the tenant</label>
          <textarea
            rows={2}
            value={notes}
            onChange={(event) => setNotes(event.target.value)}
            className="mt-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"
          />
        </div>

        <div className="flex items-center gap-3">
          <Button type="submit" loading={issue.isPending}>
            {existing ? 'Issue revised quote' : 'Issue quote'}
          </Button>
          <p className="text-xs text-gray-500">Issuing sends the quote to the tenant for acceptance.</p>
        </div>
      </form>
    </Card>
  )
}
