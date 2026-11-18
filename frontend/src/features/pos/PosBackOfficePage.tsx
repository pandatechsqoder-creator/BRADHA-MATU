import { useState } from 'react'
import {
  Alert,
  Badge,
  Button,
  Card,
  Field,
  PageHeader,
  Select,
  Table,
} from '../../components/ui'
import { ApiError } from '../../lib/api/client'
import { useCurrentUser } from '../../lib/auth/hooks'
import { useBranches, useProducts } from '../../lib/auth/hooks'
import { formatDateTime, formatMoney } from '../../lib/formatting'
import {
  useCreatePosCustomer,
  useCreatePosPurchase,
  useCreatePosReturn,
  useCreatePosSupplier,
  useCreatePosTransfer,
  usePosCustomers,
  usePosPurchases,
  usePosReturns,
  usePosSales,
  usePosSuppliers,
  usePosTransfers,
  useReceivePosTransfer,
} from '../../lib/pos/hooks'

type Tab = 'customers' | 'suppliers' | 'purchases' | 'returns' | 'transfers'

const TABS: { id: Tab; label: string }[] = [
  { id: 'customers', label: 'Customers' },
  { id: 'suppliers', label: 'Suppliers' },
  { id: 'purchases', label: 'Purchases' },
  { id: 'returns', label: 'Returns' },
  { id: 'transfers', label: 'Transfers' },
]

export function PosBackOfficePage() {
  const { data: user } = useCurrentUser()
  const tenantId = user?.current_tenant?.id
  const [tab, setTab] = useState<Tab>('customers')

  return (
    <div className="space-y-6">
      <PageHeader
        title="POS back office"
        description="Customers, suppliers, purchases, returns and branch transfers, all linked to inventory movements."
      />

      <div className="flex flex-wrap gap-2 border-b border-gray-200 pb-2" role="tablist">
        {TABS.map((item) => (
          <button
            key={item.id}
            type="button"
            role="tab"
            aria-selected={tab === item.id}
            onClick={() => setTab(item.id)}
            className={`rounded-lg px-3 py-1.5 text-sm font-medium transition-colors ${
              tab === item.id
                ? 'bg-brand-700 text-white'
                : 'bg-gray-100 text-gray-700 hover:bg-gray-200'
            }`}
          >
            {item.label}
          </button>
        ))}
      </div>

      {tab === 'customers' && <CustomersPanel tenantId={tenantId} />}
      {tab === 'suppliers' && <SuppliersPanel tenantId={tenantId} />}
      {tab === 'purchases' && <PurchasesPanel tenantId={tenantId} />}
      {tab === 'returns' && <ReturnsPanel tenantId={tenantId} />}
      {tab === 'transfers' && <TransfersPanel tenantId={tenantId} />}
    </div>
  )
}

function errorText(error: unknown, fallback: string): string {
  return error instanceof ApiError ? error.message : fallback
}

function CustomersPanel({ tenantId }: { tenantId: string | undefined }) {
  const customers = usePosCustomers(tenantId)
  const createCustomer = useCreatePosCustomer(tenantId)
  const [name, setName] = useState('')
  const [phone, setPhone] = useState('')
  const [email, setEmail] = useState('')
  const [error, setError] = useState<string | null>(null)

  function submit() {
    setError(null)
    createCustomer.mutate(
      { name: name.trim(), phone: phone.trim() || undefined, email: email.trim() || undefined },
      {
        onSuccess: () => {
          setName('')
          setPhone('')
          setEmail('')
        },
        onError: (err) => setError(errorText(err, 'Could not create the customer.')),
      },
    )
  }

  return (
    <div className="grid grid-cols-1 gap-6 lg:grid-cols-12">
      <div className="lg:col-span-4">
        <Card title="New customer">
          <div className="space-y-4">
            {error && <Alert kind="error">{error}</Alert>}
            <Field label="Name" value={name} onChange={(event) => setName(event.target.value)} />
            <Field label="Phone" value={phone} onChange={(event) => setPhone(event.target.value)} />
            <Field
              label="Email"
              type="email"
              value={email}
              onChange={(event) => setEmail(event.target.value)}
            />
            <Button
              onClick={submit}
              loading={createCustomer.isPending}
              disabled={name.trim() === ''}
            >
              Add customer
            </Button>
          </div>
        </Card>
      </div>
      <div className="lg:col-span-8">
        <Card title={`Customers (${customers.data?.length ?? 0})`}>
          <Table headers={['Name', 'Phone', 'Email', 'Loyalty', 'Status']}>
            {(customers.data ?? []).map((customer) => (
              <tr key={customer.id} className="hover:bg-gray-50">
                <td className="px-4 py-3 font-medium">{customer.name}</td>
                <td className="px-4 py-3 text-gray-600">{customer.phone ?? '—'}</td>
                <td className="px-4 py-3 text-gray-600">{customer.email ?? '—'}</td>
                <td className="px-4 py-3 text-gray-600">{customer.loyalty_id ?? '—'}</td>
                <td className="px-4 py-3">
                  <Badge tone={customer.active ? 'green' : 'gray'}>
                    {customer.active ? 'active' : 'inactive'}
                  </Badge>
                </td>
              </tr>
            ))}
          </Table>
        </Card>
      </div>
    </div>
  )
}

