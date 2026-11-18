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
} from '../../components/ui'
import { ApiError } from '../../lib/api/client'
import type { Product } from '../../lib/api/schemas'
import {
  useAddProductBarcode,
  useCurrentUser,
  useDiscontinueProduct,
  useProduct,
  useRemoveProductBarcode,
  useUpdateProduct,
} from '../../lib/auth/hooks'
import { formatDateTime, formatKes } from '../../lib/formatting'

function describeError(error: unknown): string {
  if (error instanceof ApiError) {
    const first = Object.values(error.details)[0]?.[0]
    return first ?? error.message
  }
  return 'The change could not be saved. Try again.'
}

function EditProductForm({ tenantId, product }: { tenantId: string; product: Product }) {
  const update = useUpdateProduct(tenantId)
  const [values, setValues] = useState({
    name: product.name,
    sku: product.sku ?? '',
    variant_name: product.variant_name ?? '',
    brand: product.brand ?? '',
    category: product.category?.name ?? '',
    description: product.description ?? '',
    base_unit: product.base_unit ?? '',
    packaging_unit: product.packaging_unit ?? '',
    packaging_factor: product.packaging_factor ? String(product.packaging_factor) : '',
    tracking_mode: product.tracking_mode,
    status: product.status,
    supplier_ref: product.supplier_ref ?? '',
    cost_price: product.cost_price !== undefined && product.cost_price !== null ? String(product.cost_price) : '',
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
        productId: product.id,
        input: {
          name: values.name,
          sku: values.sku,
          variant_name: values.variant_name || null,
          brand: values.brand || null,
          category: values.category || null,
          description: values.description || null,
          base_unit: values.base_unit || null,
          packaging_unit: values.packaging_unit || null,
          packaging_factor: values.packaging_factor ? Number(values.packaging_factor) : null,
          tracking_mode: values.tracking_mode,
          status: values.status,
          supplier_ref: values.supplier_ref || null,
          cost_price: values.cost_price ? Number(values.cost_price) : null,
        },
      },
      {
        onSuccess: () => setNotice('Product updated. A new catalogue version was published if anything changed.'),
        onError: (err) => setError(describeError(err)),
      },
    )
  }

  return (
    <Card title="Edit product">
      <form onSubmit={submit} className="space-y-4">
        {error && <Alert kind="error">{error}</Alert>}
        {notice && <Alert kind="success">{notice}</Alert>}
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          <Field label="Product name" name="edit_name" required value={values.name} onChange={(e) => set('name', e.target.value)} />
          <Field label="SKU" name="edit_sku" required value={values.sku} onChange={(e) => set('sku', e.target.value)} />
          <Field label="Variant name" name="edit_variant" value={values.variant_name} onChange={(e) => set('variant_name', e.target.value)} />
          <Field label="Brand" name="edit_brand" value={values.brand} onChange={(e) => set('brand', e.target.value)} />
          <Field label="Category" name="edit_category" value={values.category} onChange={(e) => set('category', e.target.value)} />
          <Field label="Supplier reference" name="edit_supplier_ref" value={values.supplier_ref} onChange={(e) => set('supplier_ref', e.target.value)} />
          <Field label="Base unit" name="edit_base_unit" value={values.base_unit} onChange={(e) => set('base_unit', e.target.value)} />
          <Field label="Packaging unit" name="edit_packaging_unit" value={values.packaging_unit} onChange={(e) => set('packaging_unit', e.target.value)} />
          <Field label="Base units per pack" name="edit_packaging_factor" type="number" min="0" step="any" value={values.packaging_factor} onChange={(e) => set('packaging_factor', e.target.value)} />
          <Select label="Tracking mode" name="edit_tracking_mode" value={values.tracking_mode} onChange={(e) => set('tracking_mode', e.target.value)}>
            <option value="quantity">Quantity</option>
            <option value="batch">Batch</option>
            <option value="serial">Serial</option>
            <option value="expiry">Expiry</option>
            <option value="combination">Combination</option>
          </Select>
          <Select label="Status" name="edit_status" value={values.status} onChange={(e) => set('status', e.target.value)}>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
            <option value="discontinued">Discontinued</option>
          </Select>
          <Field label="Cost price (KSh)" name="edit_cost_price" type="number" min="0" step="any" value={values.cost_price} onChange={(e) => set('cost_price', e.target.value)} hint="Visible to catalogue managers only" />
        </div>
        <div className="space-y-1">
          <label htmlFor="edit_description" className="block text-sm font-medium text-gray-700">
            Description
          </label>
          <textarea
            id="edit_description"
            name="edit_description"
            rows={3}
            className="block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:outline-2 focus:outline-brand-600"
            value={values.description}
            onChange={(event) => set('description', event.target.value)}
          />
        </div>
        <Button type="submit" loading={update.isPending}>
          Save changes
        </Button>
      </form>
    </Card>
  )
}

