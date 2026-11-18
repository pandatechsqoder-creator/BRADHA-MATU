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
import { ApiError } from '../../lib/api/client'
import type { Branch, Department, StockLocation, StockZone, Warehouse } from '../../lib/api/schemas'
import {
  useBranches,
  useCreateBranch,
  useCreateDepartment,
  useCreateStockBin,
  useCreateStockLocation,
  useCreateStockZone,
  useCreateWarehouse,
  useCurrentUser,
  useDeactivateBranch,
  useDeactivateDepartment,
  useDeactivateStockLocation,
  useDeactivateWarehouse,
  useDepartments,
  useStockBins,
  useStockLocations,
  useStockZones,
  useUpdateBranch,
  useUpdateDepartment,
  useUpdateStockLocation,
  useUpdateWarehouse,
  useWarehouses,
} from '../../lib/auth/hooks'

function describeError(error: unknown): string {
  return error instanceof ApiError ? error.message : 'The change could not be saved. Try again.'
}

function StatusBadge({ status }: { status: string }) {
  return <Badge tone={status === 'active' ? 'green' : 'gray'}>{status}</Badge>
}

const emptyBranch = { name: '', code: '', address: '', city: '', phone: '', email: '', timezone: '' }

function BranchForm({
  initial,
  submitting,
  submitLabel,
  onSubmit,
  onCancel,
}: {
  initial?: Partial<Branch>
  submitting: boolean
  submitLabel: string
  onSubmit: (input: typeof emptyBranch) => void
  onCancel?: () => void
}) {
  const [values, setValues] = useState({
    name: initial?.name ?? '',
    code: initial?.code ?? '',
    address: initial?.address ?? '',
    city: initial?.city ?? '',
    phone: initial?.phone ?? '',
    email: initial?.email ?? '',
    timezone: initial?.timezone ?? '',
  })

  function set(key: keyof typeof values, value: string) {
    setValues((current) => ({ ...current, [key]: value }))
  }

  function submit(event: FormEvent) {
    event.preventDefault()
    onSubmit(values)
  }

  return (
    <form onSubmit={submit} className="space-y-4">
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <Field label="Branch name" name="name" required value={values.name} onChange={(e) => set('name', e.target.value)} />
        <Field label="Code" name="code" value={values.code} onChange={(e) => set('code', e.target.value)} hint="e.g. NBO-CBD" />
        <Field label="City" name="city" value={values.city} onChange={(e) => set('city', e.target.value)} />
        <Field label="Address" name="address" value={values.address} onChange={(e) => set('address', e.target.value)} />
        <Field label="Phone" name="phone" value={values.phone} onChange={(e) => set('phone', e.target.value)} />
        <Field label="Email" name="email" type="email" value={values.email} onChange={(e) => set('email', e.target.value)} />
        <Field label="Timezone" name="timezone" value={values.timezone} onChange={(e) => set('timezone', e.target.value)} hint="Defaults to the organisation timezone." />
      </div>
      <div className="flex gap-2">
        <Button type="submit" loading={submitting}>
          {submitLabel}
        </Button>
        {onCancel && (
          <Button type="button" variant="secondary" onClick={onCancel}>
            Cancel
          </Button>
        )}
      </div>
    </form>
  )
}