function SuppliersPanel({ tenantId }: { tenantId: string | undefined }) {
  const suppliers = usePosSuppliers(tenantId)
  const createSupplier = useCreatePosSupplier(tenantId)
  const [name, setName] = useState('')
  const [phone, setPhone] = useState('')
  const [email, setEmail] = useState('')
  const [error, setError] = useState<string | null>(null)

  function submit() {
    setError(null)
    createSupplier.mutate(
      { name: name.trim(), phone: phone.trim() || undefined, email: email.trim() || undefined },
      {
        onSuccess: () => {
          setName('')
          setPhone('')
          setEmail('')
        },
        onError: (err) => setError(errorText(err, 'Could not create the supplier.')),
      },
    )
  }

  return (
    <div className="grid grid-cols-1 gap-6 lg:grid-cols-12">
      <div className="lg:col-span-4">
        <Card title="New supplier">
          <div className="space-y-4">
            {error && <Alert kind="error">{error}</Alert>}
            <Field label="Name" value={name} onChange={(event) => setName(event.target.value)} />
            <Field label="Phone" value={phone} onChange={(event) => setPhone(event.target.value)} />
            <Field
              label="Email"
              type="email"
              value={email}
              onChange={(event) => setEmail(event.target.value)}
            />
            <Button
              onClick={submit}
              loading={createSupplier.isPending}
              disabled={name.trim() === ''}
            >
              Add supplier
            </Button>
          </div>
        </Card>
      </div>
      <div className="lg:col-span-8">
        <Card title={`Suppliers (${suppliers.data?.length ?? 0})`}>
          <Table headers={['Name', 'Contact', 'Phone', 'Email', 'Status']}>
            {(suppliers.data ?? []).map((supplier) => (
              <tr key={supplier.id} className="hover:bg-gray-50">
                <td className="px-4 py-3 font-medium">{supplier.name}</td>
                <td className="px-4 py-3 text-gray-600">{supplier.contact_person ?? '—'}</td>
                <td className="px-4 py-3 text-gray-600">{supplier.phone ?? '—'}</td>
                <td className="px-4 py-3 text-gray-600">{supplier.email ?? '—'}</td>
                <td className="px-4 py-3">
                  <Badge tone={supplier.active ? 'green' : 'gray'}>
                    {supplier.active ? 'active' : 'inactive'}
                  </Badge>
                </td>
              </tr>
            ))}
          </Table>
        </Card>
      </div>
    </div>
  )
}

function VariantSelect({
  tenantId,
  value,
  onChange,
}: {
  tenantId: string | undefined
  value: string
  onChange: (value: string) => void
}) {
  const products = useProducts(tenantId, { status: 'active', include: 'variants' })
  const variants = (products.data?.products ?? []).flatMap((product) =>
    product.variants.map((variant) => ({
      id: variant.id,
      label: product.variants.length > 1 ? `${product.name} — ${variant.name ?? variant.sku}` : product.name,
    })),
  )

  return (
    <Select label="Product" value={value} onChange={(event) => onChange(event.target.value)}>
      <option value="">Select a product…</option>
      {variants.map((variant) => (
        <option key={variant.id} value={variant.id}>
          {variant.label}
        </option>
      ))}
    </Select>
  )
}

