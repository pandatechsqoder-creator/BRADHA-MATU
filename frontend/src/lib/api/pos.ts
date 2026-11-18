import { z } from 'zod'
import { api } from './client'

const nullableString = z.string().nullable().optional()

export const posTerminalSchema = z.object({
  id: z.string(),
  name: z.string(),
  branch_id: nullableString,
  device_id: nullableString,
  status: z.enum(['active', 'inactive', 'maintenance']),
  current_shift: z
    .object({
      id: z.string(),
      status: z.string(),
      opened_at: nullableString,
    })
    .nullable()
    .optional(),
  created_at: nullableString,
})
export type PosTerminal = z.infer<typeof posTerminalSchema>

export const posShiftSchema = z.object({
  id: z.string(),
  terminal_id: z.string(),
  terminal_name: nullableString,
  opened_by: nullableString,
  closed_by: nullableString,
  opening_float: z.number(),
  expected_cash: z.number(),
  actual_cash: z.number().nullable(),
  cash_variance: z.number().nullable(),
  status: z.enum(['open', 'closed', 'reconciled']),
  opened_at: nullableString,
  closed_at: nullableString,
  reconciled_at: nullableString,
  total_sales: z.number(),
  total_cash_sales: z.number(),
  created_at: nullableString,
})
export type PosShift = z.infer<typeof posShiftSchema>

export const posSaleItemSchema = z.object({
  id: z.string(),
  product_name: z.string(),
  sku: nullableString,
  barcode: nullableString,
  quantity: z.number(),
  unit: z.string(),
  unit_price: z.number(),
  line_total: z.number(),
  tax_amount: z.number(),
  discount_amount: z.number(),
})
export type PosSaleItem = z.infer<typeof posSaleItemSchema>

export const posSaleSchema = z.object({
  id: z.string(),
  reference: z.string(),
  receipt_number: nullableString,
  terminal_id: z.string(),
  terminal_name: nullableString,
  shift_id: z.string(),
  user: nullableString,
  customer: nullableString,
  status: z.string(),
  subtotal: z.number(),
  tax_amount: z.number(),
  discount_amount: z.number(),
  total_amount: z.number(),
  currency: z.string(),
  payment_method: nullableString,
  cash_tendered: z.number().nullable(),
  cash_change: z.number().nullable(),
  notes: nullableString,
  created_at: nullableString,
  items: z.array(posSaleItemSchema).default([]),
  receipt: z
    .object({
      receipt_number: z.string(),
      type: z.string(),
      amount: z.number(),
      printed: z.boolean(),
    })
    .nullable()
    .optional(),
})
export type PosSale = z.infer<typeof posSaleSchema>

export const posProductVariantSchema = z.object({
  id: z.string(),
  name: nullableString,
  sku: z.string(),
  status: z.string(),
  base_unit: nullableString,
  attributes: z.record(z.string(), z.unknown()).default({}),
  barcodes: z.array(
    z.object({
      id: z.string(),
      barcode: z.string(),
      type: z.string(),
      is_primary: z.boolean(),
    }),
  ).default([]),
  barcode: nullableString,
  selling_price: z.number().nullable(),
})
export type PosProductVariant = z.infer<typeof posProductVariantSchema>

export const posProductSchema = z.object({
  id: z.string(),
  name: z.string(),
  brand: nullableString,
  category: z.object({ id: z.string(), name: z.string() }).nullable(),
  status: z.string(),
  sku: nullableString,
  barcode: nullableString,
  base_unit: nullableString,
  variants_count: z.number(),
  variants: z.array(posProductVariantSchema),
})
export type PosProduct = z.infer<typeof posProductSchema>

export const posProductSearchSchema = z.object({
  products: z.array(posProductSchema),
  pagination: z.object({
    current_page: z.number(),
    per_page: z.number(),
    total: z.number(),
    last_page: z.number(),
  }),
  terminal_id: z.string(),
  branch_id: nullableString,
})
export type PosProductSearch = z.infer<typeof posProductSearchSchema>

export const posSupplierSchema = z.object({
  id: z.string(),
  name: z.string(),
  contact_person: nullableString,
  phone: nullableString,
  email: nullableString,
  address: nullableString,
  tax_pin: nullableString,
  active: z.boolean(),
  created_at: nullableString,
})
export type PosSupplier = z.infer<typeof posSupplierSchema>

// ---- input schemas ----

export const createTerminalInputSchema = z.object({
  name: z.string().min(1, 'Terminal name is required').max(200),
  branch_id: z.string().min(1).optional(),
  device_id: z.string().max(100).optional(),
  status: z.enum(['active', 'inactive', 'maintenance']).optional(),
})
export type CreateTerminalInput = z.infer<typeof createTerminalInputSchema>