function BranchesSection({
  tenantId,
  branches,
  canCreate,
  canUpdate,
}: {
  tenantId: string
  branches: Branch[]
  canCreate: boolean
  canUpdate: boolean
}) {
  const create = useCreateBranch(tenantId)
  const update = useUpdateBranch(tenantId)
  const deactivate = useDeactivateBranch(tenantId)
  const [editing, setEditing] = useState<Branch | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)

  return (
    <Card title="Branches">
      <div className="space-y-5">
        {error && <Alert kind="error">{error}</Alert>}
        {notice && <Alert kind="success">{notice}</Alert>}

        {branches.length === 0 ? (
          <EmptyState title="No branches yet" description="Add your first branch to start mapping stock locations." />
        ) : (
          <Table headers={['Branch', 'City', 'Departments', 'Warehouses', 'Locations', 'Status', '']}>
            {branches.map((branch) => (
              <tr key={branch.id} className="hover:bg-gray-50">
                <td className="px-4 py-3">
                  <div className="flex items-center gap-2">
                    <p className="font-medium text-gray-900">{branch.name}</p>
                    {branch.is_default && (
                      <Badge tone="green">{branch.name === 'Head Office' ? 'Default Head Office' : 'Default branch'}</Badge>
                    )}
                  </div>
                  <p className="text-xs text-gray-500">{branch.code ?? 'No code'}</p>
                </td>
                <td className="px-4 py-3 text-gray-600">{branch.city ?? '—'}</td>
                <td className="px-4 py-3 text-gray-600">{branch.departments_count}</td>
                <td className="px-4 py-3 text-gray-600">{branch.warehouses_count}</td>
                <td className="px-4 py-3 text-gray-600">{branch.locations_count}</td>
                <td className="px-4 py-3">
                  <StatusBadge status={branch.status} />
                </td>
                <td className="px-4 py-3 text-right">
                  {canUpdate && (
                    <div className="flex justify-end gap-2">
                      <Button variant="secondary" onClick={() => setEditing(branch)}>
                        Edit
                      </Button>
                      {!branch.is_default && branch.status === 'active' && (
                        <Button
                          variant="secondary"
                          loading={update.isPending && update.variables?.branchId === branch.id}
                          onClick={() => {
                            setError(null)
                            setNotice(null)
                            update.mutate(
                              { branchId: branch.id, input: { is_default: true } },
                              {
                                onSuccess: () => setNotice(`${branch.name} is now the default branch.`),
                                onError: (err) => setError(describeError(err)),
                              },
                            )
                          }}
                        >
                          Make default
                        </Button>
                      )}
                      {branch.status === 'active' && !branch.is_default && (
                        <Button
                          variant="danger"
                          loading={deactivate.isPending && deactivate.variables === branch.id}
                          onClick={() => {
                            if (!window.confirm(`Mark ${branch.name} as inactive?`)) return
                            setError(null)
                            setNotice(null)
                            deactivate.mutate(branch.id, {
                              onSuccess: () => setNotice(`${branch.name} is now inactive.`),
                              onError: (err) => setError(describeError(err)),
                            })
                          }}
                        >
                          Deactivate
                        </Button>
                      )}
                    </div>
                  )}
                </td>
              </tr>
            ))}
          </Table>
        )}

        {editing && canUpdate && (
          <div className="rounded-lg border border-gray-200 bg-gray-50 p-4">
            <h3 className="mb-3 text-sm font-semibold text-gray-900">Edit {editing.name}</h3>
            <BranchForm
              key={editing.id}
              initial={editing}
              submitting={update.isPending}
              submitLabel="Save branch"
              onCancel={() => setEditing(null)}
              onSubmit={(values) => {
                setError(null)
                update.mutate(
                  { branchId: editing.id, input: values },
                  {
                    onSuccess: () => {
                      setEditing(null)
                      setNotice('Branch updated.')
                    },
                    onError: (err) => setError(describeError(err)),
                  },
                )
              }}
            />
          </div>
        )}

        {canCreate && (
          <div className="rounded-lg border border-dashed border-gray-300 p-4">
            <h3 className="mb-3 text-sm font-semibold text-gray-900">Add a branch</h3>
            <BranchForm
              key={`branch-form-${branches.length}`}
              submitting={create.isPending}
              submitLabel="Add branch"
              onSubmit={(values) => {
                setError(null)
                setNotice(null)
                create.mutate(values, {
                  onSuccess: (branch) => setNotice(`${branch.name} added.`),
                  onError: (err) => setError(describeError(err)),
                })
              }}
            />
          </div>
        )}

        {!canCreate && !canUpdate && (
          <Alert kind="info">You have read-only access to the organisation structure.</Alert>
        )}
      </div>
    </Card>
  )
}

