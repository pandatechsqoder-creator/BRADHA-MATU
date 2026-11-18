import { Fragment, useState, type FormEvent } from 'react'
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
import { ApiError } from '../../lib/api/client'
import type {
  InventoryBalance,
  InventoryBatch,
  InventoryCutoff,
  InventoryMovement,
  InventorySerial,
  InventorySnapshot,
  MovementLineInput,
  MovementType,
} from '../../lib/api/inventory'
import { MOVEMENT_TYPES } from '../../lib/api/inventory'
import {
  useCreateInventoryCutoff,
  useCreateInventoryMovement,
  useCreateInventorySnapshot,
  useCloseInventoryCutoff,
  useImportInventoryMovements,
  useInventoryBalances,
  useInventoryBatches,
  useInventoryCutoff,
  useInventoryCutoffs,
  useInventoryMovements,
  useInventorySerials,
  useInventorySnapshot,
  useInventorySnapshots,
  useInventorySummary,
} from '../../lib/inventory/hooks'
import {
  useBranches,
  useCurrentUser,
  useProductCategories,
  useStockLocations,
} from '../../lib/auth/hooks'
import { formatDateTime, formatKes } from '../../lib/formatting'

type Tab = 'overview' | 'balances' | 'movements' | 'snapshots' | 'tracking'

function describeError(error: unknown): string {
  if (error instanceof ApiError) {
    const first = Object.values(error.details)[0]?.[0]
    return first ?? error.message
  }
  if (error instanceof Error && error.message) return error.message
  return 'The change could not be saved. Try again.'
}

function statusTone(status: string): 'gray' | 'green' | 'red' | 'amber' {
  if (['posted', 'frozen', 'closed', 'in_stock'].includes(status)) return 'green'
  if (['voided', 'superseded', 'expired', 'scrapped', 'missing'].includes(status)) return 'red'
  if (['open', 'pending', 'sold'].includes(status)) return 'amber'
  return 'gray'
}

function movementTone(type: string): 'green' | 'red' | 'amber' | 'gray' {
  if (['opening', 'purchase', 'return', 'transfer_in', 'production'].includes(type)) return 'green'
  if (['sale', 'transfer_out', 'damage', 'expiry', 'consumption'].includes(type)) return 'red'
  if (type === 'adjustment') return 'amber'
  return 'gray'
}

function quantity(value: number): string {
  return new Intl.NumberFormat('en-KE', { maximumFractionDigits: 3 }).format(value)
}

function movementTotal(movement: InventoryMovement): number {
  return movement.lines.reduce((sum, line) => sum + line.quantity, 0)
}

function Pager({ meta, onChange }: { meta: { current_page: number; last_page: number; total: number }; onChange: (page: number) => void }) {
  return (
    <div className="flex items-center justify-between text-xs text-gray-500">
      <span>
        Page {meta.current_page} of {meta.last_page} · {meta.total} rows
      </span>
      <div className="flex gap-2">
        <Button variant="secondary" disabled={meta.current_page <= 1} onClick={() => onChange(meta.current_page - 1)}>
          Previous
        </Button>
        <Button variant="secondary" disabled={meta.current_page >= meta.last_page} onClick={() => onChange(meta.current_page + 1)}>
          Next
        </Button>
      </div>
    </div>
  )
}

function LocationSelect({
  value,
  onChange,
  label,
  name,
  includeEmpty = true,
  emptyLabel = 'All stock locations',
}: {
  value: string
  onChange: (value: string) => void
  label: string
  name: string
  includeEmpty?: boolean
  emptyLabel?: string
}) {
  const { data: user } = useCurrentUser()
  const locations = useStockLocations(user?.current_tenant?.id)

  return (
    <Select label={label} name={name} value={value} onChange={(event) => onChange(event.target.value)}>
      {includeEmpty && <option value="">{emptyLabel}</option>}
      {(locations.data ?? []).map((location) => (
        <option key={location.id} value={location.id}>
          {location.name}
        </option>
      ))}
    </Select>
  )
}

function OverviewPanel({ tenantId, onNavigate }: { tenantId: string; onNavigate: (tab: Tab) => void }) {
  const summary = useInventorySummary(tenantId)

  if (summary.isLoading) return <PageLoader label="Loading inventory overview…" />
  if (summary.isError) return <Alert kind="error">Inventory overview could not be loaded.</Alert>
  if (!summary.data) return null

  const { stats, latest_snapshot: snapshot, open_cutoff: cutoff, recent_movements: movements, tracking } = summary.data

  return (
    <div className="space-y-5">
      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <StatCard
          label="Quantity on hand"
          value={quantity(stats.total_quantity)}
          hint={`${stats.balance_rows} balance rows · ${stats.tracked_variants} tracked variants`}
        />
        <StatCard
          label="Stock value"
          value={summary.data.can_view_cost && stats.stock_value !== null ? formatKes(stats.stock_value) : 'Hidden'}
          hint={summary.data.can_view_cost ? 'Cost value at current catalogue cost' : 'Your role cannot view cost values'}
        />
        <StatCard label="Movements (30 days)" value={stats.movements_30_days} hint={`${stats.locations} stock locations`} />
        <StatCard
          label="Expiry watch"
          value={stats.expiring_batches}
          hint={`${stats.expired_batches} expired · ${stats.serials_in_stock} serials in stock`}
        />
      </div>

      {cutoff && (
        <Alert kind="info">
          A cut-off is open for {cutoff.stock_location?.name ?? cutoff.branch?.name ?? 'this scope'} from{' '}
          {cutoff.cut_off_at ? formatDateTime(cutoff.cut_off_at) : 'an earlier date'} —{' '}
          {cutoff.movement_count} movement(s) captured. Snapshots at this cut-off exclude later activity.{' '}
          <button type="button" className="font-medium underline" onClick={() => onNavigate('snapshots')}>
            Review cut-offs
          </button>
        </Alert>
      )}

      <div className="grid gap-4 lg:grid-cols-[1.5fr_1fr]">
        <Card
          title="Recent movements"
          actions={
            <Button variant="secondary" onClick={() => onNavigate('movements')}>
              Open ledger
            </Button>
          }
        >
          {movements.length === 0 ? (
            <EmptyState title="No movements yet" description="Post opening stock or import an external movement file." />
          ) : (
            <Table headers={['Date', 'Type', 'Reference', 'Location', 'Qty', 'Source']}>
              {movements.map((movement) => (
                <tr key={movement.id}>
                  <td className="px-4 py-2 text-gray-500">{movement.occurred_at ? formatDateTime(movement.occurred_at) : '—'}</td>
                  <td className="px-4 py-2">
                    <Badge tone={movementTone(movement.type)}>{movement.type.replaceAll('_', ' ')}</Badge>
                  </td>
                  <td className="px-4 py-2 text-gray-700">{movement.reference ?? movement.external_id ?? '—'}</td>
                  <td className="px-4 py-2 text-gray-600">{movement.stock_location?.name ?? '—'}</td>
                  <td className="px-4 py-2 font-mono text-xs text-gray-700">{quantity(movementTotal(movement))}</td>
                  <td className="px-4 py-2 text-xs uppercase text-gray-500">{movement.source}</td>
                </tr>
              ))}
            </Table>
          )}
        </Card>

        <div className="space-y-4">
          <Card title="Latest snapshot">
            {snapshot ? (
              <div className="space-y-2 text-sm text-gray-600">
                <p className="font-medium text-gray-900">{snapshot.reference ?? 'Frozen stock snapshot'}</p>
                <p>
                  {snapshot.snapshot_at ? formatDateTime(snapshot.snapshot_at) : '—'} · {snapshot.item_count} SKUs ·{' '}
                  {quantity(snapshot.total_quantity)} units
                </p>
                <p className="text-xs text-gray-500">
                  Catalogue v{snapshot.catalogue_version ?? '—'} · {snapshot.stock_location?.name ?? snapshot.branch?.name ?? 'All locations'}
                </p>
                <p className="break-all font-mono text-[11px] text-gray-500">sha256:{snapshot.checksum}</p>
                <Button variant="secondary" onClick={() => onNavigate('snapshots')}>
                  Open snapshots
                </Button>
              </div>
            ) : (
              <EmptyState title="No snapshots yet" description="Open a cut-off and freeze expected stock before a count." />
            )}
          </Card>

          <Card title="Tracking mix">
            {tracking.length === 0 ? (
              <p className="text-sm text-gray-500">No active variants tracked yet.</p>
            ) : (
              <div className="flex flex-wrap gap-2">
                {tracking.map((item) => (
                  <Badge key={item.mode} tone={item.mode === 'quantity' ? 'gray' : 'green'}>
                    {item.mode} · {item.variants}
                  </Badge>
                ))}
              </div>
            )}
          </Card>
        </div>
      </div>
    </div>
  )
}