export const openShiftInputSchema = z.object({
  opening_float: z.number().min(0).optional(),
  notes: z.string().max(1000).optional(),
})
export type OpenShiftInput = z.infer<typeof openShiftInputSchema>

export const closeShiftInputSchema = z.object({
  actual_cash: z.number().min(0),
  notes: z.string().max(1000).optional(),
})
export type CloseShiftInput = z.infer<typeof closeShiftInputSchema>

export const createSaleItemInputSchema = z.object({
  variant_id: z.string().min(1),
  batch_id: z.string().min(1).optional(),
  barcode: z.string().max(100).optional(),
  quantity: z.number().positive('Quantity must be greater than zero'),
  tax_amount: z.number().min(0).optional(),
  discount_amount: z.number().min(0).optional(),
})
export type CreateSaleItemInput = z.infer<typeof createSaleItemInputSchema>

export const createSaleInputSchema = z.object({
  terminal_id: z.string().min(1),
  customer_id: z.string().min(1).optional(),
  payment_method: z.enum(['cash', 'mpesa', 'card', 'mixed']).optional(),
  cash_tendered: z.number().min(0).optional(),
  currency: z.string().length(3).optional(),
  notes: z.string().max(1000).optional(),
  items: z.array(createSaleItemInputSchema).min(1, 'At least one item is required'),
})
export type CreateSaleInput = z.infer<typeof createSaleInputSchema>

export const createSupplierInputSchema = z.object({
  name: z.string().min(1, 'Supplier name is required').max(200),
  contact_person: z.string().max(200).optional(),
  phone: z.string().max(50).optional(),
  email: z.string().email().optional(),
  address: z.string().max(500).optional(),
  tax_pin: z.string().max(50).optional(),
  notes: z.string().max(1000).optional(),
})
export type CreateSupplierInput = z.infer<typeof createSupplierInputSchema>

export const createPurchaseItemInputSchema = z.object({
  variant_id: z.string().min(1),
  batch_id: z.string().min(1).optional(),
  product_name: z.string().min(1, 'Product name is required').max(200),
  sku: z.string().max(100).optional(),
  quantity: z.number().positive('Quantity must be greater than zero'),
  unit: z.string().max(20).optional(),
  unit_cost: z.number().min(0, 'Cost must be non-negative'),
})
export type CreatePurchaseItemInput = z.infer<typeof createPurchaseItemInputSchema>

export const createPurchaseInputSchema = z.object({
  supplier_id: z.string().min(1),
  branch_id: z.string().min(1).optional(),
  status: z.enum(['ordered', 'received', 'cancelled']).optional(),
  subtotal: z.number().min(0).optional(),
  tax_amount: z.number().min(0).optional(),
  total_amount: z.number().min(0).optional(),
  currency: z.string().length(3).optional(),
  notes: z.string().max(1000).optional(),
  items: z.array(createPurchaseItemInputSchema).min(1, 'At least one item is required'),
})
export type CreatePurchaseInput = z.infer<typeof createPurchaseInputSchema>

export const posPurchaseItemSchema = z.object({
  product_name: z.string(),
  sku: nullableString,
  quantity: z.number(),
  unit_cost: z.number(),
  line_total: z.number(),
})
export type PosPurchaseItem = z.infer<typeof posPurchaseItemSchema>

export const posPurchaseSchema = z.object({
  id: z.string(),
  reference: z.string(),
  supplier: nullableString,
  user: nullableString,
  status: z.string(),
  subtotal: z.number(),
  tax_amount: z.number(),
  total_amount: z.number(),
  currency: z.string(),
  created_at: nullableString,
  items: z.array(posPurchaseItemSchema).default([]),
})
export type PosPurchase = z.infer<typeof posPurchaseSchema>

export const posCustomerSchema = z.object({
  id: z.string(),
  name: z.string(),
  phone: nullableString,
  email: nullableString,
  loyalty_id: nullableString,
  active: z.boolean(),
  notes: nullableString,
  created_at: nullableString,
})
export type PosCustomer = z.infer<typeof posCustomerSchema>

export const posReturnItemSchema = z.object({
  product_name: z.string(),
  sku: nullableString,
  quantity: z.number(),
  unit: z.string(),
  unit_price: z.number(),
  line_total: z.number(),
})
export const posReturnSchema = z.object({
  id: z.string(),
  reference: z.string(),
  sale_id: nullableString,
  sale_reference: nullableString,
  user: nullableString,
  status: z.string(),
  refund_method: z.string(),
  subtotal: z.number(),
  tax_amount: z.number(),
  total_amount: z.number(),
  currency: z.string(),
  reason: nullableString,
  returned_at: nullableString,
  items: z.array(posReturnItemSchema).default([]),
})
export type PosReturn = z.infer<typeof posReturnSchema>

