import { Link } from '@tanstack/react-router'
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
import { api, ApiError } from '../../lib/api/client'
import type { CatalogueImport, Product } from '../../lib/api/schemas'
import {
  useCatalogueImport,
  useCatalogueImports,
  useCatalogueSummary,
  useCommitCatalogueImport,
  useCreateProduct,
  useCreateProductCategory,
  useCreateUnit,
  useCreateUnitConversion,
  useCurrentUser,
  useDeclareCatalogue,
  useDiscontinueProduct,
  usePricingEstimate,
  useProductCategories,
  useProducts,
  useUnitDirectory,
  useUpdateImportMapping,
  useUploadCatalogueImport,
} from '../../lib/auth/hooks'
import { formatDateTime, formatKes } from '../../lib/formatting'

function describeError(error: unknown): string {
  if (error instanceof ApiError) {
    const first = Object.values(error.details)[0]?.[0]
    return first ?? error.message
  }
  if (error instanceof Error && error.message) return error.message
  return 'The change could not be saved. Try again.'
}

function statusTone(status: string): 'gray' | 'green' | 'red' | 'amber' {
  if (['active', 'ready', 'committed', 'issued'].includes(status)) return 'green'
  if (['failed', 'error', 'discontinued'].includes(status)) return 'red'
  if (['queued', 'processing', 'draft'].includes(status)) return 'amber'
  return 'gray'
}

type Tab = 'products' | 'imports' | 'units' | 'declaration'

const initialProductValues = {
  name: '',
  sku: '',
  variant_name: '',
  brand: '',
  category: '',
  barcode: '',
  base_unit: 'piece',
  packaging_unit: '',
  packaging_factor: '',
  cost_price: '',
}

const defaultDeclaration =
  'I declare that the product catalogue listed in this stock-taking account is complete, accurate and up to date as at the date of this declaration, and that every sellable SKU and barcode has been included.'