function PurchasesPanel({ tenantId }: { tenantId: string | undefined }) {
  const purchases = usePosPurchases(tenantId)
  const suppliers = usePosSuppliers(tenantId)
  const branches = useBranches(tenantId)
  const products = useProducts(tenantId, { status: 'active', include: 'variants' })
  const createPurchase = useCreatePosPurchase(tenantId)

  const [supplierId, setSupplierId] = useState('')
  const [branchId, setBranchId] = useState('')
  const [variantId, setVariantId] = useState('')
  const [quantity, setQuantity] = useState('1')
  const [unitCost, setUnitCost] = useState('0')
  const [error, setError] = useState<string | null>(null)

  function submit() {
    const variant = variantLabel(products.data?.products, variantId)
    setError(null)
    createPurchase.mutate(
      {
        supplier_id: supplierId,
        branch_id: branchId || undefined,
        status: 'received',
        items: [
          {
            variant_id: variantId,
            product_name: variant,
            quantity: Number(quantity),
            unit_cost: Number(unitCost),
          },
        ],
      },
      {
        onSuccess: () => {
          setVariantId('')
          setQuantity('1')
          setUnitCost('0')
        },
        onError: (err) => setError(errorText(err, 'Could not record the purchase.')),
      },
    )
  }

  return (
    <div className="grid grid-cols-1 gap-6 lg:grid-cols-12">
      <div className="lg:col-span-4">
        <Card title="Record purchase">
          <div className="space-y-4">
            {error && <Alert kind="error">{error}</Alert>}
            <Select
              label="Supplier"
              value={supplierId}
              onChange={(event) => setSupplierId(event.target.value)}
            >
              <option value="">Select a supplier…</option>
              {(suppliers.data ?? []).map((supplier) => (
                <option key={supplier.id} value={supplier.id}>
                  {supplier.name}
                </option>
              ))}
            </Select>
            <Select
              label="Branch"
              value={branchId}
              onChange={(event) => setBranchId(event.target.value)}
            >
              <option value="">Default location</option>
              {(branches.data ?? []).map((branch) => (
                <option key={branch.id} value={branch.id}>
                  {branch.name}
                </option>
              ))}
            </Select>
            <VariantSelect tenantId={tenantId} value={variantId} onChange={setVariantId} />
            <Field
              label="Quantity"
              type="number"
              min="0.001"
              step="0.001"
              value={quantity}
              onChange={(event) => setQuantity(event.target.value)}
            />
            <Field
              label="Unit cost"
              type="number"
              min="0"
              step="0.01"
              value={unitCost}
              onChange={(event) => setUnitCost(event.target.value)}
            />
            <Button
              onClick={submit}
              loading={createPurchase.isPending}
              disabled={supplierId === '' || variantId === ''}
            >
              Receive purchase
            </Button>
          </div>
        </Card>
      </div>
      <div className="lg:col-span-8">
        <Card title={`Purchases (${purchases.data?.length ?? 0})`}>
          <Table headers={['Reference', 'Supplier', 'Status', 'Total', 'Created']}>
            {(purchases.data ?? []).map((purchase) => (
              <tr key={purchase.id} className="hover:bg-gray-50">
                <td className="px-4 py-3 font-medium">{purchase.reference}</td>
                <td className="px-4 py-3 text-gray-600">{purchase.supplier ?? '—'}</td>
                <td className="px-4 py-3">
                  <Badge tone={purchase.status === 'received' ? 'green' : 'gray'}>
                    {purchase.status}
                  </Badge>
                </td>
                <td className="px-4 py-3">{formatMoney(purchase.total_amount, purchase.currency)}</td>
                <td className="px-4 py-3 text-gray-500">{formatDateTime(purchase.created_at ?? '')}</td>
              </tr>
            ))}
          </Table>
        </Card>
      </div>
    </div>
  )
}

function variantLabel(
  products:
    | Array<{
        name: string
        variants: Array<{ id: string; name?: string | null; sku?: string | null }>
      }>
    | undefined,
  variantId: string,
): string {
  for (const product of products ?? []) {
    const variant = product.variants.find((candidate) => candidate.id === variantId)
    if (variant) {
      return product.variants.length > 1 && variant.name
        ? `${product.name} — ${variant.name}`
        : product.name
    }
  }
  return 'Product'
}