function BalancesPanel({ tenantId, canViewCost }: { tenantId: string; canViewCost: boolean }) {
  const [filters, setFilters] = useState({
    search: '',
    search_by: 'name' as const,
    letter: '',
    stock_location: '',
    branch: '',
    warehouse: '',
    category: '',
    brand: '',
    stock_status: 'in_stock' as '' | 'in_stock' | 'out_of_stock',
    page: 1,
  })
  const balances = useInventoryBalances(tenantId, {
    search: filters.search || undefined,
    search_by: filters.search_by,
    letter: filters.letter || undefined,
    stock_location: filters.stock_location || undefined,
    branch: filters.branch || undefined,
    warehouse: filters.warehouse || undefined,
    category: filters.category || undefined,
    brand: filters.brand || undefined,
    stock_status: filters.stock_status || undefined,
    page: filters.page,
  })
  const branches = useBranches(tenantId)
  const locations = useStockLocations(tenantId)
  const categories = useProductCategories(tenantId)

  function resetFilters() {
    setFilters({
      search: '',
      search_by: 'name',
      letter: '',
      stock_location: '',
      branch: '',
      warehouse: '',
      category: '',
      brand: '',
      stock_status: '',
      page: 1,
    })
  }

  return (
    <div className="space-y-5">
      <Card title="Stock balances">
        <div className="space-y-4">
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <Field
              label="Search products"
              name="balance_search"
              placeholder="Search catalogue"
              value={filters.search}
              onChange={(event) => setFilters((current) => ({ ...current, search: event.target.value, page: 1 }))}
            />
            <Select
              label="Search in"
              name="balance_search_by"
              value={filters.search_by}
              onChange={(event) =>
                setFilters((current) => ({
                  ...current,
                  search_by: event.target.value as typeof current.search_by,
                  page: 1,
                }))
              }
            >
              <option value="name">Product name</option>
              <option value="sku">SKU</option>
              <option value="barcode">Barcode</option>
              <option value="brand">Brand</option>
              <option value="category">Category</option>
              <option value="variant">Variant</option>
              <option value="description">Description</option>
            </Select>
            <Select
              label="Branch"
              name="balance_branch"
              value={filters.branch}
              onChange={(event) => setFilters((current) => ({ ...current, branch: event.target.value, page: 1 }))}
            >
              <option value="">All branches</option>
              {(branches.data ?? []).map((branch) => (
                <option key={branch.id} value={branch.id}>{branch.name}</option>
              ))}
            </Select>
            <LocationSelect
              label="Stock location"
              name="balance_location"
              value={filters.stock_location}
              onChange={(value) => setFilters((current) => ({ ...current, stock_location: value, page: 1 }))}
            />
            <Select
              label="Warehouse"
              name="balance_warehouse"
              value={filters.warehouse}
              onChange={(event) => setFilters((current) => ({ ...current, warehouse: event.target.value, page: 1 }))}
            >
              <option value="">All warehouses</option>
              {Array.from(new Map((locations.data ?? []).filter((location) => location.warehouse_id).map((location) => [location.warehouse_id, location.warehouse_name])).entries()).map(([id, name]) => (
                <option key={id} value={id ?? ''}>{name}</option>
              ))}
            </Select>
            <Select
              label="Category"
              name="balance_category"
              value={filters.category}
              onChange={(event) => setFilters((current) => ({ ...current, category: event.target.value, page: 1 }))}
            >
              <option value="">All categories</option>
              {(categories.data ?? []).map((category) => (
                <option key={category.id} value={category.id}>{category.name}</option>
              ))}
            </Select>
            <Field
              label="Brand"
              name="balance_brand"
              placeholder="Any brand"
              value={filters.brand}
              onChange={(event) => setFilters((current) => ({ ...current, brand: event.target.value, page: 1 }))}
            />
            <Select
              label="Stock status"
              name="balance_stock_status"
              value={filters.stock_status}
              onChange={(event) =>
                setFilters((current) => ({
                  ...current,
                  stock_status: event.target.value as typeof current.stock_status,
                  page: 1,
                }))
              }
            >
              <option value="">All stock states</option>
              <option value="in_stock">In stock</option>
              <option value="out_of_stock">Out of stock</option>
            </Select>
          </div>
          <div className="flex flex-wrap items-center gap-1" aria-label="Filter products by first letter">
            <Button
              variant={filters.letter === '' ? 'primary' : 'secondary'}
              onClick={() => setFilters((current) => ({ ...current, letter: '', page: 1 }))}
            >
              All
            </Button>
            {'ABCDEFGHIJKLMNOPQRSTUVWXYZ'.split('').map((letter) => (
              <Button
                key={letter}
                variant={filters.letter === letter ? 'primary' : 'secondary'}
                className="px-2.5"
                aria-label={`Products starting with ${letter}`}
                onClick={() => setFilters((current) => ({ ...current, letter, page: 1 }))}
              >
                {letter}
              </Button>
            ))}
            <Button
              variant={filters.letter === '#' ? 'primary' : 'secondary'}
              aria-label="Products starting with numbers or symbols"
              onClick={() => setFilters((current) => ({ ...current, letter: '#', page: 1 }))}
            >
              # / Other
            </Button>
            <Button variant="secondary" onClick={resetFilters}>Clear filters</Button>
          </div>

          {balances.isLoading && <PageLoader label="Loading balances…" />}
          {balances.isError && <Alert kind="error">Balances could not be loaded.</Alert>}
          {balances.data && balances.data.balances.length === 0 && (
            <EmptyState title="No balances match" description="Post opening stock or relax the filters." />
          )}
          {balances.data && balances.data.balances.length > 0 && (
            <>
              <Table headers={['Product', 'SKU', 'Location', 'Batch', 'Qty', 'Reserved', 'Available', ...(canViewCost ? ['Value'] : [])]}>
                {balances.data.balances.map((balance: InventoryBalance) => (
                  <tr key={balance.id} className="hover:bg-gray-50">
                    <td className="px-4 py-3">
                      <p className="font-medium text-gray-900">{balance.product_name ?? '—'}</p>
                      <p className="text-xs text-gray-500">{balance.variant_name ?? balance.tracking_mode ?? ''}</p>
                    </td>
                    <td className="px-4 py-3 font-mono text-xs text-gray-600">{balance.sku ?? '—'}</td>
                    <td className="px-4 py-3 text-gray-600">{balance.stock_location?.name ?? '—'}</td>
                    <td className="px-4 py-3 text-gray-600">
                      {balance.batch_number ?? '—'}
                      {balance.expires_on && <span className="block text-xs text-gray-500">exp {balance.expires_on}</span>}
                    </td>
                    <td className="px-4 py-3 font-mono text-xs text-gray-900">
                      {quantity(balance.quantity)} {balance.unit ?? ''}
                    </td>
                    <td className="px-4 py-3 font-mono text-xs text-gray-500">{quantity(balance.reserved_quantity)}</td>
                    <td className="px-4 py-3 font-mono text-xs text-gray-700">{quantity(balance.available_quantity)}</td>
                    {canViewCost && (
                      <td className="px-4 py-3 text-gray-700">{balance.value !== null ? formatKes(balance.value) : '—'}</td>
                    )}
                  </tr>
                ))}
              </Table>
              <Pager meta={balances.data.pagination} onChange={(page) => setFilters((current) => ({ ...current, page }))} />
            </>
          )}
        </div>
      </Card>
    </div>
  )
}