function ProductsPanel({
  tenantId,
  canCreate,
  canUpdate,
}: {
  tenantId: string
  canCreate: boolean
  canUpdate: boolean
}) {
  const [filters, setFilters] = useState({ search: '', status: '', page: 1 })
  const products = useProducts(tenantId, {
    search: filters.search || undefined,
    status: filters.status || undefined,
    page: filters.page,
  })
  const categories = useProductCategories(tenantId)
  const units = useUnitDirectory(tenantId)
  const createProduct = useCreateProduct(tenantId)
  const createCategory = useCreateProductCategory(tenantId)
  const discontinue = useDiscontinueProduct(tenantId)
  const [values, setValues] = useState(initialProductValues)
  const [categoryName, setCategoryName] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({})
  const [notice, setNotice] = useState<string | null>(null)

  function set(key: keyof typeof values, value: string) {
    setValues((current) => ({ ...current, [key]: value }))
  }

  function submit(event: FormEvent) {
    event.preventDefault()
    setError(null)
    setFieldErrors({})
    setNotice(null)
    createProduct.mutate(
      {
        name: values.name,
        sku: values.sku,
        variant_name: values.variant_name || null,
        brand: values.brand || null,
        category: values.category || null,
        barcode: values.barcode || null,
        base_unit: values.base_unit || 'piece',
        packaging_unit: values.packaging_unit || null,
        packaging_factor: values.packaging_factor ? Number(values.packaging_factor) : null,
        cost_price: values.cost_price ? Number(values.cost_price) : null,
        status: 'active',
      },
      {
        onSuccess: (product) => {
          setValues(initialProductValues)
          setNotice(`${product.name} added to the catalogue.`)
        },
        onError: (err) => {
          if (err instanceof ApiError) {
            const nextFieldErrors = Object.fromEntries(
              Object.entries(err.details).map(([field, messages]) => [field, messages[0]]),
            )
            setFieldErrors(nextFieldErrors)
            setError(Object.keys(nextFieldErrors).length > 0 ? err.message : describeError(err))
            return
          }
          setError(describeError(err))
        },
      },
    )
  }

  return (
    <div className="space-y-5">
      <Card
        title="Product catalogue"
        actions={
          <span className="text-xs text-gray-500">
            {products.data ? `${products.data.pagination.total} products` : ''}
          </span>
        }
      >
        <div className="space-y-4">
          {error && <Alert kind="error">{error}</Alert>}
          {notice && <Alert kind="success">{notice}</Alert>}

          <div className="grid gap-3 sm:grid-cols-[1fr_160px_auto]">
            <Field
              label="Search"
              name="product_search"
              placeholder="Name, brand, SKU or barcode"
              value={filters.search}
              onChange={(event) => setFilters((current) => ({ ...current, search: event.target.value, page: 1 }))}
            />
            <Select
              label="Status"
              name="product_status"
              value={filters.status}
              onChange={(event) => setFilters((current) => ({ ...current, status: event.target.value, page: 1 }))}
            >
              <option value="">All statuses</option>
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
              <option value="discontinued">Discontinued</option>
            </Select>
            <div className="flex items-end">
              <Button
                variant="secondary"
                onClick={() => setFilters({ search: '', status: '', page: 1 })}
              >
                Reset filters
              </Button>
            </div>
          </div>

          {products.isLoading && <PageLoader label="Loading products…" />}
          {products.isError && <Alert kind="error">Products could not be loaded.</Alert>}
          {products.data && products.data.products.length === 0 && (
            <EmptyState
              title="No products yet"
              description="Add a product manually or import your catalogue from a CSV or XLSX file."
            />
          )}
          {products.data && products.data.products.length > 0 && (
            <>
              <Table headers={['Product', 'SKU', 'Barcode', 'Category', 'Packaging', 'Status', '']}>
                {products.data.products.map((product: Product) => (
                  <tr key={product.id} className="hover:bg-gray-50">
                    <td className="px-4 py-3">
                      <p className="font-medium text-gray-900">{product.name}</p>
                      <p className="text-xs text-gray-500">{product.brand ?? 'No brand'}</p>
                    </td>
                    <td className="px-4 py-3 font-mono text-xs text-gray-600">{product.sku ?? '—'}</td>
                    <td className="px-4 py-3 font-mono text-xs text-gray-600">{product.barcode ?? '—'}</td>
                    <td className="px-4 py-3 text-gray-600">{product.category?.name ?? '—'}</td>
                    <td className="px-4 py-3 text-gray-600">
                      {product.packaging_unit
                        ? `1 ${product.packaging_unit} = ${product.packaging_factor ?? '?'} ${product.base_unit ?? ''}`
                        : product.base_unit ?? '—'}
                    </td>
                    <td className="px-4 py-3">
                      <Badge tone={statusTone(product.status)}>{product.status}</Badge>
                    </td>
                    <td className="px-4 py-3 text-right">
                      <div className="flex justify-end gap-2">
                        <Link
                          to="/business/catalogue/products/$productId"
                          params={{ productId: product.id }}
                          className="inline-flex items-center rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50"
                        >
                          Open
                        </Link>
                        {canUpdate && product.status !== 'discontinued' && (
                          <Button
                            variant="secondary"
                            loading={discontinue.isPending && discontinue.variables === product.id}
                            onClick={() => {
                              if (!window.confirm(`Mark ${product.name} as discontinued?`)) return
                              setError(null)
                              setNotice(null)
                              discontinue.mutate(product.id, {
                                onSuccess: () => setNotice(`${product.name} marked discontinued.`),
                                onError: (err) => setError(describeError(err)),
                              })
                            }}
                          >
                            Discontinue
                          </Button>
                        )}
                      </div>
                    </td>
                  </tr>
                ))}
              </Table>
              <div className="flex items-center justify-between text-xs text-gray-500">
                <span>
                  Page {products.data.pagination.current_page} of {products.data.pagination.last_page}
                </span>
                <div className="flex gap-2">
                  <Button
                    variant="secondary"
                    disabled={products.data.pagination.current_page <= 1}
                    onClick={() => setFilters((current) => ({ ...current, page: Math.max(1, current.page - 1) }))}
                  >
                    Previous
                  </Button>
                  <Button
                    variant="secondary"
                    disabled={products.data.pagination.current_page >= products.data.pagination.last_page}
                    onClick={() => setFilters((current) => ({ ...current, page: current.page + 1 }))}
                  >
                    Next
                  </Button>
                </div>
              </div>
            </>
          )}
        </div>
      </Card>

      {canCreate && (
        <Card title="Add a product">
          <form onSubmit={submit} className="space-y-4">
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
              <Field label="Product name" name="product_name" required value={values.name} error={fieldErrors.name} onChange={(e) => set('name', e.target.value)} />
              <Field label="SKU" name="product_sku" required value={values.sku} error={fieldErrors.sku} onChange={(e) => set('sku', e.target.value)} />
              <Field label="Variant name" name="product_variant" placeholder="e.g. 500 ml" value={values.variant_name} error={fieldErrors.variant_name} onChange={(e) => set('variant_name', e.target.value)} />
              <Field label="Brand" name="product_brand" value={values.brand} error={fieldErrors.brand} onChange={(e) => set('brand', e.target.value)} />
              <Field label="Category" name="product_category" list="product-category-options" value={values.category} error={fieldErrors.category} onChange={(e) => set('category', e.target.value)} hint="Existing or new category name" />
              <datalist id="product-category-options">
                {(categories.data ?? []).map((category) => (
                  <option key={category.id} value={category.name} />
                ))}
              </datalist>
              <Field label="Barcode" name="product_barcode" value={values.barcode} error={fieldErrors.barcode} onChange={(e) => set('barcode', e.target.value)} />
              <Select label="Base unit" name="product_base_unit" value={values.base_unit} onChange={(e) => set('base_unit', e.target.value)}>
                {(units.data?.units ?? []).map((unit) => (
                  <option key={unit.code} value={unit.code}>
                    {unit.name} ({unit.code})
                  </option>
                ))}
              </Select>
              <Field label="Packaging unit" name="product_packaging_unit" placeholder="e.g. carton" value={values.packaging_unit} error={fieldErrors.packaging_unit} onChange={(e) => set('packaging_unit', e.target.value)} />
              <Field label="Base units per pack" name="product_packaging_factor" type="number" min="0" step="any" value={values.packaging_factor} error={fieldErrors.packaging_factor} onChange={(e) => set('packaging_factor', e.target.value)} hint="Required when a packaging unit is set" />
              <Field label="Cost price (KSh)" name="product_cost_price" type="number" min="0" step="any" value={values.cost_price} error={fieldErrors.cost_price} onChange={(e) => set('cost_price', e.target.value)} hint="Hidden from stock-taking workers" />
            </div>
            <Button type="submit" loading={createProduct.isPending} disabled={createProduct.isPending}>
              Add product
            </Button>
          </form>
        </Card>
      )}

      {canUpdate && (
        <Card title="Categories">
          <div className="space-y-4">
            {categories.data && categories.data.length > 0 ? (
              <div className="flex flex-wrap gap-2">
                {categories.data.map((category) => (
                  <span key={category.id} className="rounded-full bg-gray-100 px-3 py-1 text-xs text-gray-700">
                    {category.name}
                    <span className="ml-1 text-gray-500">{category.products_count}</span>
                  </span>
                ))}
              </div>
            ) : (
              <p className="text-sm text-gray-500">No categories yet.</p>
            )}
            <form
              className="flex flex-wrap items-end gap-3"
              onSubmit={(event) => {
                event.preventDefault()
                setError(null)
                createCategory.mutate(
                  { name: categoryName },
                  {
                    onSuccess: () => setCategoryName(''),
                    onError: (err) => setError(describeError(err)),
                  },
                )
              }}
            >
              <Field label="New category" name="category_name" required value={categoryName} onChange={(e) => setCategoryName(e.target.value)} />
              <Button type="submit" variant="secondary" loading={createCategory.isPending}>
                Add category
              </Button>
            </form>
          </div>
        </Card>
      )}
    </div>
  )
}

