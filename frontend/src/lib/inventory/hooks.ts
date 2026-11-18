import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api } from '../api/client'
import { paginatedMetaSchema, type PaginatedMeta } from '../api/schemas'
import {
  inventoryBalanceSchema,
  inventoryBatchSchema,
  inventoryCutoffSchema,
  inventoryMovementSchema,
  inventorySerialSchema,
  inventorySnapshotSchema,
  inventorySummarySchema,
  movementImportResultSchema,
  type InventoryBalance,
  type InventoryBatch,
  type InventoryCutoff,
  type InventoryMovement,
  type InventorySerial,
  type InventorySnapshot,
  type InventorySummary,
  type MovementImportResult,
  type MovementInput,
  type MovementType,
} from '../api/inventory'

function queryString(filters: object): string {
  const query = new URLSearchParams()
  for (const [key, value] of Object.entries(filters)) {
    if (value === undefined || value === '' || value === false) continue
    query.set(key, value === true ? '1' : String(value))
  }
  const result = query.toString()
  return result ? `?${result}` : ''
}

function pagination(meta: { current_page?: number; per_page?: number; total?: number; last_page?: number } | undefined, size: number): PaginatedMeta {
  return paginatedMetaSchema.parse({
    current_page: meta?.current_page ?? 1,
    per_page: meta?.per_page ?? size,
    total: meta?.total ?? size,
    last_page: meta?.last_page ?? 1,
  })
}

function useInventoryInvalidator(tenantId: string | undefined) {
  const queryClient = useQueryClient()
  return () => {
    void queryClient.invalidateQueries({ queryKey: ['tenants', tenantId, 'inventory'] })
    void queryClient.invalidateQueries({ queryKey: ['dashboard'] })
  }
}

export interface BalanceFilters {
  search?: string
  search_by?: 'name' | 'sku' | 'barcode' | 'brand' | 'category' | 'variant' | 'description'
  letter?: string
  stock_location?: string
  branch?: string
  warehouse?: string
  category?: string
  brand?: string
  stock_status?: 'in_stock' | 'out_of_stock'
  in_stock?: boolean
  page?: number
}

export function useInventorySummary(tenantId: string | null | undefined) {
  return useQuery({
    queryKey: ['tenants', tenantId, 'inventory', 'summary'],
    queryFn: async () =>
      inventorySummarySchema.parse(await api.get<InventorySummary>(`/tenants/${tenantId}/inventory`)),
    enabled: Boolean(tenantId),
    retry: false,
  })
}

export function useInventoryBalances(tenantId: string | null | undefined, filters: BalanceFilters = {}) {
  return useQuery({
    queryKey: ['tenants', tenantId, 'inventory', 'balances', filters],
    queryFn: async () => {
      const { data, meta } = await api.getWithMeta<InventoryBalance[]>(
        `/tenants/${tenantId}/inventory/balances${queryString(filters)}`,
      )
      return {
        balances: data.map((balance) => inventoryBalanceSchema.parse(balance)),
        pagination: pagination(meta, data.length),
      }
    },
    enabled: Boolean(tenantId),
    retry: false,
  })
}

export interface MovementFilters {
  type?: string
  source?: string
  stock_location?: string
  search?: string
  from?: string
  to?: string
  page?: number
}

export function useInventoryMovements(tenantId: string | null | undefined, filters: MovementFilters = {}) {
  return useQuery({
    queryKey: ['tenants', tenantId, 'inventory', 'movements', filters],
    queryFn: async () => {
      const { data, meta } = await api.getWithMeta<InventoryMovement[]>(
        `/tenants/${tenantId}/inventory/movements${queryString(filters)}`,
      )
      return {
        movements: data.map((movement) => inventoryMovementSchema.parse(movement)),
        pagination: pagination(meta, data.length),
      }
    },
    enabled: Boolean(tenantId),
    retry: false,
  })
}

export function useInventoryCutoffs(tenantId: string | null | undefined) {
  return useQuery({
    queryKey: ['tenants', tenantId, 'inventory', 'cutoffs'],
    queryFn: async () => {
      const data = await api.get<InventoryCutoff[]>(`/tenants/${tenantId}/inventory/cutoffs`)
      return data.map((cutoff) => inventoryCutoffSchema.parse(cutoff))
    },
    enabled: Boolean(tenantId),
    retry: false,
  })
}

export function useInventoryCutoff(tenantId: string | null | undefined, cutoffId: string | null) {
  return useQuery({
    queryKey: ['tenants', tenantId, 'inventory', 'cutoffs', cutoffId],
    queryFn: async () =>
      inventoryCutoffSchema.parse(await api.get<InventoryCutoff>(`/tenants/${tenantId}/inventory/cutoffs/${cutoffId}`)),
    enabled: Boolean(tenantId && cutoffId),
    retry: false,
  })
}