function DepartmentEditor({
  department,
  branches,
  submitting,
  onSave,
  onCancel,
}: {
  department: Department
  branches: Branch[]
  submitting: boolean
  onSave: (input: { name: string; code: string | null; branch_id: string | null; status: string }) => void
  onCancel: () => void
}) {
  const [values, setValues] = useState({
    name: department.name,
    code: department.code ?? '',
    branch_id: department.branch_id ?? '',
    status: department.status,
  })

  return (
    <form
      className="grid gap-3 rounded-lg border border-gray-200 bg-gray-50 p-4 sm:grid-cols-2 lg:grid-cols-5 lg:items-end"
      onSubmit={(event) => {
        event.preventDefault()
        onSave({
          name: values.name,
          code: values.code || null,
          branch_id: values.branch_id || null,
          status: values.status,
        })
      }}
    >
      <Field label="Department name" name="edit_department_name" required value={values.name} onChange={(event) => setValues((current) => ({ ...current, name: event.target.value }))} />
      <Field label="Code" name="edit_department_code" value={values.code} onChange={(event) => setValues((current) => ({ ...current, code: event.target.value }))} />
      <Select label="Branch" name="edit_department_branch" value={values.branch_id} onChange={(event) => setValues((current) => ({ ...current, branch_id: event.target.value }))}>
        <option value="">All branches</option>
        {branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}
      </Select>
      <Select label="Status" name="edit_department_status" value={values.status} onChange={(event) => setValues((current) => ({ ...current, status: event.target.value }))}>
        <option value="active">Active</option>
        <option value="inactive">Inactive</option>
      </Select>
      <div className="flex gap-2">
        <Button type="submit" loading={submitting}>Save</Button>
        <Button type="button" variant="secondary" onClick={onCancel}>Cancel</Button>
      </div>
    </form>
  )
}