function BarcodesCard({
  tenantId,
  product,
  canUpdate,
}: {
  tenantId: string
  product: Product
  canUpdate: boolean
}) {
  const addBarcode = useAddProductBarcode(tenantId)
  const removeBarcode = useRemoveProductBarcode(tenantId)
  const [barcode, setBarcode] = useState('')
  const [type, setType] = useState('internal')
  const [error, setError] = useState<string | null>(null)

  return (
    <Card title="Barcodes">
      <div className="space-y-4">
        {error && <Alert kind="error">{error}</Alert>}
        {product.barcodes.length === 0 ? (
          <EmptyState title="No barcodes" description="Add a barcode so counters can scan this product." />
        ) : (
          <ul className="space-y-2">
            {product.barcodes.map((item) => (
              <li key={item.id} className="flex items-center justify-between rounded-lg border border-gray-200 px-3 py-2">
                <div>
                  <p className="font-mono text-sm text-gray-800">{item.barcode}</p>
                  <p className="text-xs uppercase text-gray-500">
                    {item.type}
                    {item.is_primary ? ' · primary' : ''}
                  </p>
                </div>
                {canUpdate && (
                  <Button
                    variant="secondary"
                    loading={removeBarcode.isPending && removeBarcode.variables?.barcodeId === item.id}
                    onClick={() => {
                      if (!window.confirm(`Remove barcode ${item.barcode}?`)) return
                      setError(null)
                      removeBarcode.mutate(
                        { productId: product.id, barcodeId: item.id },
                        { onError: (err) => setError(describeError(err)) },
                      )
                    }}
                  >
                    Remove
                  </Button>
                )}
              </li>
            ))}
          </ul>
        )}

        {canUpdate && (
          <form
            className="flex flex-wrap items-end gap-3"
            onSubmit={(event) => {
              event.preventDefault()
              setError(null)
              addBarcode.mutate(
                { productId: product.id, barcode, type },
                {
                  onSuccess: () => setBarcode(''),
                  onError: (err) => setError(describeError(err)),
                },
              )
            }}
          >
            <Field label="New barcode" name="new_barcode" required value={barcode} onChange={(event) => setBarcode(event.target.value)} />
            <Select label="Type" name="new_barcode_type" value={type} onChange={(event) => setType(event.target.value)}>
              <option value="internal">Internal</option>
              <option value="ean13">EAN-13</option>
              <option value="upc">UPC</option>
              <option value="code128">Code 128</option>
            </Select>
            <Button type="submit" variant="secondary" loading={addBarcode.isPending}>
              Add barcode
            </Button>
          </form>
        )}
      </div>
    </Card>
  )
}

export function ProductDetailPage() {
  const { productId } = useParams({ from: '/business/catalogue/products/$productId' })
  const { data: user, isLoading } = useCurrentUser()
  const tenantId = user?.current_tenant?.id
  const product = useProduct(tenantId, productId)
  const discontinue = useDiscontinueProduct(tenantId)
  const [error, setError] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)

  if (isLoading) return <PageLoader />
  if (!tenantId) return <Alert kind="error">Select a business first.</Alert>
  if (product.isLoading) return <PageLoader label="Loading product…" />
  if (product.isError) return <Alert kind="error">This product could not be loaded.</Alert>
  if (!product.data) return null

  const item = product.data
  const permissions = user?.permissions ?? []
  const canUpdate = permissions.includes('catalogue.update')

  return (
    <div className="mx-auto max-w-5xl space-y-6">
      <PageHeader
        title={item.name}
        description={[item.brand, item.category?.name, item.sku].filter(Boolean).join(' · ')}
        actions={
          <div className="flex gap-2">
            <Link
              to="/business/catalogue"
              className="inline-flex items-center rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50"
            >
              Back to catalogue
            </Link>
            {canUpdate && item.status !== 'discontinued' && (
              <Button
                variant="danger"
                loading={discontinue.isPending}
                onClick={() => {
                  if (!window.confirm(`Mark ${item.name} as discontinued?`)) return
                  setError(null)
                  discontinue.mutate(item.id, {
                    onSuccess: () => setNotice('Product marked discontinued.'),
                    onError: (err) => setError(describeError(err)),
                  })
                }}
              >
                Discontinue
              </Button>
            )}
          </div>
        }
      />

      {error && <Alert kind="error">{error}</Alert>}
      {notice && <Alert kind="success">{notice}</Alert>}

      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <Card title="Status">
          <Badge tone={item.status === 'active' ? 'green' : item.status === 'discontinued' ? 'red' : 'gray'}>
            {item.status}
          </Badge>
          <p className="mt-2 text-xs text-gray-500">Tracking: {item.tracking_mode}</p>
        </Card>
        <Card title="Packaging">
          <p className="text-sm text-gray-700">
            {item.packaging_unit
              ? `1 ${item.packaging_unit} = ${item.packaging_factor ?? '?'} ${item.base_unit ?? ''}`
              : `Base unit: ${item.base_unit ?? '—'}`}
          </p>
        </Card>
        <Card title="Variants">
          <p className="text-2xl font-semibold text-gray-900">{item.variants_count}</p>
        </Card>
        <Card title="Cost price">
          <p className="text-sm text-gray-700">
            {item.cost_price !== undefined && item.cost_price !== null ? formatKes(item.cost_price) : 'Hidden or not set'}
          </p>
          {item.updated_at && <p className="mt-1 text-xs text-gray-500">Updated {formatDateTime(item.updated_at)}</p>}
        </Card>
      </div>

      <Card title="Variants">
        <div className="space-y-3">
          {item.variants.map((variant) => (
            <div key={variant.id} className="rounded-lg border border-gray-200 px-4 py-3">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <div>
                  <p className="font-medium text-gray-900">{variant.name ?? item.name}</p>
                  <p className="font-mono text-xs text-gray-500">{variant.sku}</p>
                </div>
                <Badge tone={variant.status === 'active' ? 'green' : 'gray'}>{variant.status}</Badge>
              </div>
              <p className="mt-1 text-xs text-gray-500">
                {variant.base_unit ?? '—'}
                {variant.packaging_unit
                  ? ` · 1 ${variant.packaging_unit} = ${variant.packaging_factor ?? '?'} ${variant.base_unit ?? ''}`
                  : ''}
              </p>
            </div>
          ))}
        </div>
      </Card>

      <BarcodesCard tenantId={tenantId} product={item} canUpdate={canUpdate} />
      {canUpdate && <EditProductForm key={item.id} tenantId={tenantId} product={item} />}
    </div>
  )
}
