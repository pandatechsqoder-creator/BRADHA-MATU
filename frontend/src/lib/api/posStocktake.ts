import { z } from 'zod'
import { api } from './client'

const nullableString = z.string().nullable()

export const posStocktakeReadinessSchema = z.object({
  catalogue_readiness: z.boolean(),
  product_count: z.number(),
  minimum_required: z.number(),
  blocks: z.array(z.string()).default([]),
  prepaid_eligible: z.boolean(),
  can_commission: z.boolean(),
})
export type PosStocktakeReadiness = z.infer<typeof posStocktakeReadinessSchema>

export const posStocktakeEligibilitySchema = posStocktakeReadinessSchema.extend({
  has_active_shift: z.boolean(),
  existing_commissioned_jobs: z.number(),
})
export type PosStocktakeEligibility = z.infer<typeof posStocktakeEligibilitySchema>

export const posStocktakeJobSchema = z.object({
  job_id: z.string(),
  reference: z.string(),
  title: z.string(),
  status: z.string(),
  mode: z.string(),
  frequency: z.string(),
  pos_commissioned: z.boolean(),
  pos_vendor_reference: nullableString,
  prepaid_agreement_id: nullableString,
  authorised_by: nullableString,
  authorised_at: nullableString,
  cancellation_reason: nullableString,
  created_at: nullableString,
})
export type PosStocktakeJob = z.infer<typeof posStocktakeJobSchema>

export const posQuoteSchema = z.object({
  quote_number: z.string(),
  quote_version: z.number(),
  subtotal: z.number(),
  tax_amount: z.number(),
  discount_amount: z.number(),
  total: z.number(),
  currency: z.string(),
  valid_until: z.string(),
  issued_at: z.string(),
  items: z.array(
    z.object({
      product_name: z.string(),
      sku: nullableString,
      barcode: nullableString,
      quantity: z.number(),
      unit: z.string(),
      unit_price: z.number(),
      line_total: z.number(),
      tax_amount: z.number(),
      discount_amount: z.number(),
    }),
  ),
})
export type PosQuote = z.infer<typeof posQuoteSchema>

export interface CommissionStocktakeInput {
  title: string
  mode?: 'reminder' | 'proposed'
  frequency?: string
  scope_config?: Record<string, unknown>
  recurrence_config?: Record<string, unknown>
  starts_at?: string
  ends_at?: string
  minimum_team_size?: number
  estimated_duration_hours?: number
  pos_reference?: string
  authorisation_notes?: string
  prepaid_agreement_id?: string
}

export async function checkCatalogueReadiness(tenantId: string): Promise<PosStocktakeReadiness> {
  return posStocktakeReadinessSchema.parse(
    await api.get<unknown>(`/tenants/${tenantId}/pos/stocktake/check-catalogue-readiness`),
  )
}

export async function fetchPosStocktakeEligibility(tenantId: string): Promise<PosStocktakeEligibility> {
  return posStocktakeEligibilitySchema.parse(
    await api.get<unknown>(`/tenants/${tenantId}/pos/stocktake/fetch-eligibility`),
  )
}

export async function fetchPosStocktakeJobs(tenantId: string): Promise<PosStocktakeJob[]> {
  return z
    .array(posStocktakeJobSchema)
    .parse(await api.get<unknown>(`/tenants/${tenantId}/pos/stocktake/jobs`))
}

export async function commissionStocktake(
  tenantId: string,
  input: CommissionStocktakeInput,
): Promise<PosStocktakeJob> {
  return posStocktakeJobSchema.parse(
    await api.post<unknown>(`/tenants/${tenantId}/pos/stocktake/commission`, input),
  )
}

export async function authorizeStocktakeJob(tenantId: string, jobId: string): Promise<PosStocktakeJob> {
  return posStocktakeJobSchema.parse(
    await api.post<unknown>(`/tenants/${tenantId}/pos/stocktake/${jobId}/authorize`),
  )
}

export async function declineStocktakeJob(
  tenantId: string,
  jobId: string,
  reason: string,
): Promise<PosStocktakeJob> {
  return posStocktakeJobSchema.parse(
    await api.post<unknown>(`/tenants/${tenantId}/pos/stocktake/${jobId}/decline`, { reason }),
  )
}

export async function generateQuoteFromPos(tenantId: string, saleId: string): Promise<PosQuote> {
  return posQuoteSchema.parse(
    await api.post<unknown>(`/tenants/${tenantId}/pos/stocktake/generate-quote/${saleId}`),
  )
}