function DepartmentsSection({
  tenantId,
  departments,
  branches,
  canCreate,
  canUpdate,
}: {
  tenantId: string
  departments: Department[]
  branches: Branch[]
  canCreate: boolean
  canUpdate: boolean
}) {
  const create = useCreateDepartment(tenantId)
  const update = useUpdateDepartment(tenantId)
  const deactivate = useDeactivateDepartment(tenantId)
  const [values, setValues] = useState({ name: '', code: '', branch_id: '' })
  const [editing, setEditing] = useState<Department | null>(null)
  const [error, setError] = useState<string | null>(null)

  return (
    <Card title="Departments">
      <div className="space-y-5">
        {error && <Alert kind="error">{error}</Alert>}

        {departments.length === 0 ? (
          <EmptyState title="No departments" description="Group stock-count scopes by department or warehouse team." />
        ) : (
          <Table headers={['Department', 'Code', 'Branch', 'Status', '']}>
            {departments.map((department) => (
              <tr key={department.id} className="hover:bg-gray-50">
                <td className="px-4 py-3 font-medium text-gray-900">{department.name}</td>
                <td className="px-4 py-3 text-gray-600">{department.code ?? '—'}</td>
                <td className="px-4 py-3 text-gray-600">{department.branch_name ?? 'All branches'}</td>
                <td className="px-4 py-3">
                  <StatusBadge status={department.status} />
                </td>
                <td className="px-4 py-3 text-right">
                  {canUpdate && (
                    <div className="flex justify-end gap-2">
                      <Button variant="secondary" onClick={() => setEditing(department)}>Edit</Button>
                      {department.status === 'active' && (
                    <Button
                      variant="secondary"
                      onClick={() => {
                        if (!window.confirm(`Deactivate ${department.name}?`)) return
                        setError(null)
                        deactivate.mutate(department.id, {
                          onError: (err) => setError(describeError(err)),
                        })
                      }}
                    >
                      Deactivate
                    </Button>
                      )}
                    </div>
                  )}
                </td>
              </tr>
            ))}
          </Table>
        )}

        {editing && canUpdate && (
          <DepartmentEditor
            key={editing.id}
            department={editing}
            branches={branches}
            submitting={update.isPending}
            onCancel={() => setEditing(null)}
            onSave={(input) => {
              setError(null)
              update.mutate(
                { departmentId: editing.id, input },
                {
                  onSuccess: () => setEditing(null),
                  onError: (err) => setError(describeError(err)),
                },
              )
            }}
          />
        )}

        {canCreate && (
          <form
            className="grid gap-3 rounded-lg border border-dashed border-gray-300 p-4 sm:grid-cols-[1fr_160px_200px_auto] sm:items-end"
            onSubmit={(event) => {
              event.preventDefault()
              setError(null)
              create.mutate(
                {
                  name: values.name,
                  code: values.code || null,
                  branch_id: values.branch_id || null,
                },
                {
                  onSuccess: () => setValues({ name: '', code: '', branch_id: '' }),
                  onError: (err) => setError(describeError(err)),
                },
              )
            }}
          >
            <Field label="Department name" name="department_name" required value={values.name} onChange={(e) => setValues((v) => ({ ...v, name: e.target.value }))} />
            <Field label="Code" name="department_code" value={values.code} onChange={(e) => setValues((v) => ({ ...v, code: e.target.value }))} />
            <Select label="Branch" name="department_branch" value={values.branch_id} onChange={(e) => setValues((v) => ({ ...v, branch_id: e.target.value }))}>
              <option value="">All branches</option>
              {branches.map((branch) => (
                <option key={branch.id} value={branch.id}>
                  {branch.name}
                </option>
              ))}
            </Select>
            <Button type="submit" loading={create.isPending}>
              Add department
            </Button>
          </form>
        )}
      </div>
    </Card>
  )
}

function WarehouseEditor({
  warehouse,
  branches,
  submitting,
  onSave,
  onCancel,
}: {
  warehouse: Warehouse
  branches: Branch[]
  submitting: boolean
  onSave: (input: {
    name: string
    code: string | null
    branch_id: string | null
    address: string | null
    status: string
  }) => void
  onCancel: () => void
}) {
  const [values, setValues] = useState({
    name: warehouse.name,
    code: warehouse.code ?? '',
    branch_id: warehouse.branch_id ?? '',
    address: warehouse.address ?? '',
    status: warehouse.status,
  })

  return (
    <form
      className="grid gap-3 rounded-lg border border-gray-200 bg-gray-50 p-4 sm:grid-cols-2 lg:grid-cols-6 lg:items-end"
      onSubmit={(event) => {
        event.preventDefault()
        onSave({
          name: values.name,
          code: values.code || null,
          branch_id: values.branch_id || null,
          address: values.address || null,
          status: values.status,
        })
      }}
    >
      <Field label="Warehouse name" name="edit_warehouse_name" required value={values.name} onChange={(event) => setValues((current) => ({ ...current, name: event.target.value }))} />
      <Field label="Code" name="edit_warehouse_code" value={values.code} onChange={(event) => setValues((current) => ({ ...current, code: event.target.value }))} />
      <Field label="Address" name="edit_warehouse_address" value={values.address} onChange={(event) => setValues((current) => ({ ...current, address: event.target.value }))} />
      <Select label="Branch" name="edit_warehouse_branch" value={values.branch_id} onChange={(event) => setValues((current) => ({ ...current, branch_id: event.target.value }))}>
        <option value="">Unassigned</option>
        {branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}
      </Select>
      <Select label="Status" name="edit_warehouse_status" value={values.status} onChange={(event) => setValues((current) => ({ ...current, status: event.target.value }))}>
        <option value="active">Active</option>
        <option value="inactive">Inactive</option>
      </Select>
      <div className="flex gap-2">
        <Button type="submit" loading={submitting}>Save</Button>
        <Button type="button" variant="secondary" onClick={onCancel}>Cancel</Button>
      </div>
    </form>
  )
}