const emptyLine: MovementLineInput = {
  sku: '',
  quantity: '',
  batch_number: '',
  serial_number: '',
  expires_on: '',
  unit_cost: '',
}

function MovementsPanel({
  tenantId,
  canCreate,
  canAdjust,
}: {
  tenantId: string
  canCreate: boolean
  canAdjust: boolean
}) {
  const [filters, setFilters] = useState({ type: '', source: '', stock_location: '', search: '', from: '', to: '', page: 1 })
  const movements = useInventoryMovements(tenantId, {
    type: filters.type || undefined,
    source: filters.source || undefined,
    stock_location: filters.stock_location || undefined,
    search: filters.search || undefined,
    from: filters.from || undefined,
    to: filters.to || undefined,
    page: filters.page,
  })
  const createMovement = useCreateInventoryMovement(tenantId)
  const importMovements = useImportInventoryMovements(tenantId)
  const [movement, setMovement] = useState({ stock_location_id: '', type: 'opening' as MovementType, reference: '', occurred_at: '', notes: '' })
  const [lines, setLines] = useState<MovementLineInput[]>([{ ...emptyLine }])
  const [file, setFile] = useState<File | null>(null)
  const [importSource, setImportSource] = useState('import')
  const [error, setError] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)
  const [expanded, setExpanded] = useState<string | null>(null)
  const types = MOVEMENT_TYPES.filter((type) => type !== 'adjustment' || canAdjust)

  function setLine(index: number, key: keyof MovementLineInput, value: string) {
    setLines((current) => current.map((line, position) => (position === index ? { ...line, [key]: value } : line)))
  }

  function submitMovement(event: FormEvent) {
    event.preventDefault()
    setError(null)
    setNotice(null)
    if (!movement.stock_location_id) {
      setError('Choose the stock location this movement applies to.')
      return
    }
    const payload = lines
      .filter((line) => line.sku.trim() !== '')
      .map((line) => ({
        sku: line.sku.trim(),
        quantity: Number(line.quantity),
        batch_number: line.batch_number || undefined,
        serial_number: line.serial_number || undefined,
        expires_on: line.expires_on || undefined,
        unit_cost: line.unit_cost ? Number(line.unit_cost) : undefined,
      }))
    if (payload.length === 0) {
      setError('Add at least one line with a SKU and quantity.')
      return
    }
    createMovement.mutate(
      {
        stock_location_id: movement.stock_location_id,
        type: movement.type,
        reference: movement.reference || undefined,
        occurred_at: movement.occurred_at || undefined,
        notes: movement.notes || undefined,
        lines: payload,
      },
      {
        onSuccess: (created) => {
          setNotice(`${created.type.replaceAll('_', ' ')} movement posted with ${created.line_count} line(s).`)
          setLines([{ ...emptyLine }])
          setMovement((current) => ({ ...current, reference: '', notes: '' }))
        },
        onError: (err) => setError(describeError(err)),
      },
    )
  }

  function submitImport(event: FormEvent) {
    event.preventDefault()
    if (!file) return
    setError(null)
    setNotice(null)
    importMovements.mutate(
      { file, source: importSource },
      {
        onSuccess: (result) => {
          setNotice(
            `${result.created_movements} movement(s) created, ${result.skipped_movements} duplicate(s) skipped, ${result.error_rows} row error(s).`,
          )
          setFile(null)
        },
        onError: (err) => setError(describeError(err)),
      },
    )
  }

  return (
    <div className="space-y-5">
      {error && <Alert kind="error">{error}</Alert>}
      {notice && <Alert kind="success">{notice}</Alert>}

      {canCreate && (
        <div className="grid gap-4 xl:grid-cols-[1.6fr_1fr]">
          <Card title="Post a movement">
            <form onSubmit={submitMovement} className="space-y-4">
              <div className="grid gap-3 sm:grid-cols-2">
                <Select
                  label="Movement type"
                  name="movement_type"
                  value={movement.type}
                  onChange={(event) => setMovement((current) => ({ ...current, type: event.target.value as MovementType }))}
                >
                  {types.map((type) => (
                    <option key={type} value={type}>
                      {type.replaceAll('_', ' ')}
                    </option>
                  ))}
                </Select>
                <LocationSelect
                  label="Stock location"
                  name="movement_location"
                  value={movement.stock_location_id}
                  onChange={(value) => setMovement((current) => ({ ...current, stock_location_id: value }))}
                  emptyLabel="Choose a location"
                />
                <Field
                  label="Reference"
                  name="movement_reference"
                  placeholder="e.g. GRN-1042 or POS-88"
                  value={movement.reference}
                  onChange={(event) => setMovement((current) => ({ ...current, reference: event.target.value }))}
                />
                <Field
                  label="Occurred at"
                  name="movement_occurred_at"
                  type="datetime-local"
                  value={movement.occurred_at}
                  onChange={(event) => setMovement((current) => ({ ...current, occurred_at: event.target.value }))}
                  hint="Leave blank to use the current time"
                />
              </div>

              <div className="space-y-3">
                {lines.map((line, index) => (
                  <div key={index} className="grid gap-2 rounded-lg border border-gray-200 bg-gray-50 p-3 sm:grid-cols-6">
                    <Field label="SKU" name={`line_sku_${index}`} required value={line.sku} onChange={(event) => setLine(index, 'sku', event.target.value)} />
                    <Field
                      label="Quantity"
                      name={`line_quantity_${index}`}
                      type="number"
                      step="any"
                      required
                      value={line.quantity}
                      onChange={(event) => setLine(index, 'quantity', event.target.value)}
                      hint={movement.type === 'adjustment' ? 'Negative allowed' : undefined}
                    />
                    <Field label="Batch" name={`line_batch_${index}`} value={line.batch_number} onChange={(event) => setLine(index, 'batch_number', event.target.value)} />
                    <Field label="Serial" name={`line_serial_${index}`} value={line.serial_number} onChange={(event) => setLine(index, 'serial_number', event.target.value)} />
                    <Field label="Expires" name={`line_expiry_${index}`} type="date" value={line.expires_on} onChange={(event) => setLine(index, 'expires_on', event.target.value)} />
                    <div className="flex items-end gap-2">
                      <Field label="Unit cost" name={`line_cost_${index}`} type="number" min="0" step="any" value={line.unit_cost} onChange={(event) => setLine(index, 'unit_cost', event.target.value)} />
                      {lines.length > 1 && (
                        <Button
                          type="button"
                          variant="secondary"
                          onClick={() => setLines((current) => current.filter((_, position) => position !== index))}
                        >
                          Remove
                        </Button>
                      )}
                    </div>
                  </div>
                ))}
                <div className="flex flex-wrap gap-2">
                  <Button type="button" variant="secondary" onClick={() => setLines((current) => [...current, { ...emptyLine }])}>
                    Add line
                  </Button>
                  <Button type="submit" loading={createMovement.isPending}>
                    Post movement
                  </Button>
                </div>
              </div>
              <Field label="Notes" name="movement_notes" value={movement.notes} onChange={(event) => setMovement((current) => ({ ...current, notes: event.target.value }))} />
            </form>
          </Card>

          <Card title="Import external movements">
            <form onSubmit={submitImport} className="space-y-4">
              <Field
                key={file?.name ?? 'empty-movement-file'}
                label="CSV or XLSX file"
                name="movement_file"
                type="file"
                accept=".csv,.txt,.xlsx"
                onChange={(event) => setFile(event.target.files?.[0] ?? null)}
                hint="Columns: External ID, Type, Stock Location, Occurred At, SKU, Quantity. Batch, serial, expiry and cost columns are optional."
              />
              <Select label="Source tag" name="movement_source" value={importSource} onChange={(event) => setImportSource(event.target.value)}>
                <option value="import">File import</option>
                <option value="external">External system</option>
              </Select>
              <Button type="submit" loading={importMovements.isPending} disabled={!file}>
                Import movements
              </Button>
              <p className="text-xs text-gray-500">
                Rows sharing an External ID become one movement. Re-uploading the same External ID is skipped, keeping imports idempotent.
              </p>
              {importMovements.data && importMovements.data.errors.length > 0 && (
                <div className="rounded-lg border border-red-200 bg-red-50 p-3">
                  <p className="text-xs font-semibold text-red-800">Row errors</p>
                  <ul className="mt-1 space-y-1 text-xs text-red-700">
                    {importMovements.data.errors.map((row, index) => (
                      <li key={`${row.row}-${index}`}>
                        Row {row.row}: {row.message}
                      </li>
                    ))}
                  </ul>
                </div>
              )}
            </form>
          </Card>
        </div>
      )}

      <Card title="Movement ledger">
        <div className="space-y-4">
          <div className="grid gap-3 sm:grid-cols-3 lg:grid-cols-6 sm:items-end">
            <Field
              label="Search"
              name="movement_search"
              placeholder="Reference or SKU"
              value={filters.search}
              onChange={(event) => setFilters((current) => ({ ...current, search: event.target.value, page: 1 }))}
            />
            <Select
              label="Type"
              name="movement_filter_type"
              value={filters.type}
              onChange={(event) => setFilters((current) => ({ ...current, type: event.target.value, page: 1 }))}
            >
              <option value="">All types</option>
              {MOVEMENT_TYPES.map((type) => (
                <option key={type} value={type}>
                  {type.replaceAll('_', ' ')}
                </option>
              ))}
            </Select>
            <Select
              label="Source"
              name="movement_filter_source"
              value={filters.source}
              onChange={(event) => setFilters((current) => ({ ...current, source: event.target.value, page: 1 }))}
            >
              <option value="">All sources</option>
              <option value="manual">Manual</option>
              <option value="import">Import</option>
              <option value="external">External</option>
              <option value="brathamatu_pos">BRATHAMATU POS</option>
            </Select>
            <LocationSelect
              label="Location"
              name="movement_filter_location"
              value={filters.stock_location}
              onChange={(value) => setFilters((current) => ({ ...current, stock_location: value, page: 1 }))}
            />
            <Field
              label="From"
              name="movement_from"
              type="date"
              value={filters.from}
              onChange={(event) => setFilters((current) => ({ ...current, from: event.target.value, page: 1 }))}
            />
            <Field
              label="To"
              name="movement_to"
              type="date"
              value={filters.to}
              onChange={(event) => setFilters((current) => ({ ...current, to: event.target.value, page: 1 }))}
            />
          </div>

          {movements.isLoading && <PageLoader label="Loading movements…" />}
          {movements.isError && <Alert kind="error">Movements could not be loaded.</Alert>}
          {movements.data && movements.data.movements.length === 0 && (
            <EmptyState title="No movements match" description="Adjust the filters or post a movement." />
          )}
          {movements.data && movements.data.movements.length > 0 && (
            <>
              <Table headers={['Date', 'Type', 'Reference', 'Location', 'Lines', 'Qty', 'Source', '']}>
                {movements.data.movements.map((item) => (
                  <Fragment key={item.id}>
                    <tr className="hover:bg-gray-50">
                      <td className="px-4 py-3 text-gray-500">{item.occurred_at ? formatDateTime(item.occurred_at) : '—'}</td>
                      <td className="px-4 py-3">
                        <Badge tone={movementTone(item.type)}>{item.type.replaceAll('_', ' ')}</Badge>
                      </td>
                      <td className="px-4 py-3 text-gray-700">
                        {item.reference ?? '—'}
                        {item.external_id && <span className="block font-mono text-[11px] text-gray-500">{item.external_id}</span>}
                      </td>
                      <td className="px-4 py-3 text-gray-600">{item.stock_location?.name ?? '—'}</td>
                      <td className="px-4 py-3 text-gray-600">{item.line_count}</td>
                      <td className="px-4 py-3 font-mono text-xs text-gray-900">{quantity(movementTotal(item))}</td>
                      <td className="px-4 py-3 text-xs uppercase text-gray-500">{item.source}</td>
                      <td className="px-4 py-3 text-right">
                        <Button variant="secondary" onClick={() => setExpanded(expanded === item.id ? null : item.id)}>
                          {expanded === item.id ? 'Hide' : 'Lines'}
                        </Button>
                      </td>
                    </tr>
                    {expanded === item.id && (
                      <tr>
                        <td colSpan={8} className="bg-gray-50 px-4 py-3">
                          <Table headers={['Product', 'SKU', 'Qty', 'Batch', 'Serial', 'Unit cost']}>
                            {item.lines.map((line, index) => (
                              <tr key={`${item.id}-${index}`}>
                                <td className="px-4 py-2 text-gray-700">{line.product_name ?? '—'}</td>
                                <td className="px-4 py-2 font-mono text-xs text-gray-600">{line.sku ?? '—'}</td>
                                <td className="px-4 py-2 font-mono text-xs text-gray-900">{quantity(line.quantity)}</td>
                                <td className="px-4 py-2 text-gray-600">{line.batch_number ?? '—'}</td>
                                <td className="px-4 py-2 text-gray-600">{line.serial_number ?? '—'}</td>
                                <td className="px-4 py-2 text-gray-600">{line.unit_cost !== null && line.unit_cost !== undefined ? formatKes(line.unit_cost) : '—'}</td>
                              </tr>
                            ))}
                          </Table>
                          {item.cutoff_id && <p className="mt-2 text-xs text-gray-500">Linked to cut-off {item.cutoff_id}</p>}
                        </td>
                      </tr>
                    )}
                  </Fragment>
                ))}
              </Table>
              <Pager meta={movements.data.pagination} onChange={(page) => setFilters((current) => ({ ...current, page }))} />
            </>
          )}
        </div>
      </Card>
    </div>
  )
}