function ReturnsPanel({ tenantId }: { tenantId: string | undefined }) {
  const sales = usePosSales(tenantId)
  const returns = usePosReturns(tenantId)
  const createReturn = useCreatePosReturn(tenantId)
  const [saleId, setSaleId] = useState('')
  const [quantities, setQuantities] = useState<Record<string, string>>({})
  const [refundMethod, setRefundMethod] = useState<'cash' | 'mpesa' | 'card' | 'none'>('cash')
  const [reason, setReason] = useState('')
  const [error, setError] = useState<string | null>(null)

  const eligibleSales = (sales.data ?? []).filter((sale) =>
    ['completed', 'partially_returned'].includes(sale.status),
  )
  const sale = eligibleSales.find((candidate) => candidate.id === saleId)

  function submit() {
    if (!sale) return
    const items = Object.entries(quantities)
      .map(([saleItemId, value]) => ({ sale_item_id: saleItemId, quantity: Number(value) }))
      .filter((item) => item.quantity > 0)

    if (items.length === 0) {
      setError('Enter a return quantity for at least one line.')
      return
    }

    setError(null)
    createReturn.mutate(
      { sale_id: sale.id, refund_method: refundMethod, reason: reason.trim() || undefined, items },
      {
        onSuccess: () => {
          setQuantities({})
          setReason('')
          setSaleId('')
        },
        onError: (err) => setError(errorText(err, 'Could not record the return.')),
      },
    )
  }

  return (
    <div className="grid grid-cols-1 gap-6 lg:grid-cols-12">
      <div className="lg:col-span-5">
        <Card title="Record return">
          <div className="space-y-4">
            {error && <Alert kind="error">{error}</Alert>}
            <Select
              label="Sale"
              value={saleId}
              onChange={(event) => {
                setSaleId(event.target.value)
                setQuantities({})
              }}
            >
              <option value="">Select a sale…</option>
              {eligibleSales.map((candidate) => (
                <option key={candidate.id} value={candidate.id}>
                  {candidate.reference} · {formatMoney(candidate.total_amount, candidate.currency)}
                </option>
              ))}
            </Select>

            {sale && (
              <div className="space-y-3 rounded-lg border border-gray-200 p-3">
                <p className="text-sm font-medium text-gray-800">Items</p>
                {sale.items.map((item) => (
                  <div key={item.id} className="flex items-center justify-between gap-3 text-sm">
                    <div>
                      <p className="font-medium text-gray-800">{item.product_name}</p>
                      <p className="text-xs text-gray-500">
                        {item.quantity} {item.unit} · {formatMoney(item.unit_price)}
                      </p>
                    </div>
                    <Field
                      label=""
                      aria-label={`Return quantity for ${item.product_name}`}
                      type="number"
                      min="0"
                      max={item.quantity}
                      step="0.001"
                      value={quantities[item.id] ?? ''}
                      onChange={(event) =>
                        setQuantities((current) => ({ ...current, [item.id]: event.target.value }))
                      }
                      className="w-24"
                    />
                  </div>
                ))}
              </div>
            )}

            <Select
              label="Refund method"
              value={refundMethod}
              onChange={(event) =>
                setRefundMethod(event.target.value as 'cash' | 'mpesa' | 'card' | 'none')
              }
            >
              <option value="cash">Cash</option>
              <option value="mpesa">M-Pesa</option>
              <option value="card">Card</option>
              <option value="none">No refund / credit note</option>
            </Select>
            <Field label="Reason" value={reason} onChange={(event) => setReason(event.target.value)} />
            <Button
              onClick={submit}
              loading={createReturn.isPending}
              disabled={!sale}
            >
              Process return
            </Button>
          </div>
        </Card>
      </div>
      <div className="lg:col-span-7">
        <Card title={`Returns (${returns.data?.length ?? 0})`}>
          <Table headers={['Reference', 'Sale', 'Refund', 'Status', 'Returned']}>
            {(returns.data ?? []).map((row) => (
              <tr key={row.id} className="hover:bg-gray-50">
                <td className="px-4 py-3 font-medium">{row.reference}</td>
                <td className="px-4 py-3 text-gray-600">{row.sale_reference ?? '—'}</td>
                <td className="px-4 py-3">{formatMoney(row.total_amount, row.currency)}</td>
                <td className="px-4 py-3">
                  <Badge tone="green">{row.status}</Badge>
                </td>
                <td className="px-4 py-3 text-gray-500">{formatDateTime(row.returned_at ?? '')}</td>
              </tr>
            ))}
          </Table>
        </Card>
      </div>
    </div>
  )
}