function WarehousesSection({
  tenantId,
  warehouses,
  branches,
  canCreate,
  canUpdate,
}: {
  tenantId: string
  warehouses: Warehouse[]
  branches: Branch[]
  canCreate: boolean
  canUpdate: boolean
}) {
  const create = useCreateWarehouse(tenantId)
  const update = useUpdateWarehouse(tenantId)
  const deactivate = useDeactivateWarehouse(tenantId)
  const [values, setValues] = useState({ name: '', code: '', branch_id: '', address: '' })
  const [editing, setEditing] = useState<Warehouse | null>(null)
  const [error, setError] = useState<string | null>(null)

  return (
    <Card title="Warehouses">
      <div className="space-y-5">
        {error && <Alert kind="error">{error}</Alert>}

        {warehouses.length === 0 ? (
          <EmptyState title="No warehouses" description="Register storage facilities that hold countable stock." />
        ) : (
          <Table headers={['Warehouse', 'Code', 'Branch', 'Locations', 'Status', '']}>
            {warehouses.map((warehouse) => (
              <tr key={warehouse.id} className="hover:bg-gray-50">
                <td className="px-4 py-3 font-medium text-gray-900">{warehouse.name}</td>
                <td className="px-4 py-3 text-gray-600">{warehouse.code ?? '—'}</td>
                <td className="px-4 py-3 text-gray-600">{warehouse.branch_name ?? 'Unassigned'}</td>
                <td className="px-4 py-3 text-gray-600">{warehouse.locations_count}</td>
                <td className="px-4 py-3">
                  <StatusBadge status={warehouse.status} />
                </td>
                <td className="px-4 py-3 text-right">
                  {canUpdate && (
                    <div className="flex justify-end gap-2">
                      <Button variant="secondary" onClick={() => setEditing(warehouse)}>Edit</Button>
                      {warehouse.status === 'active' && (
                    <Button
                      variant="secondary"
                      onClick={() => {
                        if (!window.confirm(`Deactivate ${warehouse.name}?`)) return
                        setError(null)
                        deactivate.mutate(warehouse.id, {
                          onError: (err) => setError(describeError(err)),
                        })
                      }}
                    >
                      Deactivate
                    </Button>
                      )}
                    </div>
                  )}
                </td>
              </tr>
            ))}
          </Table>
        )}

        {editing && canUpdate && (
          <WarehouseEditor
            key={editing.id}
            warehouse={editing}
            branches={branches}
            submitting={update.isPending}
            onCancel={() => setEditing(null)}
            onSave={(input) => {
              setError(null)
              update.mutate(
                { warehouseId: editing.id, input },
                {
                  onSuccess: () => setEditing(null),
                  onError: (err) => setError(describeError(err)),
                },
              )
            }}
          />
        )}

        {canCreate && (
          <form
            className="grid gap-3 rounded-lg border border-dashed border-gray-300 p-4 sm:grid-cols-[1fr_140px_200px_auto] sm:items-end"
            onSubmit={(event) => {
              event.preventDefault()
              setError(null)
              create.mutate(
                {
                  name: values.name,
                  code: values.code || null,
                  branch_id: values.branch_id || null,
                  address: values.address || null,
                },
                {
                  onSuccess: () => setValues({ name: '', code: '', branch_id: '', address: '' }),
                  onError: (err) => setError(describeError(err)),
                },
              )
            }}
          >
            <Field label="Warehouse name" name="warehouse_name" required value={values.name} onChange={(e) => setValues((v) => ({ ...v, name: e.target.value }))} />
            <Field label="Code" name="warehouse_code" value={values.code} onChange={(e) => setValues((v) => ({ ...v, code: e.target.value }))} />
            <Select label="Branch" name="warehouse_branch" value={values.branch_id} onChange={(e) => setValues((v) => ({ ...v, branch_id: e.target.value }))}>
              <option value="">Unassigned</option>
              {branches.map((branch) => (
                <option key={branch.id} value={branch.id}>
                  {branch.name}
                </option>
              ))}
            </Select>
            <Button type="submit" loading={create.isPending}>
              Add warehouse
            </Button>
          </form>
        )}
      </div>
    </Card>
  )
}