function CutoffDetail({ tenantId, cutoffId, canSnapshot }: { tenantId: string; cutoffId: string; canSnapshot: boolean }) {
  const detail = useInventoryCutoff(tenantId, cutoffId)
  const closeCutoff = useCloseInventoryCutoff(tenantId)
  const [error, setError] = useState<string | null>(null)

  if (detail.isLoading) return <PageLoader label="Loading cut-off…" />
  if (detail.isError) return <Alert kind="error">This cut-off could not be loaded.</Alert>
  if (!detail.data) return null

  const cutoff = detail.data

  return (
    <Card
      title={`Cut-off ${cutoff.id.slice(-6)}`}
      actions={<Badge tone={statusTone(cutoff.status)}>{cutoff.status}</Badge>}
    >
      {error && <Alert kind="error">{error}</Alert>}
      <div className="space-y-4">
        <p className="text-sm text-gray-600">
          {cutoff.stock_location?.name ?? cutoff.branch?.name ?? 'All locations'} · cut-off at{' '}
          {cutoff.cut_off_at ? formatDateTime(cutoff.cut_off_at) : '—'} · {cutoff.movement_count} movement(s)
        </p>
        {cutoff.status === 'open' && canSnapshot && (
          <Button
            loading={closeCutoff.isPending}
            onClick={() =>
              closeCutoff.mutate(cutoff.id, {
                onError: (err) => setError(describeError(err)),
              })
            }
          >
            Close cut-off
          </Button>
        )}
        {cutoff.movements.length > 0 && (
          <Table headers={['Date', 'Type', 'Reference', 'Lines', 'Qty']}>
            {cutoff.movements.map((movement) => (
              <tr key={movement.id}>
                <td className="px-4 py-2 text-gray-500">{movement.occurred_at ? formatDateTime(movement.occurred_at) : '—'}</td>
                <td className="px-4 py-2">
                  <Badge tone={movementTone(movement.type)}>{movement.type.replaceAll('_', ' ')}</Badge>
                </td>
                <td className="px-4 py-2 text-gray-700">{movement.reference ?? movement.external_id ?? '—'}</td>
                <td className="px-4 py-2 text-gray-600">{movement.line_count}</td>
                <td className="px-4 py-2 font-mono text-xs text-gray-900">{quantity(movementTotal(movement))}</td>
              </tr>
            ))}
          </Table>
        )}
        {cutoff.snapshots.length > 0 && (
          <p className="text-xs text-gray-500">
            Snapshots: {cutoff.snapshots.map((snapshot) => snapshot.reference ?? snapshot.id.slice(-6)).join(', ')}
          </p>
        )}
      </div>
    </Card>
  )
}