function TransfersPanel({ tenantId }: { tenantId: string | undefined }) {
  const transfers = usePosTransfers(tenantId)
  const branches = useBranches(tenantId)
  const createTransfer = useCreatePosTransfer(tenantId)
  const receiveTransfer = useReceivePosTransfer(tenantId)
  const [fromBranchId, setFromBranchId] = useState('')
  const [toBranchId, setToBranchId] = useState('')
  const [variantId, setVariantId] = useState('')
  const [quantity, setQuantity] = useState('1')
  const [status, setStatus] = useState<'received' | 'in_transit'>('received')
  const [error, setError] = useState<string | null>(null)

  function submit() {
    if (fromBranchId === toBranchId) {
      setError('Source and destination branches must differ.')
      return
    }
    setError(null)
    createTransfer.mutate(
      {
        from_branch_id: fromBranchId,
        to_branch_id: toBranchId,
        status,
        items: [{ variant_id: variantId, quantity: Number(quantity) }],
      },
      {
        onSuccess: () => {
          setVariantId('')
          setQuantity('1')
        },
        onError: (err) => setError(errorText(err, 'Could not create the transfer.')),
      },
    )
  }

  return (
    <div className="grid grid-cols-1 gap-6 lg:grid-cols-12">
      <div className="lg:col-span-4">
        <Card title="New transfer">
          <div className="space-y-4">
            {error && <Alert kind="error">{error}</Alert>}
            <Select
              label="From branch"
              value={fromBranchId}
              onChange={(event) => setFromBranchId(event.target.value)}
            >
              <option value="">Select a branch…</option>
              {(branches.data ?? []).map((branch) => (
                <option key={branch.id} value={branch.id}>
                  {branch.name}
                </option>
              ))}
            </Select>
            <Select
              label="To branch"
              value={toBranchId}
              onChange={(event) => setToBranchId(event.target.value)}
            >
              <option value="">Select a branch…</option>
              {(branches.data ?? []).map((branch) => (
                <option key={branch.id} value={branch.id}>
                  {branch.name}
                </option>
              ))}
            </Select>
            <VariantSelect tenantId={tenantId} value={variantId} onChange={setVariantId} />
            <Field
              label="Quantity"
              type="number"
              min="0.001"
              step="0.001"
              value={quantity}
              onChange={(event) => setQuantity(event.target.value)}
            />
            <Select
              label="Dispatch"
              value={status}
              onChange={(event) => setStatus(event.target.value as 'received' | 'in_transit')}
            >
              <option value="received">Receive immediately</option>
              <option value="in_transit">Mark in transit</option>
            </Select>
            <Button
              onClick={submit}
              loading={createTransfer.isPending}
              disabled={fromBranchId === '' || toBranchId === '' || variantId === ''}
            >
              Create transfer
            </Button>
          </div>
        </Card>
      </div>
      <div className="lg:col-span-8">
        <Card title={`Transfers (${transfers.data?.length ?? 0})`}>
          <Table headers={['Reference', 'Route', 'Status', 'Items', 'Actions']}>
            {(transfers.data ?? []).map((transfer) => (
              <tr key={transfer.id} className="hover:bg-gray-50">
                <td className="px-4 py-3 font-medium">{transfer.reference}</td>
                <td className="px-4 py-3 text-gray-600">
                  {transfer.from_branch ?? '—'} → {transfer.to_branch ?? '—'}
                </td>
                <td className="px-4 py-3">
                  <Badge tone={transfer.status === 'received' ? 'green' : 'amber'}>
                    {transfer.status}
                  </Badge>
                </td>
                <td className="px-4 py-3 text-gray-600">{transfer.items.length}</td>
                <td className="px-4 py-3">
                  {transfer.status === 'in_transit' && (
                    <Button
                      variant="secondary"
                      onClick={() => receiveTransfer.mutate(transfer.id)}
                      loading={receiveTransfer.isPending}
                    >
                      Receive
                    </Button>
                  )}
                </td>
              </tr>
            ))}
          </Table>
        </Card>
      </div>
    </div>
  )
}