function ZonesAndBins({
  tenantId,
  location,
  canUpdate,
}: {
  tenantId: string
  location: StockLocation
  canUpdate: boolean
}) {
  const zones = useStockZones(tenantId, location.id)
  const [selectedZone, setSelectedZone] = useState<StockZone | null>(null)
  const createZone = useCreateStockZone(tenantId, location.id)
  const createBin = useCreateStockBin(tenantId, selectedZone?.id)
  const bins = useStockBins(tenantId, selectedZone?.id)
  const [zoneName, setZoneName] = useState('')
  const [binName, setBinName] = useState('')
  const [error, setError] = useState<string | null>(null)

  return (
    <Card title={`Zones and bins — ${location.name}`}>
      <div className="space-y-4">
        {error && <Alert kind="error">{error}</Alert>}
        {zones.isLoading && <PageLoader label="Loading zones…" />}
        {zones.data && zones.data.length === 0 && (
          <EmptyState title="No zones yet" description="Zones split a location into countable areas. Bins sit inside a zone." />
        )}
        {zones.data && zones.data.length > 0 && (
          <div className="flex flex-wrap gap-2">
            {zones.data.map((zone) => (
              <button
                key={zone.id}
                type="button"
                onClick={() => setSelectedZone(zone)}
                className={`rounded-lg border px-3 py-2 text-sm ${
                  selectedZone?.id === zone.id
                    ? 'border-brand-600 bg-brand-50 text-brand-800'
                    : 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50'
                }`}
              >
                {zone.name}
                <span className="ml-2 text-xs text-gray-500">{zone.bins_count} bins</span>
              </button>
            ))}
          </div>
        )}

        {canUpdate && (
          <form
            className="flex flex-wrap items-end gap-3"
            onSubmit={(event) => {
              event.preventDefault()
              setError(null)
              createZone.mutate(
                { name: zoneName },
                {
                  onSuccess: () => setZoneName(''),
                  onError: (err) => setError(describeError(err)),
                },
              )
            }}
          >
            <Field label="New zone" name="zone_name" required value={zoneName} onChange={(e) => setZoneName(e.target.value)} />
            <Button type="submit" loading={createZone.isPending}>
              Add zone
            </Button>
          </form>
        )}

        {selectedZone && (
          <div className="rounded-lg border border-gray-200 bg-gray-50 p-4">
            <h4 className="mb-3 text-sm font-semibold text-gray-900">Bins in {selectedZone.name}</h4>
            {bins.isLoading && <PageLoader label="Loading bins…" />}
            {bins.data && bins.data.length === 0 && <p className="text-sm text-gray-500">No bins recorded yet.</p>}
            {bins.data && bins.data.length > 0 && (
              <ul className="mb-3 flex flex-wrap gap-2">
                {bins.data.map((bin) => (
                  <li key={bin.id} className="rounded-full bg-white px-3 py-1 text-xs text-gray-700 shadow-sm">
                    {bin.name}
                    {bin.code ? <span className="ml-1 text-gray-500">({bin.code})</span> : null}
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
                  createBin.mutate(
                    { name: binName },
                    {
                      onSuccess: () => setBinName(''),
                      onError: (err) => setError(describeError(err)),
                    },
                  )
                }}
              >
                <Field label="New bin" name="bin_name" required value={binName} onChange={(e) => setBinName(e.target.value)} />
                <Button type="submit" loading={createBin.isPending}>
                  Add bin
                </Button>
              </form>
            )}
          </div>
        )}
      </div>
    </Card>
  )
}

function LocationsSection({
  tenantId,
  locations,
  warehouses,
  branches,
  canCreate,
  canUpdate,
}: {
  tenantId: string
  locations: StockLocation[]
  warehouses: Warehouse[]
  branches: Branch[]
  canCreate: boolean
  canUpdate: boolean
}) {
  const create = useCreateStockLocation(tenantId)
  const update = useUpdateStockLocation(tenantId)
  const deactivate = useDeactivateStockLocation(tenantId)
  const [selected, setSelected] = useState<StockLocation | null>(null)
  const [values, setValues] = useState({ name: '', code: '', type: 'zone', warehouse_id: '', branch_id: '' })
  const [error, setError] = useState<string | null>(null)

  return (
    <div className="space-y-5">
      <Card title="Stock locations">
        <div className="space-y-5">
          {error && <Alert kind="error">{error}</Alert>}

          {locations.length === 0 ? (
            <EmptyState title="No stock locations" description="Locations are the physical places counters visit: aisles, shelves, floors or bins." />
          ) : (
            <Table headers={['Location', 'Type', 'Branch', 'Warehouse', 'Zones', 'Status', '']}>
              {locations.map((location) => (
                <tr key={location.id} className="hover:bg-gray-50">
                  <td className="px-4 py-3">
                    <div className="flex items-center gap-2">
                      <p className="font-medium text-gray-900">{location.name}</p>
                      {location.is_default && <Badge tone="green">Default stock location</Badge>}
                    </div>
                    <p className="text-xs text-gray-500">{location.code ?? 'No code'}</p>
                  </td>
                  <td className="px-4 py-3 capitalize text-gray-600">{location.type}</td>
                  <td className="px-4 py-3 text-gray-600">{location.branch_name ?? '—'}</td>
                  <td className="px-4 py-3 text-gray-600">{location.warehouse_name ?? '—'}</td>
                  <td className="px-4 py-3 text-gray-600">{location.zones_count}</td>
                  <td className="px-4 py-3">
                    <StatusBadge status={location.status} />
                  </td>
                  <td className="px-4 py-3 text-right">
                    <div className="flex justify-end gap-2">
                      <Button variant="secondary" onClick={() => setSelected(location)}>
                        Zones
                      </Button>
                      {canUpdate && location.status === 'active' && !location.is_default && location.branch_id && (
                        <Button
                          variant="secondary"
                          loading={update.isPending && update.variables?.locationId === location.id}
                          onClick={() => {
                            setError(null)
                            update.mutate(
                              { locationId: location.id, input: { is_default: true } },
                              { onError: (err) => setError(describeError(err)) },
                            )
                          }}
                        >
                          Make default
                        </Button>
                      )}
                      {canUpdate && location.status === 'active' && !location.is_default && (
                        <Button
                          variant="secondary"
                          onClick={() => {
                            if (!window.confirm(`Deactivate ${location.name}?`)) return
                            setError(null)
                            deactivate.mutate(location.id, {
                              onError: (err) => setError(describeError(err)),
                            })
                          }}
                        >
                          Deactivate
                        </Button>
                      )}
                    </div>
                  </td>
                </tr>
              ))}
            </Table>
          )}

          {canCreate && (
            <form
              className="grid gap-3 rounded-lg border border-dashed border-gray-300 p-4 sm:grid-cols-2 lg:grid-cols-[1fr_130px_140px_200px_auto] lg:items-end"
              onSubmit={(event) => {
                event.preventDefault()
                setError(null)
                create.mutate(
                  {
                    name: values.name,
                    code: values.code || null,
                    type: values.type,
                    warehouse_id: values.warehouse_id || null,
                    branch_id: values.branch_id || null,
                  },
                  {
                    onSuccess: () => setValues({ name: '', code: '', type: 'zone', warehouse_id: '', branch_id: '' }),
                    onError: (err) => setError(describeError(err)),
                  },
                )
              }}
            >
              <Field label="Location name" name="location_name" required value={values.name} onChange={(e) => setValues((v) => ({ ...v, name: e.target.value }))} />
              <Field label="Code" name="location_code" value={values.code} onChange={(e) => setValues((v) => ({ ...v, code: e.target.value }))} />
              <Select label="Type" name="location_type" value={values.type} onChange={(e) => setValues((v) => ({ ...v, type: e.target.value }))}>
                <option value="zone">Zone</option>
                <option value="aisle">Aisle</option>
                <option value="shelf">Shelf</option>
                <option value="floor">Floor</option>
                <option value="bin">Bin</option>
              </Select>
              <Select label="Warehouse" name="location_warehouse" value={values.warehouse_id} onChange={(e) => setValues((v) => ({ ...v, warehouse_id: e.target.value }))}>
                <option value="">No warehouse</option>
                {warehouses.map((warehouse) => (
                  <option key={warehouse.id} value={warehouse.id}>
                    {warehouse.name}
                  </option>
                ))}
              </Select>
              <Select label="Branch" name="location_branch" value={values.branch_id} onChange={(e) => setValues((v) => ({ ...v, branch_id: e.target.value }))}>
                <option value="">From warehouse</option>
                {branches.map((branch) => (
                  <option key={branch.id} value={branch.id}>
                    {branch.name}
                  </option>
                ))}
              </Select>
              <Button type="submit" loading={create.isPending}>
                Add location
              </Button>
            </form>
          )}
        </div>
      </Card>

      {selected && <ZonesAndBins tenantId={tenantId} location={selected} canUpdate={canUpdate} />}
    </div>
  )
}

export function LocationsPage() {
  const { data: user, isLoading } = useCurrentUser()
  const tenantId = user?.current_tenant?.id
  const branches = useBranches(tenantId)
  const departments = useDepartments(tenantId)
  const warehouses = useWarehouses(tenantId)
  const locations = useStockLocations(tenantId)

  if (isLoading) return <PageLoader />
  if (!tenantId) return <Alert kind="error">Select a business before managing locations.</Alert>

  const permissions = user?.permissions ?? []
  const canCreate = permissions.includes('branch.create')
  const canUpdate = permissions.includes('branch.update')

  return (
    <div className="mx-auto max-w-6xl space-y-6">
      <PageHeader
        title="Branches & locations"
        description="Map the physical structure of your business so stock takes target the right shelves."
      />

      {branches.isError && <Alert kind="error">Could not load branches.</Alert>}
      {departments.isError && <Alert kind="error">Could not load departments.</Alert>}
      {warehouses.isError && <Alert kind="error">Could not load warehouses.</Alert>}
      {locations.isError && <Alert kind="error">Could not load stock locations.</Alert>}

      <BranchesSection
        tenantId={tenantId}
        branches={branches.data ?? []}
        canCreate={canCreate}
        canUpdate={canUpdate}
      />

      <DepartmentsSection
        tenantId={tenantId}
        departments={departments.data ?? []}
        branches={branches.data ?? []}
        canCreate={canCreate}
        canUpdate={canUpdate}
      />

      <WarehousesSection
        tenantId={tenantId}
        warehouses={warehouses.data ?? []}
        branches={branches.data ?? []}
        canCreate={canCreate}
        canUpdate={canUpdate}
      />

      <LocationsSection
        tenantId={tenantId}
        locations={locations.data ?? []}
        warehouses={warehouses.data ?? []}
        branches={branches.data ?? []}
        canCreate={canCreate}
        canUpdate={canUpdate}
      />
    </div>
  )
}