function ImportDetail({ tenantId, importId }: { tenantId: string; importId: string }) {
  const detail = useCatalogueImport(tenantId, importId)
  const updateMapping = useUpdateImportMapping(tenantId)
  const commit = useCommitCatalogueImport(tenantId)
  const [mapping, setMapping] = useState<Record<string, string>>({})
  const [strategy, setStrategy] = useState<'skip' | 'update'>('skip')
  const [error, setError] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)
  const [downloading, setDownloading] = useState(false)
  const item = detail.data

  if (detail.isLoading) return <PageLoader label="Loading import…" />
  if (detail.isError) return <Alert kind="error">This import could not be loaded.</Alert>
  if (!item) return null

  const itemId = item.id
  const currentMapping = Object.keys(mapping).length > 0 ? mapping : item.mapping
  const locked = item.status === 'committed' || item.status === 'processing'

  function setMappingField(field: string, header: string) {
    setMapping({ ...currentMapping, [field]: header })
  }

  function submitMapping(event: FormEvent) {
    event.preventDefault()
    setError(null)
    setNotice(null)
    updateMapping.mutate(
      { importId: itemId, mapping: Object.fromEntries(Object.entries(currentMapping).filter(([, value]) => value)) },
      {
        onSuccess: () => setNotice('Column mapping saved and rows revalidated.'),
        onError: (err) => setError(describeError(err)),
      },
    )
  }

  async function downloadErrors() {
    setDownloading(true)
    setError(null)
    try {
      const blob = await api.download(`/tenants/${tenantId}/catalogue/imports/${itemId}/errors`)
      const url = URL.createObjectURL(blob)
      const link = document.createElement('a')
      link.href = url
      link.download = 'catalogue-import-errors.csv'
      link.click()
      URL.revokeObjectURL(url)
    } catch (err) {
      setError(describeError(err))
    } finally {
      setDownloading(false)
    }
  }

  return (
    <Card
      title={`Import — ${item.original_name}`}
      actions={<Badge tone={statusTone(item.status)}>{item.status}</Badge>}
    >
      <div className="space-y-5">
        {error && <Alert kind="error">{error}</Alert>}
        {notice && <Alert kind="success">{notice}</Alert>}
        {item.status === 'failed' && item.error_message && <Alert kind="error">{item.error_message}</Alert>}

        <div className="grid gap-3 sm:grid-cols-4">
          <StatCard label="Rows" value={item.total_rows} />
          <StatCard label="Valid" value={item.valid_rows} />
          <StatCard label="Duplicates" value={item.duplicate_rows} />
          <StatCard label="Errors" value={item.error_rows} />
        </div>

        {!locked && item.canonical_fields.length > 0 && (
          <form onSubmit={submitMapping} className="space-y-4 rounded-lg border border-gray-200 bg-gray-50 p-4">
            <h3 className="text-sm font-semibold text-gray-900">Map your columns</h3>
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
              {item.canonical_fields.map((canonical) => (
                <Select
                  key={canonical.field}
                  label={canonical.field === 'name' || canonical.field === 'sku' ? `${canonical.label} *` : canonical.label}
                  name={`mapping_${canonical.field}`}
                  value={currentMapping[canonical.field] ?? ''}
                  onChange={(event) => setMappingField(canonical.field, event.target.value)}
                >
                  <option value="">Not mapped</option>
                  {item.headers.map((header) => (
                    <option key={header} value={header}>
                      {header}
                    </option>
                  ))}
                </Select>
              ))}
            </div>
            <div className="flex flex-wrap gap-2">
              <Button type="submit" variant="secondary" loading={updateMapping.isPending}>
                Save mapping &amp; revalidate
              </Button>
              {item.error_rows > 0 && (
                <Button type="button" variant="secondary" loading={downloading} onClick={downloadErrors}>
                  Download error report
                </Button>
              )}
            </div>
          </form>
        )}

        {item.rows.length > 0 && (
          <div>
            <h3 className="mb-2 text-sm font-semibold text-gray-900">Preview</h3>
            <Table headers={['Row', 'Status', 'Name', 'SKU', 'Barcode', 'Issues']}>
              {item.rows.map((row) => (
                <tr key={row.row_number} className={row.status === 'error' ? 'bg-red-50' : undefined}>
                  <td className="px-4 py-2 text-gray-500">{row.row_number}</td>
                  <td className="px-4 py-2">
                    <Badge tone={statusTone(row.status === 'valid' ? 'active' : row.status === 'error' ? 'failed' : 'queued')}>
                      {row.status}
                    </Badge>
                  </td>
                  <td className="px-4 py-2 text-gray-700">{row.values.name ?? '—'}</td>
                  <td className="px-4 py-2 font-mono text-xs text-gray-600">{row.values.sku ?? '—'}</td>
                  <td className="px-4 py-2 font-mono text-xs text-gray-600">{row.values.barcode ?? '—'}</td>
                  <td className="px-4 py-2 text-xs text-red-700">{row.errors.join('; ') || '—'}</td>
                </tr>
              ))}
            </Table>
          </div>
        )}

        {!locked && item.valid_rows > 0 && (
          <div className="flex flex-wrap items-end gap-3 rounded-lg border border-dashed border-gray-300 p-4">
            <Select
              label="If a SKU or barcode already exists"
              name="duplicate_strategy"
              value={strategy}
              onChange={(event) => setStrategy(event.target.value as 'skip' | 'update')}
            >
              <option value="skip">Skip the imported row</option>
              <option value="update">Update the existing product</option>
            </Select>
            <Button
              loading={commit.isPending}
              onClick={() => {
                setError(null)
                setNotice(null)
                if (item.error_rows > 0 && item.partial_policy === 'all_or_nothing') {
                  setError('This import is all-or-nothing and still has invalid rows.')
                  return
                }
                commit.mutate(
                  { importId: item.id, strategy },
                  {
                    onSuccess: () => setNotice('Import committed and a new catalogue version published.'),
                    onError: (err) => setError(describeError(err)),
                  },
                )
              }}
            >
              Commit to catalogue
            </Button>
            {item.error_rows > 0 && item.partial_policy === 'partial' && (
              <p className="text-xs text-amber-700">
                {item.valid_rows} valid rows will be imported; error rows are skipped.
              </p>
            )}
          </div>
        )}

        {item.status === 'committed' && (
          <Alert kind="success">This import has been committed to the catalogue.</Alert>
        )}
      </div>
    </Card>
  )
}