function CutoffsPanel({ tenantId, canSnapshot }: { tenantId: string; canSnapshot: boolean }) {
  const cutoffs = useInventoryCutoffs(tenantId)
  const createCutoff = useCreateInventoryCutoff(tenantId)
  const [values, setValues] = useState({ stock_location_id: '', cut_off_at: '', notes: '' })
  const [selected, setSelected] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)

  function submit(event: FormEvent) {
    event.preventDefault()
    setError(null)
    setNotice(null)
    if (!values.stock_location_id) {
      setError('Choose a stock location for the cut-off.')
      return
    }
    createCutoff.mutate(
      {
        stock_location_id: values.stock_location_id,
        cut_off_at: values.cut_off_at || undefined,
        notes: values.notes || undefined,
      },
      {
        onSuccess: () => {
          setNotice('Cut-off opened. Movements after the cut-off time will be linked automatically.')
          setValues({ stock_location_id: '', cut_off_at: '', notes: '' })
        },
        onError: (err) => setError(describeError(err)),
      },
    )
  }

  return (
    <div className="space-y-5">
      {error && <Alert kind="error">{error}</Alert>}
      {notice && <Alert kind="success">{notice}</Alert>}

      <div className="grid gap-4 lg:grid-cols-[1fr_1.5fr]">
        {canSnapshot && (
          <Card title="Open a cut-off">
            <form onSubmit={submit} className="space-y-3">
              <LocationSelect
                label="Stock location"
                name="cutoff_location"
                value={values.stock_location_id}
                onChange={(value) => setValues((current) => ({ ...current, stock_location_id: value }))}
                emptyLabel="Choose a location"
              />
              <Field
                label="Cut-off date and time"
                name="cutoff_at"
                type="datetime-local"
                value={values.cut_off_at}
                onChange={(event) => setValues((current) => ({ ...current, cut_off_at: event.target.value }))}
                hint="Leave blank to cut off now"
              />
              <Field label="Notes" name="cutoff_notes" value={values.notes} onChange={(event) => setValues((current) => ({ ...current, notes: event.target.value }))} />
              <Button type="submit" loading={createCutoff.isPending}>
                Open cut-off
              </Button>
            </form>
          </Card>
        )}

        <Card title="Cut-offs">
          {cutoffs.isLoading && <PageLoader label="Loading cut-offs…" />}
          {cutoffs.isError && <Alert kind="error">Cut-offs could not be loaded.</Alert>}
          {cutoffs.data && cutoffs.data.length === 0 && (
            <EmptyState title="No cut-offs yet" description="Open a cut-off before freezing a snapshot for a count." />
          )}
          {cutoffs.data && cutoffs.data.length > 0 && (
            <Table headers={['Cut-off at', 'Scope', 'Status', 'Movements', '']}>
              {cutoffs.data.map((cutoff: InventoryCutoff) => (
                <tr key={cutoff.id} className="hover:bg-gray-50">
                  <td className="px-4 py-3 text-gray-700">{cutoff.cut_off_at ? formatDateTime(cutoff.cut_off_at) : '—'}</td>
                  <td className="px-4 py-3 text-gray-600">{cutoff.stock_location?.name ?? cutoff.branch?.name ?? 'All'}</td>
                  <td className="px-4 py-3">
                    <Badge tone={statusTone(cutoff.status)}>{cutoff.status}</Badge>
                  </td>
                  <td className="px-4 py-3 text-gray-600">{cutoff.movement_count}</td>
                  <td className="px-4 py-3 text-right">
                    <Button variant="secondary" onClick={() => setSelected(cutoff.id)}>
                      Open
                    </Button>
                  </td>
                </tr>
              ))}
            </Table>
          )}
        </Card>
      </div>

      {selected && <CutoffDetail key={selected} tenantId={tenantId} cutoffId={selected} canSnapshot={canSnapshot} />}
    </div>
  )
}

