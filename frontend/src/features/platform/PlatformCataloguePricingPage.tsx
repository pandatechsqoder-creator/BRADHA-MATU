import { useState, type FormEvent } from 'react'
import {
  Alert,
  Button,
  Card,
  EmptyState,
  Field,
  PageHeader,
  PageLoader,
  Select,
  Table,
} from '../../components/ui'
import { ApiError } from '../../lib/api/client'
import type { PricingPolicy } from '../../lib/api/schemas'
import { usePlatformPricing, useUpdatePricingPolicy } from '../../lib/auth/hooks'
import { formatDateTime, formatKes } from '../../lib/formatting'

function describeError(error: unknown): string {
  if (error instanceof ApiError) {
    const first = Object.values(error.details)[0]?.[0]
    return first ?? error.message
  }
  return 'The pricing policy could not be saved.'
}

function PolicyForm({ policy }: { policy: PricingPolicy }) {
  const update = useUpdatePricingPolicy()
  const [values, setValues] = useState({
    name: policy.name,
    pricing_model: policy.pricing_model,
    currency: policy.currency,
    product_count_basis: policy.product_count_basis,
    base_charge: String(policy.base_charge),
    included_products: String(policy.included_products),
    scale_products: String(policy.scale_products),
    max_charge: String(policy.max_charge),
    tax_rate: String(policy.tax_rate),
    notes: policy.notes ?? '',
  })
  const [error, setError] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)

  function set(key: keyof typeof values, value: string) {
    setValues((current) => ({ ...current, [key]: value }))
  }

  function submit(event: FormEvent) {
    event.preventDefault()
    setError(null)
    setNotice(null)
    update.mutate(
      {
        name: values.name,
        pricing_model: values.pricing_model,
        currency: values.currency.toUpperCase(),
        product_count_basis: values.product_count_basis,
        base_charge: Number(values.base_charge),
        included_products: Number(values.included_products),
        scale_products: Number(values.scale_products),
        max_charge: Number(values.max_charge),
        tax_rate: Number(values.tax_rate),
        notes: values.notes || null,
      },
      {
        onSuccess: () => setNotice('Pricing policy saved as a new version. Existing invoices are unchanged.'),
        onError: (err) => setError(describeError(err)),
      },
    )
  }

  return (
    <Card title="Catalogue pricing policy">
      <form onSubmit={submit} className="space-y-4">
        {error && <Alert kind="error">{error}</Alert>}
        {notice && <Alert kind="success">{notice}</Alert>}
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          <Field label="Policy name" name="policy_name" required value={values.name} onChange={(e) => set('name', e.target.value)} />
          <Select label="Pricing model" name="policy_model" value={values.pricing_model} onChange={(e) => set('pricing_model', e.target.value)}>
            <option value="banded">Banded</option>
            <option value="flat">Flat</option>
            <option value="per_product">Per product</option>
            <option value="minimum">Minimum</option>
            <option value="capped">Capped</option>
            <option value="promotional">Promotional</option>
            <option value="negotiated">Negotiated</option>
          </Select>
          <Field label="Currency" name="policy_currency" required maxLength={3} value={values.currency} onChange={(e) => set('currency', e.target.value)} />
          <Select label="Product count basis" name="policy_basis" value={values.product_count_basis} onChange={(e) => set('product_count_basis', e.target.value)}>
            <option value="active_variants">Active variants</option>
            <option value="active_products">Active products</option>
            <option value="sellable_skus">Sellable SKUs</option>
          </Select>
          <Field label="Base charge" name="policy_base_charge" type="number" min="0" step="any" value={values.base_charge} onChange={(e) => set('base_charge', e.target.value)} />
          <Field label="Included products" name="policy_included" type="number" min="0" value={values.included_products} onChange={(e) => set('included_products', e.target.value)} />
          <Field label="Scale products" name="policy_scale" type="number" min="1" value={values.scale_products} onChange={(e) => set('scale_products', e.target.value)} hint="Count at which the maximum charge applies" />
          <Field label="Maximum charge" name="policy_max_charge" type="number" min="0" step="any" value={values.max_charge} onChange={(e) => set('max_charge', e.target.value)} />
          <Field label="Tax rate (%)" name="policy_tax" type="number" min="0" max="100" step="any" value={values.tax_rate} onChange={(e) => set('tax_rate', e.target.value)} />
          <Field label="Notes" name="policy_notes" value={values.notes} onChange={(e) => set('notes', e.target.value)} />
        </div>
        <p className="text-xs text-gray-500">
          Saving creates a new pricing version. Invoices already issued keep the rules that were active when they were issued.
        </p>
        <Button type="submit" loading={update.isPending}>
          Save policy
        </Button>
      </form>
    </Card>
  )
}

export function PlatformCataloguePricingPage() {
  const pricing = usePlatformPricing()

  if (pricing.isLoading) return <PageLoader label="Loading pricing policy…" />
  if (pricing.isError) return <Alert kind="error">The pricing policy could not be loaded.</Alert>
  if (!pricing.data) return null

  const { policy, estimates } = pricing.data

  return (
    <div className="mx-auto max-w-6xl space-y-6">
      <PageHeader
        title="Catalogue pricing"
        description="Configure how catalogue declaration fees are calculated across the marketplace."
      />

      <PolicyForm key={`${policy.id}-${policy.versions.length}`} policy={policy} />

      <Card title="Sample estimates">
        <Table headers={['Products', 'Model', 'Subtotal', 'Tax', 'Total', 'Explanation']}>
          {estimates.map((estimate) => (
            <tr key={estimate.product_count} className="hover:bg-gray-50">
              <td className="px-4 py-2 text-gray-700">{estimate.product_count}</td>
              <td className="px-4 py-2 capitalize text-gray-600">{estimate.pricing_model}</td>
              <td className="px-4 py-2 text-gray-600">{formatKes(estimate.subtotal)}</td>
              <td className="px-4 py-2 text-gray-600">{formatKes(estimate.tax)}</td>
              <td className="px-4 py-2 font-medium text-gray-900">{formatKes(estimate.total)}</td>
              <td className="px-4 py-2 text-xs text-gray-500">{estimate.explanation}</td>
            </tr>
          ))}
        </Table>
      </Card>

      <Card title="Version history">
        {policy.versions.length === 0 ? (
          <EmptyState title="No versions yet" description="The first policy version is created with the policy." />
        ) : (
          <Table headers={['Version', 'Effective from', 'Base charge', 'Included', 'Scale', 'Maximum']}>
            {policy.versions.map((version) => {
              const rules = version.rules as Record<string, unknown>
              return (
                <tr key={version.id} className="hover:bg-gray-50">
                  <td className="px-4 py-2 font-medium text-gray-900">v{version.version}</td>
                  <td className="px-4 py-2 text-gray-600">
                    {version.effective_from ? formatDateTime(version.effective_from) : '—'}
                  </td>
                  <td className="px-4 py-2 text-gray-600">
                    {typeof rules.base_charge === 'number' ? formatKes(rules.base_charge) : '—'}
                  </td>
                  <td className="px-4 py-2 text-gray-600">{String(rules.included_products ?? '—')}</td>
                  <td className="px-4 py-2 text-gray-600">{String(rules.scale_products ?? '—')}</td>
                  <td className="px-4 py-2 text-gray-600">
                    {typeof rules.max_charge === 'number' ? formatKes(rules.max_charge) : '—'}
                  </td>
                </tr>
              )
            })}
          </Table>
        )}
      </Card>
    </div>
  )
}