export function useInventorySnapshots(tenantId: string | null | undefined) {
  return useQuery({
    queryKey: ['tenants', tenantId, 'inventory', 'snapshots'],
    queryFn: async () => {
      const data = await api.get<InventorySnapshot[]>(`/tenants/${tenantId}/inventory/snapshots`)
      return data.map((snapshot) => inventorySnapshotSchema.parse(snapshot))
    },
    enabled: Boolean(tenantId),
    retry: false,
  })
}

export function useInventorySnapshot(tenantId: string | null | undefined, snapshotId: string | null) {
  return useQuery({
    queryKey: ['tenants', tenantId, 'inventory', 'snapshots', snapshotId],
    queryFn: async () =>
      inventorySnapshotSchema.parse(await api.get<InventorySnapshot>(`/tenants/${tenantId}/inventory/snapshots/${snapshotId}`)),
    enabled: Boolean(tenantId && snapshotId),
    retry: false,
  })
}

export interface BatchFilters {
  search?: string
  expiry?: string
  page?: number
}

export function useInventoryBatches(tenantId: string | null | undefined, filters: BatchFilters = {}) {
  return useQuery({
    queryKey: ['tenants', tenantId, 'inventory', 'batches', filters],
    queryFn: async () => {
      const { data, meta } = await api.getWithMeta<InventoryBatch[]>(
        `/tenants/${tenantId}/inventory/batches${queryString(filters)}`,
      )
      return {
        batches: data.map((batch) => inventoryBatchSchema.parse(batch)),
        pagination: pagination(meta, data.length),
      }
    },
    enabled: Boolean(tenantId),
    retry: false,
  })
}

export interface SerialFilters {
  search?: string
  status?: string
  page?: number
}

export function useInventorySerials(tenantId: string | null | undefined, filters: SerialFilters = {}) {
  return useQuery({
    queryKey: ['tenants', tenantId, 'inventory', 'serials', filters],
    queryFn: async () => {
      const { data, meta } = await api.getWithMeta<InventorySerial[]>(
        `/tenants/${tenantId}/inventory/serials${queryString(filters)}`,
      )
      return {
        serials: data.map((serial) => inventorySerialSchema.parse(serial)),
        pagination: pagination(meta, data.length),
      }
    },
    enabled: Boolean(tenantId),
    retry: false,
  })
}

export function useCreateInventoryMovement(tenantId: string | undefined) {
  const invalidate = useInventoryInvalidator(tenantId)
  return useMutation({
    mutationFn: async (input: MovementInput) =>
      inventoryMovementSchema.parse(await api.post(`/tenants/${tenantId}/inventory/movements`, input)),
    onSuccess: invalidate,
  })
}

export function useImportInventoryMovements(tenantId: string | undefined) {
  const invalidate = useInventoryInvalidator(tenantId)
  return useMutation({
    mutationFn: async ({ file, source }: { file: File; source: string }) => {
      const form = new FormData()
      form.append('file', file)
      form.append('source', source)
      return movementImportResultSchema.parse(
        await api.upload<MovementImportResult>(`/tenants/${tenantId}/inventory/movements/import`, form),
      )
    },
    onSuccess: invalidate,
  })
}

export interface CutoffInput {
  stock_location_id?: string
  branch_id?: string
  cut_off_at?: string
  notes?: string
}

export function useCreateInventoryCutoff(tenantId: string | undefined) {
  const invalidate = useInventoryInvalidator(tenantId)
  return useMutation({
    mutationFn: async (input: CutoffInput) =>
      inventoryCutoffSchema.parse(await api.post(`/tenants/${tenantId}/inventory/cutoffs`, input)),
    onSuccess: invalidate,
  })
}

export function useCloseInventoryCutoff(tenantId: string | undefined) {
  const invalidate = useInventoryInvalidator(tenantId)
  return useMutation({
    mutationFn: async (cutoffId: string) =>
      inventoryCutoffSchema.parse(await api.post(`/tenants/${tenantId}/inventory/cutoffs/${cutoffId}/close`)),
    onSuccess: invalidate,
  })
}

export interface SnapshotInput {
  cutoff_id?: string
  stock_location_id?: string
  branch_id?: string
  snapshot_at?: string
  job_id?: string
  reference?: string
  notes?: string
}

export function useCreateInventorySnapshot(tenantId: string | undefined) {
  const invalidate = useInventoryInvalidator(tenantId)
  return useMutation({
    mutationFn: async (input: SnapshotInput) =>
      inventorySnapshotSchema.parse(await api.post(`/tenants/${tenantId}/inventory/snapshots`, input)),
    onSuccess: invalidate,
  })
}

export type { MovementType }