function SnapshotDetail({ tenantId, snapshotId, canViewCost }: { tenantId: string; snapshotId: string; canViewCost: boolean }) {
  const detail = useInventorySnapshot(tenantId, snapshotId)

  if (detail.isLoading) return <PageLoader label="Loading snapshot…" />
  if (detail.isError) return <Alert kind="error">This snapshot could not be loaded.</Alert>
  if (!detail.data) return null

  const snapshot = detail.data
  const showValue = canViewCost && snapshot.items.some((item) => item.value !== null && item.value !== undefined)

  return (
    <Card
      title={snapshot.reference ?? `Snapshot ${snapshot.id.slice(-6)}`}
      actions={<Badge tone={statusTone(snapshot.status)}>{snapshot.status}</Badge>}
    >
      <div className="space-y-4">
        <div className="grid gap-3 sm:grid-cols-4">
          <StatCard label="SKUs" value={snapshot.item_count} />
          <StatCard label="Expected units" value={quantity(snapshot.total_quantity)} />
          <StatCard label="Catalogue" value={snapshot.catalogue_version ? `v${snapshot.catalogue_version}` : '—'} />
          <StatCard label="Frozen at" value={snapshot.snapshot_at ? formatDateTime(snapshot.snapshot_at) : '—'} />
        </div>
        <p className="break-all font-mono text-[11px] text-gray-500">sha256:{snapshot.checksum}</p>
        {snapshot.job_id && <p className="text-xs text-gray-500">Job reference: {snapshot.job_id}</p>}
        <Table headers={['Product', 'SKU', 'Batch', 'Expires', 'Expected', ...(showValue ? ['Unit cost', 'Value'] : [])]}>
          {snapshot.items.map((item) => (
            <tr key={item.id}>
              <td className="px-4 py-2 text-gray-700">{item.product_name}</td>
              <td className="px-4 py-2 font-mono text-xs text-gray-600">{item.sku}</td>
              <td className="px-4 py-2 text-gray-600">{item.batch_number ?? '—'}</td>
              <td className="px-4 py-2 text-gray-600">{item.expires_on ?? '—'}</td>
              <td className="px-4 py-2 font-mono text-xs text-gray-900">
                {quantity(item.expected_quantity)} {item.unit ?? ''}
              </td>
              {showValue && (
                <>
                  <td className="px-4 py-2 text-gray-600">{item.unit_cost !== null && item.unit_cost !== undefined ? formatKes(item.unit_cost) : '—'}</td>
                  <td className="px-4 py-2 text-gray-700">{item.value !== null && item.value !== undefined ? formatKes(item.value) : '—'}</td>
                </>
              )}
            </tr>
          ))}
        </Table>
      </div>
    </Card>
  )
}

