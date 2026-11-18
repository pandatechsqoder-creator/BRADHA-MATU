import { z } from 'zod'

export const inventoryLocationSchema = z.object({
  id: z.string(),
  name: z.string(),
})
export type InventoryLocation = z.infer<typeof inventoryLocationSchema>

export const inventoryMovementLineSchema = z.object({
  variant_id: z.string().nullable().optional(),
  sku: z.string().nullable().optional(),
  product_name: z.string().nullable().optional(),
  variant_name: z.string().nullable().optional(),
  unit: z.string().nullable().optional(),
  quantity: z.number(),
  batch_number: z.string().nullable().optional(),
  serial_number: z.string().nullable().optional(),
  unit_cost: z.number().nullable().optional(),
})
export type InventoryMovementLine = z.infer<typeof inventoryMovementLineSchema>

export const inventoryMovementSchema = z.object({
  id: z.string(),
  type: z.string(),
  source: z.string(),
  external_id: z.string().nullable().optional(),
  reference: z.string().nullable().optional(),
  status: z.string(),
  occurred_at: z.string().nullable().optional(),
  notes: z.string().nullable().optional(),
  line_count: z.number().default(0),
  stock_location: inventoryLocationSchema.nullable().optional(),
  branch: inventoryLocationSchema.nullable().optional(),
  cutoff_id: z.string().nullable().optional(),
  lines: z.array(inventoryMovementLineSchema).default([]),
})
export type InventoryMovement = z.infer<typeof inventoryMovementSchema>

export const inventorySnapshotItemSchema = z.object({
  id: z.union([z.string(), z.number()]),
  variant_id: z.string().nullable().optional(),
  sku: z.string(),
  product_name: z.string(),
  unit: z.string().nullable().optional(),
  batch_number: z.string().nullable().optional(),
  expires_on: z.string().nullable().optional(),
  expected_quantity: z.number(),
  unit_cost: z.number().nullable().optional(),
  value: z.number().nullable().optional(),
  attributes: z.preprocess((value) => value ?? {}, z.record(z.string(), z.unknown())),
})
export type InventorySnapshotItem = z.infer<typeof inventorySnapshotItemSchema>

export const inventorySnapshotSchema = z.object({
  id: z.string(),
  reference: z.string().nullable().optional(),
  status: z.string(),
  snapshot_at: z.string().nullable().optional(),
  item_count: z.number().default(0),
  total_quantity: z.number().default(0),
  checksum: z.string(),
  job_id: z.string().nullable().optional(),
  notes: z.string().nullable().optional(),
  stock_location: inventoryLocationSchema.nullable().optional(),
  branch: inventoryLocationSchema.nullable().optional(),
  catalogue_version: z.number().nullable().optional(),
  cutoff_id: z.string().nullable().optional(),
  created_at: z.string().nullable().optional(),
  items: z.array(inventorySnapshotItemSchema).default([]),
})
export type InventorySnapshot = z.infer<typeof inventorySnapshotSchema>

export const inventoryCutoffSchema = z.object({
  id: z.string(),
  status: z.string(),
  cut_off_at: z.string().nullable().optional(),
  closed_at: z.string().nullable().optional(),
  notes: z.string().nullable().optional(),
  movement_count: z.number().default(0),
  stock_location: inventoryLocationSchema.nullable().optional(),
  branch: inventoryLocationSchema.nullable().optional(),
  created_at: z.string().nullable().optional(),
  movements: z.array(inventoryMovementSchema).default([]),
  snapshots: z.array(inventorySnapshotSchema).default([]),
})
export type InventoryCutoff = z.infer<typeof inventoryCutoffSchema>

export const inventoryBalanceSchema = z.object({
  id: z.string(),
  stock_location: inventoryLocationSchema.nullable(),
  variant_id: z.string().nullable().optional(),
  sku: z.string().nullable().optional(),
  product_name: z.string().nullable().optional(),
  variant_name: z.string().nullable().optional(),
  unit: z.string().nullable().optional(),
  tracking_mode: z.string().nullable().optional(),
  batch_number: z.string().nullable().optional(),
  expires_on: z.string().nullable().optional(),
  quantity: z.number(),
  reserved_quantity: z.number(),
  available_quantity: z.number(),
  value: z.number().nullable(),
  last_movement_at: z.string().nullable().optional(),
})
export type InventoryBalance = z.infer<typeof inventoryBalanceSchema>

export const inventoryBatchSchema = z.object({
  id: z.string(),
  batch_number: z.string(),
  status: z.string(),
  manufactured_on: z.string().nullable().optional(),
  expires_on: z.string().nullable().optional(),
  days_to_expiry: z.number().nullable().optional(),
  quantity: z.number(),
  variant_id: z.string().nullable().optional(),
  sku: z.string().nullable().optional(),
  product_name: z.string().nullable().optional(),
  variant_name: z.string().nullable().optional(),
  unit: z.string().nullable().optional(),
})
export type InventoryBatch = z.infer<typeof inventoryBatchSchema>

export const inventorySerialSchema = z.object({
  id: z.string(),
  serial_number: z.string(),
  status: z.string(),
  sku: z.string().nullable().optional(),
  product_name: z.string().nullable().optional(),
  batch_number: z.string().nullable().optional(),
  stock_location: inventoryLocationSchema.nullable().optional(),
  updated_at: z.string().nullable().optional(),
})
export type InventorySerial = z.infer<typeof inventorySerialSchema>

export const inventorySummarySchema = z.object({
  can_view_cost: z.boolean(),
  stats: z.object({
    locations: z.number(),
    tracked_variants: z.number(),
    balance_rows: z.number(),
    total_quantity: z.number(),
    stock_value: z.number().nullable(),
    movements_30_days: z.number(),
    expiring_batches: z.number(),
    expired_batches: z.number(),
    serials_in_stock: z.number(),
    open_cutoffs: z.number(),
  }),
  tracking: z.array(z.object({ mode: z.string(), variants: z.number() })).default([]),
  latest_snapshot: inventorySnapshotSchema.nullable(),
  open_cutoff: inventoryCutoffSchema.nullable(),
  recent_movements: z.array(inventoryMovementSchema).default([]),
})
export type InventorySummary = z.infer<typeof inventorySummarySchema>

export const importErrorsSchema = z.object({
  row: z.union([z.string(), z.number()]),
  message: z.string(),
})

export const movementImportResultSchema = z.object({
  status: z.string(),
  source: z.string(),
  created_movements: z.number(),
  skipped_movements: z.number(),
  error_rows: z.number(),
  errors: z.array(importErrorsSchema).default([]),
  movements: z.array(inventoryMovementSchema).default([]),
})
export type MovementImportResult = z.infer<typeof movementImportResultSchema>

export const MOVEMENT_TYPES = [
  'opening',
  'purchase',
  'sale',
  'return',
  'transfer_in',
  'transfer_out',
  'adjustment',
  'production',
  'damage',
  'expiry',
  'consumption',
  'other',
] as const

export type MovementType = (typeof MOVEMENT_TYPES)[number]

export interface MovementLineInput {
  sku: string
  quantity: string
  batch_number: string
  serial_number: string
  expires_on: string
  unit_cost: string
}

export interface MovementInput {
  stock_location_id: string
  type: MovementType
  occurred_at?: string
  reference?: string
  notes?: string
  external_id?: string
  lines: {
    sku: string
    quantity: number
    batch_number?: string
    serial_number?: string
    expires_on?: string
    unit_cost?: number
  }[]
}