function ImportsPanel({ tenantId, canImport }: { tenantId: string; canImport: boolean }) {
  const imports = useCatalogueImports(tenantId)
  const upload = useUploadCatalogueImport(tenantId)
  const [file, setFile] = useState<File | null>(null)
  const [partialPolicy, setPartialPolicy] = useState<'partial' | 'all_or_nothing'>('partial')
  const [selectedId, setSelectedId] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)

  function submit(event: FormEvent) {
    event.preventDefault()
    if (!file) return
    setError(null)
    setNotice(null)
    upload.mutate(
      { file, partialPolicy },
      {
        onSuccess: (result) => {
          setNotice(`${result.original_name} uploaded and queued for validation.`)
          setFile(null)
          setSelectedId(result.id)
        },
        onError: (err) => setError(describeError(err)),
      },
    )
  }

  return (
    <div className="space-y-5">
      {canImport && (
        <Card title="Upload a catalogue file">
          <form onSubmit={submit} className="space-y-4">
            {error && <Alert kind="error">{error}</Alert>}
            {notice && <Alert kind="success">{notice}</Alert>}
            <div className="grid gap-4 sm:grid-cols-[1fr_220px_auto] sm:items-end">
              <Field
                key={file?.name ?? 'empty-file'}
                label="CSV or XLSX file"
                name="catalogue_file"
                type="file"
                accept=".csv,.txt,.xlsx"
                onChange={(event) => setFile(event.target.files?.[0] ?? null)}
                hint="Maximum 10 MB. Include a product name and SKU column."
              />
              <Select
                label="Invalid rows"
                name="partial_policy"
                value={partialPolicy}
                onChange={(event) => setPartialPolicy(event.target.value as 'partial' | 'all_or_nothing')}
              >
                <option value="partial">Import valid rows and skip errors</option>
                <option value="all_or_nothing">All-or-nothing</option>
              </Select>
              <Button type="submit" loading={upload.isPending} disabled={!file}>
                Upload &amp; validate
              </Button>
            </div>
          </form>
        </Card>
      )}

      <Card title="Import history">
        <div className="space-y-4">
          {imports.isLoading && <PageLoader label="Loading imports…" />}
          {imports.isError && <Alert kind="error">Imports could not be loaded.</Alert>}
          {imports.data && imports.data.length === 0 && (
            <EmptyState title="No imports yet" description="Upload your first catalogue file to begin." />
          )}
          {imports.data && imports.data.length > 0 && (
            <Table headers={['File', 'Status', 'Rows', 'Valid', 'Errors', 'Uploaded', '']}>
              {imports.data.map((item: CatalogueImport) => (
                <tr key={item.id} className="hover:bg-gray-50">
                  <td className="px-4 py-3">
                    <p className="font-medium text-gray-900">{item.original_name}</p>
                    <p className="text-xs uppercase text-gray-500">{item.extension}</p>
                  </td>
                  <td className="px-4 py-3">
                    <Badge tone={statusTone(item.status)}>{item.status}</Badge>
                  </td>
                  <td className="px-4 py-3 text-gray-600">{item.total_rows}</td>
                  <td className="px-4 py-3 text-gray-600">{item.valid_rows}</td>
                  <td className="px-4 py-3 text-gray-600">{item.error_rows}</td>
                  <td className="px-4 py-3 text-gray-500">
                    {item.created_at ? formatDateTime(item.created_at) : '—'}
                  </td>
                  <td className="px-4 py-3 text-right">
                    <Button variant="secondary" onClick={() => setSelectedId(item.id)}>
                      Review
                    </Button>
                  </td>
                </tr>
              ))}
            </Table>
          )}
        </div>
      </Card>

      {selectedId && <ImportDetail key={selectedId} tenantId={tenantId} importId={selectedId} />}
    </div>
  )
}