function SnapshotsPanel({
  tenantId,
  canSnapshot,
  canViewCost,
}: {
  tenantId: string
  canSnapshot: boolean
  canViewCost: boolean
}) {
  const snapshots = useInventorySnapshots(tenantId)
  const cutoffs = useInventoryCutoffs(tenantId)
  const createSnapshot = useCreateInventorySnapshot(tenantId)
  const [values, setValues] = useState({ cutoff_id: '', stock_location_id: '', snapshot_at: '', job_id: '', reference: '', notes: '' })
  const [selected, setSelected] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)

  function submit(event: FormEvent) {
    event.preventDefault()
    setError(null)
    setNotice(null)
    if (!values.cutoff_id && !values.stock_location_id) {
      setError('Choose a cut-off or a stock location to snapshot.')
      return
    }
    createSnapshot.mutate(
      {
        cutoff_id: values.cutoff_id || undefined,
        stock_location_id: values.cutoff_id ? undefined : values.stock_location_id || undefined,
        snapshot_at: values.cutoff_id ? undefined : values.snapshot_at || undefined,
        job_id: values.job_id || undefined,
        reference: values.reference || undefined,
        notes: values.notes || undefined,
      },
      {
        onSuccess: (snapshot) => {
          setNotice(`Snapshot frozen with ${snapshot.item_count} SKUs and ${quantity(snapshot.total_quantity)} units.`)
          setSelected(snapshot.id)
          setValues((current) => ({ ...current, job_id: '', reference: '', notes: '' }))
        },
        onError: (err) => setError(describeError(err)),
      },
    )
  }

  return (
    <div className="space-y-5">
      {error && <Alert kind="error">{error}</Alert>}
      {notice && <Alert kind="success">{notice}</Alert>}

      {canSnapshot && (
        <Card title="Freeze a stock snapshot">
          <form onSubmit={submit} className="space-y-4">
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
              <Select
                label="Cut-off (recommended)"
                name="snapshot_cutoff"
                value={values.cutoff_id}
                onChange={(event) => setValues((current) => ({ ...current, cutoff_id: event.target.value }))}
              >
                <option value="">No cut-off</option>
                {(cutoffs.data ?? []).map((cutoff) => (
                  <option key={cutoff.id} value={cutoff.id}>
                    {cutoff.cut_off_at ? formatDateTime(cutoff.cut_off_at) : cutoff.id.slice(-6)} · {cutoff.status} ·{' '}
                    {cutoff.stock_location?.name ?? 'all locations'}
                  </option>
                ))}
              </Select>
              {!values.cutoff_id && (
                <>
                  <LocationSelect
                    label="Stock location"
                    name="snapshot_location"
                    value={values.stock_location_id}
                    onChange={(value) => setValues((current) => ({ ...current, stock_location_id: value }))}
                    emptyLabel="Choose a location"
                  />
                  <Field
                    label="Snapshot at"
                    name="snapshot_at"
                    type="datetime-local"
                    value={values.snapshot_at}
                    onChange={(event) => setValues((current) => ({ ...current, snapshot_at: event.target.value }))}
                    hint="Leave blank for now"
                  />
                </>
              )}
              <Field label="Job reference" name="snapshot_job" placeholder="e.g. JOB-1001" value={values.job_id} onChange={(event) => setValues((current) => ({ ...current, job_id: event.target.value }))} />
              <Field label="Reference" name="snapshot_reference" value={values.reference} onChange={(event) => setValues((current) => ({ ...current, reference: event.target.value }))} />
            </div>
            <Field label="Notes" name="snapshot_notes" value={values.notes} onChange={(event) => setValues((current) => ({ ...current, notes: event.target.value }))} />
            <Button type="submit" loading={createSnapshot.isPending}>
              Freeze snapshot
            </Button>
            <p className="text-xs text-gray-500">
              Snapshots use the movement ledger up to the cut-off. Repeating the same scope, catalogue version and cut-off returns the existing frozen snapshot.
            </p>
          </form>
        </Card>
      )}

      <Card title="Frozen snapshots">
        {snapshots.isLoading && <PageLoader label="Loading snapshots…" />}
        {snapshots.isError && <Alert kind="error">Snapshots could not be loaded.</Alert>}
        {snapshots.data && snapshots.data.length === 0 && (
          <EmptyState title="No snapshots yet" description="Freeze the expected stock before a count begins." />
        )}
        {snapshots.data && snapshots.data.length > 0 && (
          <Table headers={['Frozen at', 'Reference', 'Scope', 'Version', 'SKUs', 'Expected qty', 'Status', '']}>
            {snapshots.data.map((snapshot: InventorySnapshot) => (
              <tr key={snapshot.id} className="hover:bg-gray-50">
                <td className="px-4 py-3 text-gray-500">{snapshot.snapshot_at ? formatDateTime(snapshot.snapshot_at) : '—'}</td>
                <td className="px-4 py-3">
                  <p className="font-medium text-gray-900">{snapshot.reference ?? '—'}</p>
                  {snapshot.job_id && <p className="text-xs text-gray-500">{snapshot.job_id}</p>}
                </td>
                <td className="px-4 py-3 text-gray-600">{snapshot.stock_location?.name ?? snapshot.branch?.name ?? 'All'}</td>
                <td className="px-4 py-3 text-gray-600">{snapshot.catalogue_version ? `v${snapshot.catalogue_version}` : '—'}</td>
                <td className="px-4 py-3 text-gray-600">{snapshot.item_count}</td>
                <td className="px-4 py-3 font-mono text-xs text-gray-900">{quantity(snapshot.total_quantity)}</td>
                <td className="px-4 py-3">
                  <Badge tone={statusTone(snapshot.status)}>{snapshot.status}</Badge>
                </td>
                <td className="px-4 py-3 text-right">
                  <Button variant="secondary" onClick={() => setSelected(snapshot.id)}>
                    Open
                  </Button>
                </td>
              </tr>
            ))}
          </Table>
        )}
      </Card>

      {selected && <SnapshotDetail key={selected} tenantId={tenantId} snapshotId={selected} canViewCost={canViewCost} />}
    </div>
  )
}

