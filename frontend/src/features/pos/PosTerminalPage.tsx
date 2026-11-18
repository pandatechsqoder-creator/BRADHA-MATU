import { useState } from 'react'
import { Alert, Badge, Button, Card, Field, PageHeader, Select, Table } from '../../components/ui'
import { useCurrentUser } from '../../lib/auth/hooks'
import { ApiError } from '../../lib/api/client'
import { fetchPosProducts, type PosProduct } from '../../lib/api/pos'
import {
  useCreatePosSale,
  useCreatePosTerminal,
  useOpenPosShift,
  usePosProducts,
  usePosShifts,
  usePosTerminals,
} from '../../lib/pos/hooks'
import type { CreateSaleInput, PosSale } from '../../lib/api/pos'

interface CartLine {
  variant_id: string
  product_name: string
  sku: string | null
  barcode: string | null
  quantity: number
  unit: string
  unit_price: number
  tax_amount: number
  discount_amount: number
}

function money(value: number, currency = 'KES'): string {
  return `${currency} ${value.toLocaleString('en-KE', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  })}`
}

export function PosTerminalPage() {
  const { data: user } = useCurrentUser()
  const tenantId = user?.current_tenant?.id

  const terminalsQuery = usePosTerminals(tenantId)
  const shiftsQuery = usePosShifts(tenantId)
  const createTerminal = useCreatePosTerminal(tenantId)
  const createSale = useCreatePosSale(tenantId)

  const terminals = terminalsQuery.data ?? []
  const shifts = shiftsQuery.data ?? []

  const [terminalId, setTerminalId] = useState('')
  const [newTerminalName, setNewTerminalName] = useState('')
  const [openingFloat, setOpeningFloat] = useState('0')
  const [search, setSearch] = useState('')
  const [searchTerm, setSearchTerm] = useState('')
  const [brand, setBrand] = useState('')
  const [brandTerm, setBrandTerm] = useState('')
  const [category, setCategory] = useState('')
  const [categoryTerm, setCategoryTerm] = useState('')
  const [searchPage, setSearchPage] = useState(1)
  const [barcode, setBarcode] = useState('')
  const [lookupBusy, setLookupBusy] = useState(false)
  const [cart, setCart] = useState<CartLine[]>([])
  const [paymentMethod, setPaymentMethod] = useState<'cash' | 'mpesa' | 'card' | 'mixed'>('cash')
  const [cashTendered, setCashTendered] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [lastSale, setLastSale] = useState<PosSale | null>(null)

  const activeTerminal = terminals.find((terminal) => terminal.id === terminalId) ?? terminals[0]
  const productsQuery = usePosProducts(tenantId, {
    terminalId: activeTerminal?.id ?? '',
    search: searchTerm || undefined,
    brand: brandTerm || undefined,
    category: categoryTerm || undefined,
    page: searchPage,
    perPage: 8,
  })
  const openShift = shifts.find(
    (shift) => shift.status === 'open' && shift.terminal_id === activeTerminal?.id,
  )
  const openShiftMutation = useOpenPosShift(tenantId, activeTerminal?.id)

  const subtotal = cart.reduce((sum, line) => sum + line.quantity * line.unit_price, 0)
  const taxTotal = cart.reduce((sum, line) => sum + line.tax_amount, 0)
  const discountTotal = cart.reduce((sum, line) => sum + line.discount_amount, 0)
  const total = subtotal + taxTotal - discountTotal
  const change = paymentMethod === 'cash' ? Math.max(0, Number(cashTendered || 0) - total) : 0
  const currency = lastSale?.currency ?? 'KES'

  const searchResults = productsQuery.data?.products ?? []

  function runSearch() {
    setSearchTerm(search.trim())
    setBrandTerm(brand.trim())
    setCategoryTerm(category.trim())
    setSearchPage(1)
  }

  function addToCart(product: PosProduct, scannedBarcode?: string) {
    setError(null)
    const variants = product.variants.filter((variant) => variant.status === 'active')
    const variant =
      (scannedBarcode
        ? variants.find((candidate) =>
            candidate.barcodes.some((code) => code.barcode === scannedBarcode),
          )
        : undefined) ?? variants[0]

    if (!variant) {
      setError(`${product.name} has no active variant to sell.`)
      return
    }
    if (variant.selling_price === null || variant.selling_price <= 0) {
      setError(
        `No selling price is configured for ${product.name}. Set a catalogue variant retail_price, selling_price, unit_price or price before selling.`,
      )
      return
    }
    const unitPrice = variant.selling_price

    const barcodeValue =
      scannedBarcode ?? variant.barcodes.find((code) => code.is_primary)?.barcode
      ?? variant.barcodes[0]?.barcode ?? null
    setCart((current) => {
      const existing = current.findIndex((line) => line.variant_id === variant.id)
      if (existing >= 0) {
        const next = [...current]
        next[existing] = { ...next[existing], quantity: next[existing].quantity + 1 }
        return next
      }
      return [
        ...current,
        {
          variant_id: variant.id,
          product_name:
            product.variants.length > 1 && variant.name
              ? `${product.name} — ${variant.name}`
              : product.name,
          sku: variant.sku ?? product.sku ?? null,
          barcode: barcodeValue,
          quantity: 1,
          unit: variant.base_unit ?? product.base_unit ?? 'pcs',
          unit_price: unitPrice,
          tax_amount: 0,
          discount_amount: 0,
        },
      ]
    })
    setLastSale(null)
    setSearch('')
    setSearchTerm('')
  }

  async function handleBarcodeLookup() {
    const code = barcode.trim()
    if (!tenantId || !activeTerminal || code === '') return
    setLookupBusy(true)
    setError(null)
    try {
      const result = await fetchPosProducts(tenantId, {
        terminalId: activeTerminal.id,
        search: code,
        perPage: 50,
      })
      const match = result.products.find((product) =>
        product.variants.some((variant) =>
          variant.barcodes.some((candidate) => candidate.barcode === code),
        ),
      )
      if (!match) {
        setError(`No active product matches “${code}”.`)
        return
      }
      addToCart(match, code)
      setBarcode('')
    } catch (err) {
      setError(err instanceof ApiError ? err.message : 'Barcode lookup failed.')
    } finally {
      setLookupBusy(false)
    }
  }

  function updateLine(index: number, value: number) {
    setCart((current) => {
      const next = [...current]
      next[index] = { ...next[index], quantity: value }
      return next
    })
  }

  function removeLine(index: number) {
    setCart((current) => current.filter((_, position) => position !== index))
  }

  function handleCreateTerminal() {
    if (newTerminalName.trim() === '') return
    setError(null)
    createTerminal.mutate(
      { name: newTerminalName.trim() },
      {
        onSuccess: (terminal) => {
          setNewTerminalName('')
          setTerminalId(terminal.id)
        },
        onError: (err) =>
          setError(err instanceof ApiError ? err.message : 'Could not create the terminal.'),
      },
    )
  }

  function handleOpenShift() {
    if (!activeTerminal) return
    setError(null)
    openShiftMutation.mutate(
      { opening_float: Number(openingFloat || 0) },
      {
        onError: (err) =>
          setError(err instanceof ApiError ? err.message : 'Could not open the shift.'),
      },
    )
  }

  function handleCheckout() {
    if (!activeTerminal) {
      setError('Create or select a POS terminal first.')
      return
    }
    if (!openShift) {
      setError('Open a shift before making sales.')
      return
    }
    if (cart.length === 0) {
      setError('The cart is empty.')
      return
    }
    if (cart.some((line) => line.unit_price <= 0)) {
      setError('Set a unit price above zero for every cart line.')
      return
    }

    const input: CreateSaleInput = {
      terminal_id: activeTerminal.id,
      payment_method: paymentMethod,
      cash_tendered:
        paymentMethod === 'cash' ? Number(cashTendered || 0) || total : undefined,
      items: cart.map((line) => ({
        variant_id: line.variant_id,
        barcode: line.barcode ?? undefined,
        quantity: line.quantity,
        tax_amount: line.tax_amount,
        discount_amount: line.discount_amount,
      })),
    }

    setError(null)
    createSale.mutate(input, {
      onSuccess: (sale) => {
        setLastSale(sale)
        setCart([])
        setCashTendered('')
      },
      onError: (err) =>
        setError(err instanceof ApiError ? err.message : 'Checkout failed.'),
    })
  }

  if (terminalsQuery.isLoading || shiftsQuery.isLoading) {
    return <div className="py-16 text-center text-gray-500">Loading POS…</div>
  }

  return (
    <div className="space-y-6">
      <PageHeader
        title="POS Terminal"
        description="Scan barcodes or search the catalogue, then take payment."
        actions={
          <div className="flex items-center gap-2">
            <Badge tone={activeTerminal ? 'green' : 'red'}>
              {activeTerminal ? activeTerminal.name : 'No terminal'}
            </Badge>
            <Badge tone={openShift ? 'green' : 'amber'}>
              {openShift ? 'Shift open' : 'Shift closed'}
            </Badge>
          </div>
        }
      />

      {error && <Alert kind="error">{error}</Alert>}
      {lastSale && (
        <Alert kind="success">
          Sale {lastSale.reference} completed — receipt {lastSale.receipt_number ?? 'pending'}. Total{' '}
          {money(lastSale.total_amount, lastSale.currency)}
          {lastSale.cash_change !== null
            ? ` · change ${money(lastSale.cash_change, lastSale.currency)}`
            : ''}
        </Alert>
      )}

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-12">
        <div className="space-y-6 lg:col-span-4">
          <Card title="Terminal & shift">
            <div className="space-y-4">
              {terminals.length > 0 ? (
                <Select
                  label="Terminal"
                  name="terminal"
                  value={activeTerminal?.id ?? ''}
                  onChange={(event) => setTerminalId(event.target.value)}
                >
                  {terminals.map((terminal) => (
                    <option key={terminal.id} value={terminal.id}>
                      {terminal.name}
                    </option>
                  ))}
                </Select>
              ) : (
                <div className="space-y-3">
                  <p className="text-sm text-gray-600">
                    No terminal yet. Create one to start selling.
                  </p>
                  <Field
                    label="Terminal name"
                    name="terminal_name"
                    value={newTerminalName}
                    onChange={(event) => setNewTerminalName(event.target.value)}
                    placeholder="Till 1"
                  />
                  <Button
                    onClick={handleCreateTerminal}
                    loading={createTerminal.isPending}
                    disabled={newTerminalName.trim() === ''}
                  >
                    Create terminal
                  </Button>
                </div>
              )}

              {activeTerminal && !openShift && (
                <div className="space-y-3 border-t border-gray-100 pt-4">
                  <p className="text-sm text-gray-600">This terminal has no open shift.</p>
                  <Field
                    label="Opening float"
                    name="opening_float"
                    type="number"
                    min={0}
                    step="0.01"
                    value={openingFloat}
                    onChange={(event) => setOpeningFloat(event.target.value)}
                  />
                  <Button onClick={handleOpenShift} loading={openShiftMutation.isPending}>
                    Open shift
                  </Button>
                </div>
              )}

              {openShift && (
                <div className="space-y-1 border-t border-gray-100 pt-4 text-sm">
                  <div className="flex justify-between">
                    <span className="text-gray-500">Opened</span>
                    <span>
                      {openShift.opened_at
                        ? new Date(openShift.opened_at).toLocaleString('en-KE')
                        : '—'}
                    </span>
                  </div>
                  <div className="flex justify-between">
                    <span className="text-gray-500">Float</span>
                    <span>{money(openShift.opening_float, currency)}</span>
                  </div>
                  <div className="flex justify-between">
                    <span className="text-gray-500">Cash sales</span>
                    <span>{money(openShift.total_cash_sales, currency)}</span>
                  </div>
                </div>
              )}
            </div>
          </Card>

          <Card title="Scan or search">
            <div className="space-y-4">
              <Field
                label="Barcode"
                name="barcode"
                value={barcode}
                onChange={(event) => setBarcode(event.target.value)}
                onKeyDown={(event) => {
                  if (event.key === 'Enter') {
                    event.preventDefault()
                    void handleBarcodeLookup()
                  }
                }}
                placeholder="Scan barcode and press Enter"
                autoFocus
              />
              <Button
                onClick={() => void handleBarcodeLookup()}
                loading={lookupBusy}
                disabled={barcode.trim() === ''}
              >
                Look up barcode
              </Button>

              <div className="space-y-3 border-t border-gray-100 pt-4">
                <Field
                  label="Catalogue search"
                  name="catalogue_search"
                  value={search}
                  onChange={(event) => setSearch(event.target.value)}
                  onKeyDown={(event) => {
                    if (event.key === 'Enter') {
                      event.preventDefault()
                      runSearch()
                    }
                  }}
                  placeholder="Product name, SKU or barcode"
                />
                  <Field
                    label="Brand filter"
                    name="pos_brand_filter"
                    value={brand}
                    onChange={(event) => setBrand(event.target.value)}
                    onKeyDown={(event) => {
                      if (event.key === 'Enter') {
                        event.preventDefault()
                        runSearch()
                      }
                    }}
                    placeholder="Any brand"
                  />
                  <Field
                    label="Category filter"
                    name="pos_category_filter"
                    value={category}
                    onChange={(event) => setCategory(event.target.value)}
                    onKeyDown={(event) => {
                      if (event.key === 'Enter') {
                        event.preventDefault()
                        runSearch()
                      }
                    }}
                    placeholder="Category name"
                  />
                  <Button
                    variant="secondary"
                    onClick={runSearch}
                    disabled={
                      search.trim() === '' &&
                      brand.trim() === '' &&
                      category.trim() === ''
                    }
                  >
                    Search
                  </Button>

                  {productsQuery.isFetching && <p className="text-sm text-gray-500">Searching…</p>}
                  {(searchTerm !== '' || brandTerm !== '' || categoryTerm !== '') &&
                    !productsQuery.isFetching &&
                    searchResults.length === 0 && (
                    <p className="text-sm text-gray-500">No active products matched.</p>
                  )}
                {searchResults.length > 0 && (
                  <ul className="divide-y divide-gray-100 rounded-lg border border-gray-200">
                    {searchResults.map((product) => (
                      <li
                        key={product.id}
                        className="flex items-center justify-between gap-3 px-3 py-2"
                      >
                        <div className="min-w-0">
                          <p className="truncate text-sm font-medium text-gray-900">
                            {product.name}
                          </p>
                          <p className="truncate text-xs text-gray-500">
                            {product.sku ?? 'No SKU'}
                            {product.barcode ? ` · ${product.barcode}` : ''}
                          </p>
                          <p className="truncate text-xs text-gray-500">
                            {product.brand ?? 'No brand'}
                            {product.category ? ` · ${product.category.name}` : ''}
                            {' · '}
                            {product.variants[0]?.selling_price
                              ? money(product.variants[0].selling_price)
                              : 'Selling price not configured'}
                          </p>
                        </div>
                        <Button
                          variant="secondary"
                          onClick={() => addToCart(product)}
                          disabled={lookupBusy || !product.variants[0]?.selling_price}
                        >
                          Add
                        </Button>
                      </li>
                    ))}
                  </ul>
                )}
                {productsQuery.data && productsQuery.data.pagination.total > 0 && (
                  <div className="flex items-center justify-between gap-3 text-sm text-gray-600">
                    <span>
                      Page {productsQuery.data.pagination.current_page} of{' '}
                      {productsQuery.data.pagination.last_page} ·{' '}
                      {productsQuery.data.pagination.total} products
                    </span>
                    <div className="flex gap-2">
                      <Button
                        variant="secondary"
                        disabled={searchPage <= 1 || productsQuery.isFetching}
                        onClick={() => setSearchPage((page) => page - 1)}
                      >
                        Previous
                      </Button>
                      <Button
                        variant="secondary"
                        disabled={
                          searchPage >= productsQuery.data.pagination.last_page ||
                          productsQuery.isFetching
                        }
                        onClick={() => setSearchPage((page) => page + 1)}
                      >
                        Next
                      </Button>
                    </div>
                  </div>
                )}
              </div>
            </div>
          </Card>
        </div>

        <div className="space-y-6 lg:col-span-8">
          <Card title="Cart">
            {cart.length === 0 ? (
              <p className="py-8 text-center text-sm text-gray-500">
                Empty cart — scan a barcode or add a catalogue product.
              </p>
            ) : (
              <Table headers={['Product', 'Qty', 'Unit price', 'Line total', '']}>
                {cart.map((line, index) => (
                  <tr key={line.variant_id} className="hover:bg-gray-50">
                    <td className="px-4 py-3">
                      <div className="font-medium">{line.product_name}</div>
                      <div className="text-xs text-gray-500">
                        {[line.sku, line.barcode].filter(Boolean).join(' · ') || '—'}
                      </div>
                    </td>
                    <td className="px-4 py-3">
                      <input
                        type="number"
                        min={1}
                        step="1"
                        value={line.quantity}
                        onChange={(event) =>
                          updateLine(
                            index,
                            Math.max(1, Number(event.target.value) || 1),
                          )
                        }
                        className="w-20 rounded-lg border border-gray-300 bg-white px-2 py-1.5 text-sm focus:outline-2 focus:outline-brand-600"
                      />
                    </td>
                    <td className="px-4 py-3">
                      {money(line.unit_price, currency)}
                    </td>
                    <td className="px-4 py-3 font-mono text-sm">
                      {money(line.quantity * line.unit_price, currency)}
                    </td>
                    <td className="px-4 py-3">
                      <Button variant="danger" onClick={() => removeLine(index)}>
                        Remove
                      </Button>
                    </td>
                  </tr>
                ))}
              </Table>
            )}

            <div className="mt-4 space-y-2 border-t border-gray-200 pt-4">
              <div className="flex justify-between text-sm">
                <span className="text-gray-500">Subtotal</span>
                <span>{money(subtotal, currency)}</span>
              </div>
              <div className="flex justify-between text-sm">
                <span className="text-gray-500">Tax</span>
                <span>{money(taxTotal, currency)}</span>
              </div>
              <div className="flex justify-between text-sm">
                <span className="text-gray-500">Discount</span>
                <span>-{money(discountTotal, currency)}</span>
              </div>
              <div className="flex justify-between text-base font-semibold">
                <span>Total</span>
                <span>{money(total, currency)}</span>
              </div>
            </div>

            <div className="mt-4 grid grid-cols-1 gap-3 border-t border-gray-200 pt-4 sm:grid-cols-2">
              <Select
                label="Payment method"
                name="payment_method"
                value={paymentMethod}
                onChange={(event) =>
                  setPaymentMethod(event.target.value as 'cash' | 'mpesa' | 'card' | 'mixed')
                }
              >
                <option value="cash">Cash</option>
                <option value="mpesa">M-Pesa</option>
                <option value="card">Card</option>
                <option value="mixed">Mixed</option>
              </Select>
              {paymentMethod === 'cash' && (
                <Field
                  label="Cash tendered"
                  name="cash_tendered"
                  type="number"
                  min={0}
                  step="0.01"
                  value={cashTendered}
                  onChange={(event) => setCashTendered(event.target.value)}
                  placeholder={total.toFixed(2)}
                  hint={`Change: ${money(change, currency)}`}
                />
              )}
            </div>

            <Button
              className="mt-4 w-full"
              onClick={handleCheckout}
              loading={createSale.isPending}
              disabled={cart.length === 0 || !openShift || !activeTerminal}
            >
              Complete sale
            </Button>
          </Card>
        </div>
      </div>
    </div>
  )
}