function UnitsPanel({ tenantId, canUpdate }: { tenantId: string; canUpdate: boolean }) {
  const units = useUnitDirectory(tenantId)
  const createUnit = useCreateUnit(tenantId)
  const createConversion = useCreateUnitConversion(tenantId)
  const [unitValues, setUnitValues] = useState({ code: '', name: '', dimension: 'count', factor_to_base: '1' })
  const [conversion, setConversion] = useState({ from: '', to: '', factor: '' })
  const [error, setError] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)

  if (units.isLoading) return <PageLoader label="Loading units…" />
  if (units.isError) return <Alert kind="error">Units could not be loaded.</Alert>

  return (
    <div className="space-y-5">
      {error && <Alert kind="error">{error}</Alert>}
      {notice && <Alert kind="success">{notice}</Alert>}

      <Card title="Units of measure">
        <Table headers={['Code', 'Name', 'Dimension', 'Factor to base', 'Base', 'Custom']}>
          {(units.data?.units ?? []).map((unit) => (
            <tr key={unit.code} className="hover:bg-gray-50">
              <td className="px-4 py-2 font-mono text-xs text-gray-700">{unit.code}</td>
              <td className="px-4 py-2 text-gray-700">{unit.name}</td>
              <td className="px-4 py-2 capitalize text-gray-600">{unit.dimension}</td>
              <td className="px-4 py-2 text-gray-600">{unit.factor_to_base}</td>
              <td className="px-4 py-2 text-gray-600">{unit.is_base ? 'Yes' : 'No'}</td>
              <td className="px-4 py-2 text-gray-600">{unit.custom ? 'Yes' : 'No'}</td>
            </tr>
          ))}
        </Table>
      </Card>

      <Card title="Conversions">
        {units.data && units.data.conversions.length > 0 ? (
          <Table headers={['From', 'To', 'Factor']}>
            {units.data.conversions.map((item) => (
              <tr key={item.id}>
                <td className="px-4 py-2 font-mono text-xs text-gray-700">{item.from}</td>
                <td className="px-4 py-2 font-mono text-xs text-gray-700">{item.to}</td>
                <td className="px-4 py-2 text-gray-600">{item.factor}</td>
              </tr>
            ))}
          </Table>
        ) : (
          <p className="text-sm text-gray-500">No conversions recorded yet.</p>
        )}
      </Card>

      {canUpdate && (
        <div className="grid gap-4 lg:grid-cols-2">
          <Card title="Add a unit">
            <form
              className="grid gap-3 sm:grid-cols-2"
              onSubmit={(event) => {
                event.preventDefault()
                setError(null)
                setNotice(null)
                createUnit.mutate(
                  {
                    code: unitValues.code,
                    name: unitValues.name,
                    dimension: unitValues.dimension,
                    factor_to_base: Number(unitValues.factor_to_base || 1),
                  },
                  {
                    onSuccess: () => {
                      setNotice(`${unitValues.name} added.`)
                      setUnitValues({ code: '', name: '', dimension: 'count', factor_to_base: '1' })
                    },
                    onError: (err) => setError(describeError(err)),
                  },
                )
              }}
            >
              <Field label="Unit code" name="unit_code" required value={unitValues.code} onChange={(e) => setUnitValues((v) => ({ ...v, code: e.target.value }))} />
              <Field label="Unit name" name="unit_name" required value={unitValues.name} onChange={(e) => setUnitValues((v) => ({ ...v, name: e.target.value }))} />
              <Select label="Dimension" name="unit_dimension" value={unitValues.dimension} onChange={(e) => setUnitValues((v) => ({ ...v, dimension: e.target.value }))}>
                <option value="count">Count</option>
                <option value="weight">Weight</option>
                <option value="volume">Volume</option>
                <option value="length">Length</option>
                <option value="other">Other</option>
              </Select>
              <Field label="Factor to base" name="unit_factor" type="number" min="0" step="any" value={unitValues.factor_to_base} onChange={(e) => setUnitValues((v) => ({ ...v, factor_to_base: e.target.value }))} />
              <div className="sm:col-span-2">
                <Button type="submit" loading={createUnit.isPending}>
                  Add unit
                </Button>
              </div>
            </form>
          </Card>

          <Card title="Add a conversion">
            <form
              className="grid gap-3 sm:grid-cols-2"
              onSubmit={(event) => {
                event.preventDefault()
                setError(null)
                setNotice(null)
                createConversion.mutate(
                  { from: conversion.from, to: conversion.to, factor: Number(conversion.factor) },
                  {
                    onSuccess: () => {
                      setNotice('Conversion saved.')
                      setConversion({ from: '', to: '', factor: '' })
                    },
                    onError: (err) => setError(describeError(err)),
                  },
                )
              }}
            >
              <Field label="From unit" name="conversion_from" required placeholder="carton" value={conversion.from} onChange={(e) => setConversion((v) => ({ ...v, from: e.target.value }))} />
              <Field label="To unit" name="conversion_to" required placeholder="piece" value={conversion.to} onChange={(e) => setConversion((v) => ({ ...v, to: e.target.value }))} />
              <Field label="Factor" name="conversion_factor" required type="number" min="0" step="any" value={conversion.factor} onChange={(e) => setConversion((v) => ({ ...v, factor: e.target.value }))} hint="1 from-unit equals this many to-units" />
              <div className="sm:col-span-2">
                <Button type="submit" variant="secondary" loading={createConversion.isPending}>
                  Save conversion
                </Button>
              </div>
            </form>
          </Card>
        </div>
      )}
    </div>
  )
}