export const posTransferItemSchema = z.object({
  product_name: z.string(),
  sku: nullableString,
  quantity: z.number(),
  unit: z.string(),
})
export const posTransferSchema = z.object({
  id: z.string(),
  reference: z.string(),
  from_branch: nullableString,
  to_branch: nullableString,
  user: nullableString,
  status: z.string(),
  notes: nullableString,
  shipped_at: nullableString,
  received_at: nullableString,
  created_at: nullableString,
  items: z.array(posTransferItemSchema).default([]),
})
export type PosTransfer = z.infer<typeof posTransferSchema>

export const createCustomerInputSchema = z.object({
  name: z.string().min(1, 'Customer name is required').max(200),
  phone: z.string().max(50).optional(),
  email: z.string().email().optional(),
  loyalty_id: z.string().max(100).optional(),
  notes: z.string().max(1000).optional(),
})
export type CreateCustomerInput = z.infer<typeof createCustomerInputSchema>

export const createReturnInputSchema = z.object({
  sale_id: z.string().min(1),
  refund_method: z.enum(['cash', 'mpesa', 'card', 'none']).optional(),
  reason: z.string().max(500).optional(),
  notes: z.string().max(1000).optional(),
  items: z
    .array(z.object({ sale_item_id: z.string().min(1), quantity: z.number().positive() }))
    .min(1),
})
export type CreateReturnInput = z.infer<typeof createReturnInputSchema>

export const createTransferInputSchema = z.object({
  from_branch_id: z.string().min(1),
  to_branch_id: z.string().min(1),
  status: z.enum(['in_transit', 'received']).optional(),
  notes: z.string().max(1000).optional(),
  items: z
    .array(
      z.object({
        variant_id: z.string().min(1),
        quantity: z.number().positive(),
        unit: z.string().max(20).optional(),
      }),
    )
    .min(1),
})
export type CreateTransferInput = z.infer<typeof createTransferInputSchema>

export const posReconciliationSchema = z.object({
  shift_id: z.string(),
  opening_float: z.number(),
  expected_cash: z.number(),
  actual_cash: z.number().nullable(),
  cash_variance: z.number().nullable(),
  cash_sales: z.number(),
  card_sales: z.number(),
  mpesa_sales: z.number(),
  total_sales: z.number(),
  transaction_count: z.number(),
  status: z.string(),
  reconciled_at: nullableString,
})
export type PosReconciliation = z.infer<typeof posReconciliationSchema>

// ---- API functions ----

export async function fetchPosTerminals(tenantId: string): Promise<PosTerminal[]> {
  return z.array(posTerminalSchema).parse(await api.get<unknown>(`/tenants/${tenantId}/pos/terminals`))
}

export async function createPosTerminal(
  tenantId: string,
  input: CreateTerminalInput,
): Promise<PosTerminal> {
  createTerminalInputSchema.parse(input)
  return posTerminalSchema.parse(
    await api.post<unknown>(`/tenants/${tenantId}/pos/terminals`, input),
  )
}

export async function updatePosTerminal(
  tenantId: string,
  terminalId: string,
  input: Partial<CreateTerminalInput>,
): Promise<PosTerminal> {
  return posTerminalSchema.parse(
    await api.patch<unknown>(`/tenants/${tenantId}/pos/terminals/${terminalId}`, input),
  )
}

export async function fetchPosShifts(tenantId: string): Promise<PosShift[]> {
  return z.array(posShiftSchema).parse(
    await api.get<unknown>(`/tenants/${tenantId}/pos/shifts`),
  )
}

export async function openPosShift(
  tenantId: string,
  terminalId: string,
  input: OpenShiftInput,
): Promise<PosShift> {
  openShiftInputSchema.parse(input)
  return posShiftSchema.parse(
    await api.post<unknown>(
      `/tenants/${tenantId}/pos/terminals/${terminalId}/shifts/open`,
      input,
    ),
  )
}

export async function closePosShift(
  tenantId: string,
  shiftId: string,
  input: CloseShiftInput,
): Promise<PosShift> {
  closeShiftInputSchema.parse(input)
  return posShiftSchema.parse(
    await api.post<unknown>(
      `/tenants/${tenantId}/pos/shifts/${shiftId}/close`,
      input,
    ),
  )
}

export async function reconcilePosShift(
  tenantId: string,
  shiftId: string,
): Promise<PosShift> {
  return posShiftSchema.parse(
    await api.post<unknown>(`/tenants/${tenantId}/pos/shifts/${shiftId}/reconcile`),
  )
}

export async function fetchPosShiftReconciliation(
  tenantId: string,
  shiftId: string,
): Promise<PosReconciliation> {
  return posReconciliationSchema.parse(
    await api.get<unknown>(
      `/tenants/${tenantId}/pos/shifts/${shiftId}/reconciliation`,
    ),
  )
}

