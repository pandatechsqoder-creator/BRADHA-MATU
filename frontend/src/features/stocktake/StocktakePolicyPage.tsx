import { Link } from '@tanstack/react-router'
import { useEffect, useState, type FormEvent } from 'react'
import { Alert, Button, Card, Field, PageHeader, PageLoader, Select } from '../../components/ui'
import { formatMoney } from '../../lib/formatting'
import { useStocktakePolicy, useUpdateStocktakePolicy } from '../../lib/stocktake/hooks'
import { describeError } from './shared'

interface PolicyForm {
  currency: string
  base_charge: string
  per_sku_charge: string
  per_location_charge: string
  per_branch_charge: string
  priority_low: string
  priority_normal: string
  priority_high: string
  priority_critical: string
  tax_rate: string
  deposit_type: string
  deposit_value: string
  deposit_minimum: string
  deposit_maximum: string
  deposit_due_days: string
  balance_due_days: string
  cancellation_fee_percent: string
}

export function StocktakePolicyPage() {
  const policy = useStocktakePolicy()
  const update = useUpdateStocktakePolicy()
  const [form, setForm] = useState<PolicyForm | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)

  useEffect(() => {
    if (form !== null || !policy.data) return
    const data = policy.data
    setForm({
      currency: data.currency,
      base_charge: String(data.base_charge),
      per_sku_charge: String(data.per_sku_charge),
      per_location_charge: String(data.per_location_charge),
      per_branch_charge: String(data.per_branch_charge),
      priority_low: String(data.priority_multipliers.low ?? 1),
      priority_normal: String(data.priority_multipliers.normal ?? 1),
      priority_high: String(data.priority_multipliers.high ?? 1),
      priority_critical: String(data.priority_multipliers.critical ?? 1),
      tax_rate: String(data.tax_rate),
      deposit_type: data.deposit_type,
      deposit_value: String(data.deposit_value),
      deposit_minimum: String(data.deposit_minimum),
      deposit_maximum: String(data.deposit_maximum),
      deposit_due_days: String(data.deposit_due_days),
      balance_due_days: String(data.balance_due_days),
      cancellation_fee_percent: String(data.cancellation_fee_percent),
    })
  }, [policy.data, form])

  if (policy.isError) return <Alert kind="error">The stocktake policy could not be loaded or you do not have permission to view it.</Alert>
  if (policy.isLoading || !form) return <PageLoader label="Loading stocktake policy…" />

  const set = (key: keyof PolicyForm, value: string) => setForm((current) => (current ? { ...current, [key]: value } : current))

  function submit(event: FormEvent) {
    event.preventDefault()
    setError(null)
    setNotice(null)
    if (!form) return
    update.mutate(
      {
        currency: form.currency.toUpperCase(),
        base_charge: Number(form.base_charge),
        per_sku_charge: Number(form.per_sku_charge),
        per_location_charge: Number(form.per_location_charge),
        per_branch_charge: Number(form.per_branch_charge),
        priority_multipliers: {
          low: Number(form.priority_low),
          normal: Number(form.priority_normal),
          high: Number(form.priority_high),
          critical: Number(form.priority_critical),
        },
        tax_rate: Number(form.tax_rate),
        deposit_type: form.deposit_type,
        deposit_value: Number(form.deposit_value),
        deposit_minimum: Number(form.deposit_minimum),
        deposit_maximum: Number(form.deposit_maximum),
        deposit_due_days: Number(form.deposit_due_days),
        balance_due_days: Number(form.balance_due_days),
        cancellation_fee_percent: Number(form.cancellation_fee_percent),
      },
      {
        onSuccess: () => setNotice('Stocktake policy updated. New estimates and quotes use these values.'),
        onError: (err) => setError(describeError(err)),
      },
    )
  }

  const sampleTotal =
    Number(form.base_charge) +
    Number(form.per_sku_charge) * 100 +
    Number(form.per_location_charge) * 2 +
    Number(form.per_branch_charge) * 1
  const sampleDeposit =
    form.deposit_type === 'fixed'
      ? Number(form.deposit_value)
      : form.deposit_type === 'none'
        ? 0
        : Math.min(
            Math.max(sampleTotal * (Number(form.deposit_value) / 100), Number(form.deposit_minimum)),
            Number(form.deposit_maximum) || Number.MAX_SAFE_INTEGER,
          )

  return (
    <div className="mx-auto max-w-5xl space-y-6">
      <div>
        <Link to="/platform/stocktaking-jobs" className="text-sm font-medium text-brand-700 hover:underline">
          ← Stock-taking jobs
        </Link>
      </div>
      <PageHeader
        title="Stocktake pricing & deposit policy"
        description="Platform-wide defaults for provisional estimates, quotes and deposit requirements. Tenants may override deposit rules in their own settings."
      />

      {error && <Alert kind="error">{error}</Alert>}
      {notice && <Alert kind="success">{notice}</Alert>}

      <form onSubmit={submit} className="space-y-4">
        <Card title="Estimate charges">
          <div className="grid gap-4 sm:grid-cols-3">
            <Field label="Currency" value={form.currency} onChange={(event) => set('currency', event.target.value)} />
            <Field label="Base charge" type="number" min={0} step="0.01" value={form.base_charge} onChange={(event) => set('base_charge', event.target.value)} />
            <Field label="Per SKU" type="number" min={0} step="0.01" value={form.per_sku_charge} onChange={(event) => set('per_sku_charge', event.target.value)} />
            <Field
              label="Per stock location"
              type="number"
              min={0}
              step="0.01"
              value={form.per_location_charge}
              onChange={(event) => set('per_location_charge', event.target.value)}
            />
            <Field label="Per branch" type="number" min={0} step="0.01" value={form.per_branch_charge} onChange={(event) => set('per_branch_charge', event.target.value)} />
            <Field label="Tax rate (%)" type="number" min={0} max={100} step="0.01" value={form.tax_rate} onChange={(event) => set('tax_rate', event.target.value)} />
          </div>
        </Card>

        <Card title="Priority multipliers">
          <div className="grid gap-4 sm:grid-cols-4">
            <Field label="Low" type="number" min={0} step="0.01" value={form.priority_low} onChange={(event) => set('priority_low', event.target.value)} />
            <Field label="Normal" type="number" min={0} step="0.01" value={form.priority_normal} onChange={(event) => set('priority_normal', event.target.value)} />
            <Field label="High" type="number" min={0} step="0.01" value={form.priority_high} onChange={(event) => set('priority_high', event.target.value)} />
            <Field label="Critical" type="number" min={0} step="0.01" value={form.priority_critical} onChange={(event) => set('priority_critical', event.target.value)} />
          </div>
        </Card>

        <Card title="Deposit policy">
          <div className="grid gap-4 sm:grid-cols-3">
            <Select label="Deposit type" value={form.deposit_type} onChange={(event) => set('deposit_type', event.target.value)}>
              <option value="percentage">Percentage of job value</option>
              <option value="fixed">Fixed amount</option>
              <option value="none">No deposit</option>
            </Select>
            <Field
              label={form.deposit_type === 'fixed' ? 'Fixed amount' : 'Percentage (%)'}
              type="number"
              min={0}
              step="0.01"
              value={form.deposit_value}
              onChange={(event) => set('deposit_value', event.target.value)}
            />
            <Field label="Minimum deposit" type="number" min={0} step="0.01" value={form.deposit_minimum} onChange={(event) => set('deposit_minimum', event.target.value)} />
            <Field label="Maximum deposit" type="number" min={0} step="0.01" value={form.deposit_maximum} onChange={(event) => set('deposit_maximum', event.target.value)} />
            <Field
              label="Deposit due (days)"
              type="number"
              min={0}
              max={60}
              value={form.deposit_due_days}
              onChange={(event) => set('deposit_due_days', event.target.value)}
            />
            <Field
              label="Balance due (days)"
              type="number"
              min={0}
              max={90}
              value={form.balance_due_days}
              onChange={(event) => set('balance_due_days', event.target.value)}
            />
            <Field
              label="Cancellation fee (%)"
              type="number"
              min={0}
              max={100}
              step="0.01"
              value={form.cancellation_fee_percent}
              onChange={(event) => set('cancellation_fee_percent', event.target.value)}
            />
          </div>
          <p className="mt-4 rounded-lg bg-gray-50 p-3 text-sm text-gray-600">
            Example: 100 SKUs across 2 locations in 1 branch has a provisional charge of{' '}
            <strong>{formatMoney(sampleTotal, form.currency || 'KES')}</strong> and a deposit of{' '}
            <strong>{formatMoney(sampleDeposit, form.currency || 'KES')}</strong>.
          </p>
        </Card>

        <Button type="submit" loading={update.isPending}>
          Save policy
        </Button>
      </form>
    </div>
  )
}