function DeclarationPanel({ tenantId, canConfirm }: { tenantId: string; canConfirm: boolean }) {
  const summary = useCatalogueSummary(tenantId)
  const estimate = usePricingEstimate(tenantId, summary.data?.product_count)
  const declare = useDeclareCatalogue(tenantId)
  const { data: user } = useCurrentUser()
  const [text, setText] = useState(defaultDeclaration)
  const [exceptions, setExceptions] = useState('')
  const [declarationVersion, setDeclarationVersion] = useState('1.0')
  const [confirmed, setConfirmed] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [result, setResult] = useState<{ declaration: { id: string; accepted_at: string | null }; invoice: { number: string; total: number; currency: string; product_count: number; status: string } | null } | null>(null)

  const data = summary.data

  if (summary.isLoading) return <PageLoader label="Loading declaration status…" />
  if (summary.isError) return <Alert kind="error">Catalogue readiness could not be loaded.</Alert>
  if (!data) return null

  return (
    <div className="space-y-5">
      <Card title="Readiness">
        <ul className="space-y-2 text-sm">
          <li className="flex items-center gap-2">
            <Badge tone={data.readiness.has_catalogue ? 'green' : 'red'}>
              {data.readiness.has_catalogue ? 'Catalogue created' : 'No catalogue version'}
            </Badge>
            <span className="text-gray-600">
              {data.current_version ? `Version ${data.current_version.version_number} · ${data.current_version.product_count} products` : 'Add or import products to create a version.'}
            </span>
          </li>
          <li className="flex items-center gap-2">
            <Badge tone={data.readiness.has_products ? 'green' : 'red'}>
              {data.readiness.has_products ? `${data.product_count} active products` : 'No active products'}
            </Badge>
            <Badge tone={data.readiness.confirmed ? 'green' : 'amber'}>
              {data.readiness.confirmed ? 'Declaration signed' : 'Declaration pending'}
            </Badge>
          </li>
        </ul>
        {data.readiness.reasons.length > 0 && (
          <ul className="mt-3 list-disc space-y-1 pl-5 text-xs text-gray-500">
            {data.readiness.reasons.map((reason) => (
              <li key={reason}>{reason}</li>
            ))}
          </ul>
        )}
      </Card>

      <div className="grid gap-4 lg:grid-cols-2">
        <Card title="Catalogue fee estimate">
          {estimate.isLoading && <PageLoader label="Calculating fee…" />}
          {estimate.data && (
            <div className="space-y-3 text-sm">
              <div className="flex justify-between">
                <span className="text-gray-500">Products counted</span>
                <span className="font-medium">{estimate.data.product_count}</span>
              </div>
              <div className="flex justify-between">
                <span className="text-gray-500">Pricing model</span>
                <span className="font-medium capitalize">{estimate.data.pricing_model}</span>
              </div>
              <div className="flex justify-between">
                <span className="text-gray-500">Subtotal</span>
                <span>{formatKes(estimate.data.subtotal)}</span>
              </div>
              <div className="flex justify-between">
                <span className="text-gray-500">Tax</span>
                <span>{formatKes(estimate.data.tax)}</span>
              </div>
              <div className="flex justify-between border-t border-gray-100 pt-2 text-base font-semibold">
                <span>Total</span>
                <span>{formatKes(estimate.data.total)}</span>
              </div>
              <p className="text-xs text-gray-500">{estimate.data.explanation}</p>
            </div>
          )}
        </Card>

        <Card title="Declaration">
          {data.confirmed && data.latest_declaration ? (
            <div className="space-y-2 text-sm text-gray-600">
              <Alert kind="success">This catalogue has been declared accurate.</Alert>
              <p>
                Signed by {data.latest_declaration.declared_by ?? 'a team member'} on{' '}
                {data.latest_declaration.accepted_at ? formatDateTime(data.latest_declaration.accepted_at) : '—'} for version{' '}
                {data.latest_declaration.version_number ?? data.current_version?.version_number}.
              </p>
              {data.invoice && (
                <p className="text-gray-700">
                  Invoice <span className="font-mono">{data.invoice.number}</span> · {formatKes(data.invoice.total)} · {data.invoice.status}
                </p>
              )}
            </div>
          ) : !canConfirm ? (
            <Alert kind="info">Only owners or managers with catalogue confirmation access can sign the declaration.</Alert>
          ) : (
            <form
              className="space-y-3"
              onSubmit={(event) => {
                event.preventDefault()
                if (!data.id) {
                  setError('Create a catalogue version first.')
                  return
                }
                setError(null)
                declare.mutate(
                  {
                    catalogueId: data.id,
                    declarationText: text,
                    exceptions,
                    declarationVersion,
                  },
                  {
                    onSuccess: (response) => {
                      setResult({
                        declaration: {
                          id: response.declaration.id,
                          accepted_at: response.declaration.accepted_at,
                        },
                        invoice: response.invoice,
                      })
                    },
                    onError: (err) => setError(describeError(err)),
                  },
                )
              }}
            >
              {error && <Alert kind="error">{error}</Alert>}
              {result && (
                <Alert kind="success">
                  Declaration recorded{result.invoice ? ` · invoice ${result.invoice.number} for ${formatKes(result.invoice.total)}` : ''}.
                </Alert>
              )}
              <div className="space-y-1">
                <label htmlFor="declaration_text" className="block text-sm font-medium text-gray-700">
                  Declaration statement
                </label>
                <textarea
                  id="declaration_text"
                  name="declaration_text"
                  rows={5}
                  required
                  minLength={20}
                  className="block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm shadow-sm focus:outline-2 focus:outline-brand-600"
                  value={text}
                  onChange={(event) => setText(event.target.value)}
                />
              </div>
              <Field label="Exceptions" name="declaration_exceptions" placeholder="Any known gaps or exclusions" value={exceptions} onChange={(event) => setExceptions(event.target.value)} />
              <Field label="Declaration version" name="declaration_version" value={declarationVersion} onChange={(event) => setDeclarationVersion(event.target.value)} />
              <label className="flex items-start gap-2 text-sm text-gray-700">
                <input
                  type="checkbox"
                  className="mt-1"
                  checked={confirmed}
                  onChange={(event) => setConfirmed(event.target.checked)}
                />
                <span>
                  I am authorised to declare this catalogue accurate on behalf of {user?.current_tenant?.name ?? 'this business'}.
                </span>
              </label>
              <Button type="submit" loading={declare.isPending} disabled={!confirmed || data.product_count === 0}>
                Sign declaration
              </Button>
            </form>
          )}
        </Card>
      </div>

      {data.latest_import && (
        <Card title="Latest import">
          <p className="text-sm text-gray-600">
            {data.latest_import.original_name} · <Badge tone={statusTone(data.latest_import.status)}>{data.latest_import.status}</Badge> ·{' '}
            {data.latest_import.valid_rows} valid, {data.latest_import.error_rows} errors
          </p>
        </Card>
      )}
    </div>
  )
}