export async function fetchPosSales(tenantId: string, filters: {
  status?: string
  terminal_id?: string
  from?: string
  to?: string
} = {}): Promise<PosSale[]> {
  const params = new URLSearchParams()
  if (filters.status) params.set('status', filters.status)
  if (filters.terminal_id) params.set('terminal_id', filters.terminal_id)
  if (filters.from) params.set('from', filters.from)
  if (filters.to) params.set('to', filters.to)
  const query = params.toString() ? `?${params.toString()}` : ''
  return z.array(posSaleSchema).parse(
    await api.get<unknown>(`/tenants/${tenantId}/pos/sales${query}`),
  )
}

export async function fetchPosProducts(tenantId: string, filters: {
  terminalId: string
  search?: string
  brand?: string
  category?: string
  page?: number
  perPage?: number
}): Promise<PosProductSearch> {
  const params = new URLSearchParams({ terminal_id: filters.terminalId })
  if (filters.search) params.set('search', filters.search)
  if (filters.brand) params.set('brand', filters.brand)
  if (filters.category) params.set('category', filters.category)
  if (filters.page) params.set('page', String(filters.page))
  if (filters.perPage) params.set('per_page', String(filters.perPage))

  return posProductSearchSchema.parse(
    await api.get<unknown>(`/tenants/${tenantId}/pos/products?${params.toString()}`),
  )
}

export async function createPosSale(
  tenantId: string,
  input: CreateSaleInput,
): Promise<PosSale> {
  createSaleInputSchema.parse(input)
  return posSaleSchema.parse(
    await api.post<unknown>(`/tenants/${tenantId}/pos/sales`, input),
  )
}

export async function fetchPosSale(tenantId: string, saleId: string): Promise<PosSale> {
  return posSaleSchema.parse(
    await api.get<unknown>(`/tenants/${tenantId}/pos/sales/${saleId}`),
  )
}

export async function fetchPosSuppliers(tenantId: string): Promise<PosSupplier[]> {
  return z.array(posSupplierSchema).parse(
    await api.get<unknown>(`/tenants/${tenantId}/pos/suppliers`),
  )
}

export async function createPosSupplier(
  tenantId: string,
  input: CreateSupplierInput,
): Promise<PosSupplier> {
  createSupplierInputSchema.parse(input)
  return posSupplierSchema.parse(
    await api.post<unknown>(`/tenants/${tenantId}/pos/suppliers`, input),
  )
}

export async function fetchPosPurchases(tenantId: string): Promise<PosPurchase[]> {
  return z.array(posPurchaseSchema).parse(
    await api.get<unknown>(`/tenants/${tenantId}/pos/purchases`),
  )
}

export async function createPosPurchase(
  tenantId: string,
  input: CreatePurchaseInput,
): Promise<PosPurchase> {
  createPurchaseInputSchema.parse(input)
  return posPurchaseSchema.parse(
    await api.post<unknown>(`/tenants/${tenantId}/pos/purchases`, input),
  )
}

export async function fetchPosCustomers(
  tenantId: string,
  search?: string,
): Promise<PosCustomer[]> {
  const query = search ? `?search=${encodeURIComponent(search)}` : ''
  return z.array(posCustomerSchema).parse(
    await api.get<unknown>(`/tenants/${tenantId}/pos/customers${query}`),
  )
}

export async function createPosCustomer(
  tenantId: string,
  input: CreateCustomerInput,
): Promise<PosCustomer> {
  createCustomerInputSchema.parse(input)
  return posCustomerSchema.parse(
    await api.post<unknown>(`/tenants/${tenantId}/pos/customers`, input),
  )
}

export async function fetchPosReturns(tenantId: string): Promise<PosReturn[]> {
  return z.array(posReturnSchema).parse(
    await api.get<unknown>(`/tenants/${tenantId}/pos/returns`),
  )
}

export async function createPosReturn(
  tenantId: string,
  input: CreateReturnInput,
): Promise<PosReturn> {
  createReturnInputSchema.parse(input)
  return posReturnSchema.parse(
    await api.post<unknown>(`/tenants/${tenantId}/pos/returns`, input),
  )
}

export async function fetchPosTransfers(tenantId: string): Promise<PosTransfer[]> {
  return z.array(posTransferSchema).parse(
    await api.get<unknown>(`/tenants/${tenantId}/pos/transfers`),
  )
}

export async function createPosTransfer(
  tenantId: string,
  input: CreateTransferInput,
): Promise<PosTransfer> {
  createTransferInputSchema.parse(input)
  return posTransferSchema.parse(
    await api.post<unknown>(`/tenants/${tenantId}/pos/transfers`, input),
  )
}

export async function receivePosTransfer(
  tenantId: string,
  transferId: string,
): Promise<PosTransfer> {
  return posTransferSchema.parse(
    await api.post<unknown>(`/tenants/${tenantId}/pos/transfers/${transferId}/receive`),
  )
}