function TrackingPanel({ tenantId }: { tenantId: string }) {
  const [batchFilters, setBatchFilters] = useState({ search: '', expiry: '', page: 1 })
  const [serialFilters, setSerialFilters] = useState({ search: '', status: '', page: 1 })
  const batches = useInventoryBatches(tenantId, {
    search: batchFilters.search || undefined,
    expiry: batchFilters.expiry || undefined,
    page: batchFilters.page,
  })
  const serials = useInventorySerials(tenantId, {
    search: serialFilters.search || undefined,
    status: serialFilters.status || undefined,
    page: serialFilters.page,
  })

  return (
    <div className="space-y-5">
      <Card title="Batch and expiry tracking">
        <div className="space-y-4">
          <div className="grid gap-3 sm:grid-cols-[1fr_220px]">
            <Field
              label="Search batches"
              name="batch_search"
              placeholder="Batch number, product or SKU"
              value={batchFilters.search}
              onChange={(event) => setBatchFilters((current) => ({ ...current, search: event.target.value, page: 1 }))}
            />
            <Select
              label="Expiry"
              name="batch_expiry"
              value={batchFilters.expiry}
              onChange={(event) => setBatchFilters((current) => ({ ...current, expiry: event.target.value, page: 1 }))}
            >
              <option value="">All batches</option>
              <option value="soon">Expiring within 30 days</option>
              <option value="expired">Expired</option>
            </Select>
          </div>
          {batches.isLoading && <PageLoader label="Loading batches…" />}
          {batches.isError && <Alert kind="error">Batches could not be loaded.</Alert>}
          {batches.data && batches.data.batches.length === 0 && (
            <EmptyState title="No batches tracked" description="Batch, expiry and combination tracked products appear here." />
          )}
          {batches.data && batches.data.batches.length > 0 && (
            <>
              <Table headers={['Batch', 'Product', 'SKU', 'Expires', 'Days left', 'Quantity', 'Status']}>
                {batches.data.batches.map((batch: InventoryBatch) => (
                  <tr key={batch.id} className="hover:bg-gray-50">
                    <td className="px-4 py-3 font-mono text-xs text-gray-700">{batch.batch_number}</td>
                    <td className="px-4 py-3 text-gray-900">{batch.product_name ?? '—'}</td>
                    <td className="px-4 py-3 font-mono text-xs text-gray-600">{batch.sku ?? '—'}</td>
                    <td className="px-4 py-3 text-gray-600">{batch.expires_on ?? '—'}</td>
                    <td className="px-4 py-3">
                      {batch.days_to_expiry === null || batch.days_to_expiry === undefined ? (
                        <span className="text-gray-500">—</span>
                      ) : (
                        <Badge tone={batch.days_to_expiry < 0 ? 'red' : batch.days_to_expiry <= 30 ? 'amber' : 'green'}>
                          {batch.days_to_expiry} days
                        </Badge>
                      )}
                    </td>
                    <td className="px-4 py-3 font-mono text-xs text-gray-900">
                      {quantity(batch.quantity)} {batch.unit ?? ''}
                    </td>
                    <td className="px-4 py-3">
                      <Badge tone={batch.status === 'active' ? 'green' : 'red'}>{batch.status}</Badge>
                    </td>
                  </tr>
                ))}
              </Table>
              <Pager meta={batches.data.pagination} onChange={(page) => setBatchFilters((current) => ({ ...current, page }))} />
            </>
          )}
        </div>
      </Card>

      <Card title="Serial tracking">
        <div className="space-y-4">
          <div className="grid gap-3 sm:grid-cols-[1fr_220px]">
            <Field
              label="Search serials"
              name="serial_search"
              placeholder="Serial number"
              value={serialFilters.search}
              onChange={(event) => setSerialFilters((current) => ({ ...current, search: event.target.value, page: 1 }))}
            />
            <Select
              label="Status"
              name="serial_status"
              value={serialFilters.status}
              onChange={(event) => setSerialFilters((current) => ({ ...current, status: event.target.value, page: 1 }))}
            >
              <option value="">All statuses</option>
              <option value="in_stock">In stock</option>
              <option value="sold">Sold</option>
              <option value="returned">Returned</option>
              <option value="scrapped">Scrapped</option>
              <option value="missing">Missing</option>
            </Select>
          </div>
          {serials.isLoading && <PageLoader label="Loading serials…" />}
          {serials.isError && <Alert kind="error">Serials could not be loaded.</Alert>}
          {serials.data && serials.data.serials.length === 0 && (
            <EmptyState title="No serials tracked" description="Register serial numbers through opening stock or movement lines." />
          )}
          {serials.data && serials.data.serials.length > 0 && (
            <>
              <Table headers={['Serial', 'Product', 'SKU', 'Batch', 'Location', 'Status', 'Updated']}>
                {serials.data.serials.map((serial: InventorySerial) => (
                  <tr key={serial.id} className="hover:bg-gray-50">
                    <td className="px-4 py-3 font-mono text-xs text-gray-700">{serial.serial_number}</td>
                    <td className="px-4 py-3 text-gray-900">{serial.product_name ?? '—'}</td>
                    <td className="px-4 py-3 font-mono text-xs text-gray-600">{serial.sku ?? '—'}</td>
                    <td className="px-4 py-3 text-gray-600">{serial.batch_number ?? '—'}</td>
                    <td className="px-4 py-3 text-gray-600">{serial.stock_location?.name ?? '—'}</td>
                    <td className="px-4 py-3">
                      <Badge tone={statusTone(serial.status)}>{serial.status.replaceAll('_', ' ')}</Badge>
                    </td>
                    <td className="px-4 py-3 text-gray-500">{serial.updated_at ? formatDateTime(serial.updated_at) : '—'}</td>
                  </tr>
                ))}
              </Table>
              <Pager meta={serials.data.pagination} onChange={(page) => setSerialFilters((current) => ({ ...current, page }))} />
            </>
          )}
        </div>
      </Card>
    </div>
  )
}

export function InventoryPage() {
  const { data: user, isLoading } = useCurrentUser()
  const tenantId = user?.current_tenant?.id
  const summary = useInventorySummary(tenantId)
  const [tab, setTab] = useState<Tab>('overview')

  if (isLoading) return <PageLoader />
  if (!tenantId) return <Alert kind="error">Select a business before managing inventory.</Alert>

  const permissions = user?.permissions ?? []
  if (!permissions.includes('inventory.view')) {
    return <Alert kind="error">You do not have access to inventory for this business.</Alert>
  }

  const canCreate = permissions.includes('inventory.movement.create')
  const canAdjust = permissions.includes('inventory.adjust')
  const canSnapshot = permissions.includes('inventory.snapshot.create')
  const canViewCost = summary.data?.can_view_cost ?? permissions.includes('catalogue.update')

  const tabs: { key: Tab; label: string }[] = [
    { key: 'overview', label: 'Overview' },
    { key: 'balances', label: 'Balances' },
    { key: 'movements', label: 'Movements' },
    { key: 'snapshots', label: 'Cut-offs & snapshots' },
    { key: 'tracking', label: 'Batches & serials' },
  ]

  return (
    <div className="mx-auto max-w-6xl space-y-6">
      <PageHeader
        title="Inventory"
        description="Balances, movement history, cut-offs and frozen stock snapshots behind every count."
        actions={
          summary.data ? (
            <Badge tone={summary.data.stats.open_cutoffs > 0 ? 'amber' : 'green'}>
              {summary.data.stats.open_cutoffs > 0 ? `${summary.data.stats.open_cutoffs} open cut-off(s)` : 'Ledger up to date'}
            </Badge>
          ) : undefined
        }
      />

      {summary.isError && <Alert kind="error">The inventory summary could not be loaded.</Alert>}

      <div className="flex flex-wrap gap-2 border-b border-gray-200 pb-2" role="tablist" aria-label="Inventory sections">
        {tabs.map((item) => (
          <button
            key={item.key}
            type="button"
            role="tab"
            aria-selected={tab === item.key}
            onClick={() => setTab(item.key)}
            className={`rounded-lg px-4 py-2 text-sm font-medium ${
              tab === item.key ? 'bg-brand-700 text-white' : 'text-gray-600 hover:bg-gray-100'
            }`}
          >
            {item.label}
          </button>
        ))}
      </div>

      {tab === 'overview' && <OverviewPanel tenantId={tenantId} onNavigate={setTab} />}
      {tab === 'balances' && <BalancesPanel tenantId={tenantId} canViewCost={canViewCost} />}
      {tab === 'movements' && <MovementsPanel tenantId={tenantId} canCreate={canCreate} canAdjust={canAdjust} />}
      {tab === 'snapshots' && (
        <>
          <CutoffsPanel tenantId={tenantId} canSnapshot={canSnapshot} />
          <SnapshotsPanel tenantId={tenantId} canSnapshot={canSnapshot} canViewCost={canViewCost} />
        </>
      )}
      {tab === 'tracking' && <TrackingPanel tenantId={tenantId} />}
    </div>
  )
}