export function CataloguePage() {
  const { data: user, isLoading } = useCurrentUser()
  const tenantId = user?.current_tenant?.id
  const summary = useCatalogueSummary(tenantId)
  const [tab, setTab] = useState<Tab>('products')

  if (isLoading) return <PageLoader />
  if (!tenantId) return <Alert kind="error">Select a business before managing its catalogue.</Alert>

  const permissions = user?.permissions ?? []
  const canView = permissions.includes('catalogue.view')
  const canCreate = permissions.includes('catalogue.create')
  const canUpdate = permissions.includes('catalogue.update')
  const canImport = permissions.includes('catalogue.import')
  const canConfirm = permissions.includes('catalogue.confirm')

  if (!canView) return <Alert kind="error">You do not have access to this catalogue.</Alert>

  const data = summary.data
  const tabs: { key: Tab; label: string }[] = [
    { key: 'products', label: 'Products' },
    { key: 'imports', label: 'Imports' },
    { key: 'units', label: 'Units' },
    { key: 'declaration', label: 'Declaration' },
  ]

  return (
    <div className="mx-auto max-w-6xl space-y-6">
      <PageHeader
        title="Catalogue"
        description="Import, validate and declare the products your stock take will count."
        actions={
          data ? (
            <Badge tone={data.readiness.ready ? 'green' : 'amber'}>
              {data.readiness.ready ? 'Catalogue ready' : 'Setup in progress'}
            </Badge>
          ) : undefined
        }
      />

      {summary.isError && <Alert kind="error">The catalogue summary could not be loaded.</Alert>}

      {data && (
        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
          <StatCard label="Active products" value={data.product_count} hint={`${data.categories_count} categories`} />
          <StatCard label="Barcodes" value={data.barcodes_count} hint="Across all sellable variants" />
          <StatCard label="Current version" value={data.current_version ? `v${data.current_version.version_number}` : '—'} hint={data.current_version ? `${data.current_version.product_count} products` : 'Created on first product'} />
          <StatCard label="Declaration" value={data.confirmed ? 'Signed' : 'Pending'} hint={data.confirmed_at ? formatDateTime(data.confirmed_at) : 'Confirm accuracy before requesting a count'} />
        </div>
      )}

      <div className="flex flex-wrap gap-2 border-b border-gray-200 pb-2" role="tablist" aria-label="Catalogue sections">
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

      {tab === 'products' && <ProductsPanel tenantId={tenantId} canCreate={canCreate} canUpdate={canUpdate} />}
      {tab === 'imports' && <ImportsPanel tenantId={tenantId} canImport={canImport} />}
      {tab === 'units' && <UnitsPanel tenantId={tenantId} canUpdate={canUpdate} />}
      {tab === 'declaration' && <DeclarationPanel tenantId={tenantId} canConfirm={canConfirm} />}
    </div>
  )
}
