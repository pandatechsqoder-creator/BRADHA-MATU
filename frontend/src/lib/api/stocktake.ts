import { z } from 'zod'
import { api } from './client'

const nullableString = z.string().nullable().optional()
const nullableNumber = z.number().nullable().optional()

export const stocktakeJobSummarySchema = z.object({
  id: z.string(),
  reference: z.string(),
  title: z.string(),
  status: z.string(),
  status_label: z.string(),
  job_type: z.string(),
  priority: z.string(),
  count_method: z.string(),
  scope_label: z.string(),
  preferred_start_at: nullableString,
  deadline_at: nullableString,
  required_team_size: z.number().optional(),
  currency: z.string(),
  provisional_total: z.number(),
  quote_total: nullableNumber,
  quote_status: nullableString,
  deposit_required: z.boolean(),
  deposit_amount: z.number(),
  deposit_paid: z.number(),
  submitted_at: nullableString,
  accepted_at: nullableString,
  created_at: nullableString,
  updated_at: nullableString,
  requester: z.object({ name: nullableString, email: nullableString }).nullable().optional(),
  tenant: z.object({ id: nullableString, name: nullableString }).nullable().optional(),
})
export type StocktakeJobSummary = z.infer<typeof stocktakeJobSummarySchema>

export const stocktakeQuoteSchema = z.object({
  id: z.string(),
  number: z.string(),
  version: z.number(),
  status: z.string(),
  currency: z.string(),
  base_service_charge: z.number(),
  scope_complexity_charge: z.number(),
  travel_expenses: z.number(),
  other_expenses: z.number(),
  discount: z.number(),
  subtotal: z.number(),
  tax_rate: z.number(),
  tax: z.number(),
  total: z.number(),
  deposit_amount: z.number(),
  balance_due: z.number(),
  notes: nullableString,
  valid_until: nullableString,
  issued_at: nullableString,
  accepted_at: nullableString,
  decline_reason: nullableString,
})
export type StocktakeQuote = z.infer<typeof stocktakeQuoteSchema>

export const workerCompensationLineSchema = z.object({
  label: z.string(),
  amount: z.number(),
})
export type WorkerCompensationLine = z.infer<typeof workerCompensationLineSchema>

export const platformStocktakeQuoteSchema = stocktakeQuoteSchema.extend({
  job_status: nullableString,
  worker_compensation_budget: z.number(),
  worker_compensation_breakdown: z.array(workerCompensationLineSchema).nullable().optional(),
  platform_fee: z.number(),
  margin_amount: z.number(),
  internal_notes: nullableString,
  issued_by: nullableString,
  superseded_at: nullableString,
})
export type PlatformStocktakeQuote = z.infer<typeof platformStocktakeQuoteSchema>

export const stocktakePaymentSchema = z.object({
  id: z.string(),
  purpose: z.string(),
  amount: z.number(),
  currency: z.string(),
  method: z.string(),
  provider: z.string(),
  status: z.string(),
  internal_reference: z.string(),
  provider_reference: nullableString,
  checkout_reference: nullableString,
  phone: nullableString,
  instructions: z.unknown().optional(),
  notes: nullableString,
  failure_reason: nullableString,
  sandbox: z.boolean(),
  can_simulate: z.boolean(),
  can_verify: z.boolean(),
  created_at: nullableString,
  succeeded_at: nullableString,
  failed_at: nullableString,
  initiated_by: nullableString,
})
export type StocktakePayment = z.infer<typeof stocktakePaymentSchema>

export const stocktakeDepositSchema = z.object({
  id: z.string(),
  status: z.string(),
  amount: z.number(),
  paid_amount: z.number(),
  outstanding: z.number(),
  currency: z.string(),
  due_at: nullableString,
  overdue: z.boolean(),
  paid_at: nullableString,
  policy: z.record(z.string(), z.unknown()).nullable().optional(),
  payments: z.array(stocktakePaymentSchema).default([]),
})
export type StocktakeDeposit = z.infer<typeof stocktakeDepositSchema>

export const stocktakeBalanceSchema = z.object({
  quote_number: z.string(),
  total: z.number(),
  deposit_applied: z.number(),
  due: z.number(),
  paid: z.number(),
  currency: z.string(),
  due_at: nullableString,
})
export type StocktakeBalance = z.infer<typeof stocktakeBalanceSchema>

export const stocktakeAmendmentSchema = z.object({
  id: z.string(),
  status: z.string(),
  reason: z.string(),
  changes: z.record(z.string(), z.unknown()).default({}),
  financial_impact: z.number(),
  worker_consent_required: z.boolean(),
  requested_at: nullableString,
  decided_at: nullableString,
  decision_reason: nullableString,
  applied_at: nullableString,
})
export type StocktakeAmendment = z.infer<typeof stocktakeAmendmentSchema>

export const stocktakeTimelineEntrySchema = z.object({
  id: z.number(),
  from_status: nullableString,
  to_status: z.string(),
  to_status_label: z.string(),
  actor_type: z.string(),
  actor: nullableString,
  reason: nullableString,
  created_at: nullableString,
})
export type StocktakeTimelineEntry = z.infer<typeof stocktakeTimelineEntrySchema>

export const stocktakeScopeSchema = z.object({
  label: z.string().optional(),
  branches: z.array(z.object({ id: z.string(), name: z.string(), code: nullableString })).default([]),
  locations: z
    .array(
      z.object({
        id: z.string(),
        name: z.string(),
        code: nullableString,
        branch: nullableString,
        warehouse: nullableString,
      }),
    )
    .default([]),
  departments: z.array(z.object({ id: z.string(), name: z.string(), code: nullableString })).default([]),
  zones: z
    .array(z.object({ id: z.string(), name: z.string(), code: nullableString, location: nullableString }))
    .default([]),
})
export type StocktakeScope = z.infer<typeof stocktakeScopeSchema>

export const stocktakeRequirementSchema = z.object({
  industry: nullableString,
  safety: nullableString,
  access: nullableString,
  stock_movement_cutoff: nullableString,
  team_size: z.number().nullable().optional(),
  skills: z.array(z.string()).nullable().optional(),
  qualifications: nullableString,
  evidence: z.array(z.string()).nullable().optional(),
  signoffs: nullableString,
})

export const stocktakeCatalogueRefSchema = z.object({
  version_id: nullableString,
  version_number: nullableNumber,
  product_count: z.number().nullable().optional(),
})

export const stocktakeRequestDeclarationSchema = z.object({
  id: z.string(),
  revision: z.number(),
  declaration_text: z.string(),
  exceptions: nullableString,
  accepted_at: nullableString,
  declared_by: nullableString,
  catalogue_version_number: nullableNumber,
})

export const stocktakeEstimateLineSchema = z.object({
  label: z.string(),
  amount: z.number(),
})

export const stocktakeDepositPolicySchema = z.object({
  required: z.boolean(),
  type: z.string(),
  value: z.number(),
  minimum: z.number(),
  maximum: z.number(),
  amount: z.number(),
  currency: z.string(),
  due_days: z.number(),
  source: z.string(),
  summary: z.string(),
})
export type StocktakeDepositPolicy = z.infer<typeof stocktakeDepositPolicySchema>

export const stocktakeBaseJobSchema = z.object({
  id: z.string(),
  reference: z.string(),
  title: z.string(),
  description: nullableString,
  status: z.string(),
  status_label: z.string(),
  job_type: z.string(),
  priority: z.string(),
  business_criticality: nullableString,
  count_method: z.string(),
  expected_duration_minutes: nullableNumber,
  preferred_start_at: nullableString,
  deadline_at: nullableString,
  required_team_size: z.number(),
  scope: stocktakeScopeSchema,
  product_scope: z.string(),
  product_scope_payload: z.array(z.string()).nullable().optional(),
  requirements: stocktakeRequirementSchema,
  sop: z.object({
    template: nullableString,
    version: nullableString,
    instructions: nullableString,
  }),
  attachments: z.array(z.string()).nullable().optional(),
  catalogue: stocktakeCatalogueRefSchema,
  request_declaration: stocktakeRequestDeclarationSchema.nullable().optional(),
  request_declaration_history: z.array(stocktakeRequestDeclarationSchema).default([]),
  inventory_snapshot: z
    .object({
      id: nullableString,
      reference: nullableString,
      snapshot_at: nullableString,
      checksum: nullableString,
    })
    .nullable(),
  provisional_estimate: z.record(z.string(), z.unknown()).nullable().optional(),
  deposit_required: z.boolean(),
  deposit_amount: z.number(),
  deposit_paid_amount: z.number(),
  deposit_due_at: nullableString,
  quote_total: nullableNumber,
  currency: z.string(),
  version: z.number(),
  submitted_at: nullableString,
  review_started_at: nullableString,
  quoted_at: nullableString,
  accepted_at: nullableString,
  cancelled_at: nullableString,
  cancellation: z.record(z.string(), z.unknown()).nullable().optional(),
  closed_at: nullableString,
  created_at: nullableString,
  updated_at: nullableString,
  timeline: z.array(stocktakeTimelineEntrySchema).default([]),
  allowed_transitions: z.array(z.string()).default([]),
})
export type StocktakeBaseJob = z.infer<typeof stocktakeBaseJobSchema>

export const tenantStocktakePermissionsSchema = z.object({
  can_edit: z.boolean(),
  can_submit: z.boolean(),
  can_cancel: z.boolean(),
  can_accept_quote: z.boolean(),
  can_decline_quote: z.boolean(),
  can_pay_deposit: z.boolean(),
  can_pay_balance: z.boolean(),
  can_amend: z.boolean(),
  can_view_quote: z.boolean(),
})
export type TenantStocktakePermissions = z.infer<typeof tenantStocktakePermissionsSchema>

export const tenantStocktakeJobSchema = stocktakeBaseJobSchema.extend({
  quote: stocktakeQuoteSchema.nullable(),
  deposit: stocktakeDepositSchema.nullable(),
  balance: stocktakeBalanceSchema.nullable(),
  payments: z.array(stocktakePaymentSchema).default([]),
  amendments: z.array(stocktakeAmendmentSchema).default([]),
  permissions: tenantStocktakePermissionsSchema,
  internal: z.null().optional(),
})
export type TenantStocktakeJob = z.infer<typeof tenantStocktakeJobSchema>

export const platformStocktakePermissionsSchema = z.object({
  can_start_review: z.boolean(),
  can_issue_quote: z.boolean(),
  can_verify_payment: z.boolean(),
  can_waive_deposit: z.boolean(),
  can_decide_amendment: z.boolean(),
  can_approve_execution: z.boolean(),
  can_send_information_request: z.boolean(),
})
export type PlatformStocktakePermissions = z.infer<typeof platformStocktakePermissionsSchema>

export const platformStocktakeInternalSchema = z.object({
  worker_compensation_budget: nullableNumber,
  worker_compensation_breakdown: z.array(workerCompensationLineSchema).nullable().optional(),
  platform_fee: nullableNumber,
  margin_amount: nullableNumber,
  internal_notes: nullableString,
  deposit_policy: z.record(z.string(), z.unknown()).nullable().optional(),
})

export const platformStocktakeJobSchema = stocktakeBaseJobSchema.extend({
  tenant: z.object({ id: nullableString, name: nullableString, industry: nullableString }),
  requester: z.object({ name: nullableString, email: nullableString }),
  quote: platformStocktakeQuoteSchema.nullable(),
  quotes: z.array(platformStocktakeQuoteSchema).default([]),
  deposit: stocktakeDepositSchema.nullable(),
  balance: stocktakeBalanceSchema.nullable(),
  payments: z.array(stocktakePaymentSchema).default([]),
  amendments: z.array(stocktakeAmendmentSchema).default([]),
  permissions: platformStocktakePermissionsSchema,
  internal: platformStocktakeInternalSchema,
})
export type PlatformStocktakeJob = z.infer<typeof platformStocktakeJobSchema>

const stocktakeBucketSchema = z.object({
  drafts: z.number().optional(),
  awaiting_deposit: z.number(),
  with_platform: z.number().optional(),
  active: z.number().optional(),
  closed: z.number().optional(),
  new_requests: z.number().optional(),
  under_review: z.number().optional(),
  quotes_pending: z.number().optional(),
  ready_to_execute: z.number().optional(),
  active_jobs: z.number().optional(),
})
export type StocktakeBuckets = z.infer<typeof stocktakeBucketSchema>

export const stocktakeEstimateScopeSchema = stocktakeScopeSchema.extend({
  summary: z.object({
    branch_count: z.number(),
    location_count: z.number(),
    department_count: z.number(),
    zone_count: z.number(),
    label: z.string(),
  }),
})

export const stocktakeEstimateSchema = z.object({
  catalogue: z.object({
    version_number: nullableNumber,
    product_count: z.number(),
    confirmed: z.boolean(),
    ready: z.boolean(),
    reasons: z.array(z.string()).default([]),
  }),
  scope: stocktakeEstimateScopeSchema,
  counts: z.object({ skus: z.number(), branches: z.number(), locations: z.number() }),
  estimate: z.object({
    currency: z.string(),
    priority: z.string(),
    priority_multiplier: z.number(),
    base_charge: z.number(),
    sku_charge: z.number(),
    location_charge: z.number(),
    branch_charge: z.number(),
    subtotal: z.number(),
    tax_rate: z.number(),
    tax: z.number(),
    total: z.number(),
    provisional: z.boolean(),
    lines: z.array(stocktakeEstimateLineSchema).default([]),
  }),
  deposit: stocktakeDepositPolicySchema,
})
export type StocktakeEstimate = z.infer<typeof stocktakeEstimateSchema>

export const stocktakePolicySchema = z.object({
  id: z.string(),
  currency: z.string(),
  base_charge: z.number(),
  per_sku_charge: z.number(),
  per_location_charge: z.number(),
  per_branch_charge: z.number(),
  priority_multipliers: z.record(z.string(), z.number()).default({}),
  tax_rate: z.number(),
  deposit_type: z.string(),
  deposit_value: z.number(),
  deposit_minimum: z.number(),
  deposit_maximum: z.number(),
  deposit_due_days: z.number(),
  balance_due_days: z.number(),
  cancellation_fee_percent: z.number(),
  deposit_policy_overrides: z.union([z.record(z.string(), z.unknown()), z.array(z.unknown())]).default({}),
  updated_at: nullableString,
})
export type StocktakePolicy = z.infer<typeof stocktakePolicySchema>

export interface TenantJobFilters {
  status?: string
  search?: string
  priority?: string
  page?: number
}

export interface PlatformJobFilters {
  status?: string
  search?: string
  priority?: string
  page?: number
}

export interface StocktakeScopeInput {
  job_type?: string
  priority?: string
  count_method?: string
  required_team_size?: number
  product_scope?: string
  product_scope_payload?: string[]
  branch_ids?: string[]
  stock_location_ids?: string[]
  department_ids?: string[]
  zone_ids?: string[]
}

export interface StocktakeJobInput extends StocktakeScopeInput {
  title: string
  description?: string | null
  business_criticality?: string | null
  expected_duration_minutes?: number | null
  preferred_start_at?: string | null
  deadline_at?: string | null
  industry_requirements?: string | null
  safety_requirements?: string | null
  access_requirements?: string | null
  stock_movement_cutoff?: string | null
  required_skills?: string[]
  required_qualifications?: string | null
  required_evidence?: string[]
  signoff_requirements?: string | null
  custom_instructions?: string | null
  inventory_snapshot_id?: string | null
}

export interface PaymentInput {
  purpose: 'deposit' | 'balance'
  amount?: number
  method: 'mpesa' | 'card' | 'bank' | 'manual'
  phone?: string | null
  notes?: string | null
}

export interface QuoteInput {
  base_service_charge: number
  scope_complexity_charge?: number
  travel_expenses?: number
  other_expenses?: number
  discount?: number
  tax_rate?: number
  deposit_type?: 'none' | 'fixed' | 'percentage'
  deposit_value?: number
  deposit_amount?: number
  worker_compensation_budget?: number
  worker_compensation_breakdown?: WorkerCompensationLine[]
  platform_fee?: number
  notes?: string | null
  internal_notes?: string | null
  valid_until?: string | null
}

function queryString(filters: object): string {
  const query = new URLSearchParams()
  for (const [key, value] of Object.entries(filters)) {
    if (value === undefined || value === '') continue
    query.set(key, String(value))
  }
  const result = query.toString()
  return result ? `?${result}` : ''
}

function pagination(meta: { current_page?: number; per_page?: number; total?: number; last_page?: number } | undefined, size: number) {
  return {
    current_page: meta?.current_page ?? 1,
    per_page: meta?.per_page ?? size,
    total: meta?.total ?? size,
    last_page: meta?.last_page ?? 1,
  }
}

export async function fetchTenantJobs(tenantId: string, filters: TenantJobFilters = {}) {
  const { data, meta } = await api.getWithMeta<unknown[]>(
    `/tenants/${tenantId}/stocktake-jobs${queryString(filters)}`,
  )
  return {
    jobs: data.map((job) => stocktakeJobSummarySchema.parse(job)),
    pagination: pagination(meta, 20),
    buckets: stocktakeBucketSchema.parse(meta?.buckets ?? {}),
    statusCounts: (meta?.status_counts ?? {}) as Record<string, number>,
  }
}

export async function estimateTenantJob(tenantId: string, input: StocktakeScopeInput) {
  return stocktakeEstimateSchema.parse(
    await api.post<unknown>(`/tenants/${tenantId}/stocktake-jobs/estimate`, input),
  )
}

export async function createTenantJob(tenantId: string, input: StocktakeJobInput) {
  return tenantStocktakeJobSchema.parse(
    await api.post<unknown>(`/tenants/${tenantId}/stocktake-jobs`, input),
  )
}

export async function fetchTenantJob(tenantId: string, jobId: string) {
  return tenantStocktakeJobSchema.parse(
    await api.get<unknown>(`/tenants/${tenantId}/stocktake-jobs/${jobId}`),
  )
}

export async function updateTenantJob(tenantId: string, jobId: string, input: Partial<StocktakeJobInput>) {
  return tenantStocktakeJobSchema.parse(
    await api.patch<unknown>(`/tenants/${tenantId}/stocktake-jobs/${jobId}`, input),
  )
}

export async function submitTenantJob(
  tenantId: string,
  jobId: string,
  requestDeclaration: { confirm_accuracy: boolean; exceptions?: string | null },
) {
  return tenantStocktakeJobSchema.parse(
    await api.post<unknown>(`/tenants/${tenantId}/stocktake-jobs/${jobId}/submit`, {
      request_declaration: requestDeclaration,
    }),
  )
}

export async function payTenantJob(tenantId: string, jobId: string, input: PaymentInput, idempotencyKey: string) {
  return z
    .object({ payment: stocktakePaymentSchema, job: tenantStocktakeJobSchema })
    .parse(
      await api.postWithHeaders(
        `/tenants/${tenantId}/stocktake-jobs/${jobId}/payments`,
        input,
        { 'Idempotency-Key': idempotencyKey },
      ),
    )
}

export async function simulateTenantPayment(
  tenantId: string,
  jobId: string,
  paymentId: string,
  outcome: 'success' | 'failure',
) {
  return z
    .object({
      result: z.object({
        status: z.string(),
        duplicate: z.boolean().optional(),
        payment_id: nullableString,
        payment_status: nullableString,
        message: nullableString,
      }),
      job: tenantStocktakeJobSchema,
    })
    .parse(
      await api.post<unknown>(
        `/tenants/${tenantId}/stocktake-jobs/${jobId}/payments/${paymentId}/simulate`,
        { outcome },
      ),
    )
}

export async function acceptTenantQuote(tenantId: string, jobId: string) {
  return tenantStocktakeJobSchema.parse(
    await api.post<unknown>(`/tenants/${tenantId}/stocktake-jobs/${jobId}/quote/accept`),
  )
}

export async function declineTenantQuote(tenantId: string, jobId: string, reason: string) {
  return tenantStocktakeJobSchema.parse(
    await api.post<unknown>(`/tenants/${tenantId}/stocktake-jobs/${jobId}/quote/decline`, { reason }),
  )
}

export async function cancelTenantJob(tenantId: string, jobId: string, reason: string) {
  return tenantStocktakeJobSchema.parse(
    await api.post<unknown>(`/tenants/${tenantId}/stocktake-jobs/${jobId}/cancel`, { reason }),
  )
}

export async function amendTenantJob(
  tenantId: string,
  jobId: string,
  input: { reason: string; changes: Record<string, unknown> },
) {
  return z
    .object({
      amendment: stocktakeAmendmentSchema,
      job: tenantStocktakeJobSchema,
    })
    .parse(await api.post<unknown>(`/tenants/${tenantId}/stocktake-jobs/${jobId}/amendments`, input))
}

export async function fetchPlatformJobs(filters: PlatformJobFilters = {}) {
  const { data, meta } = await api.getWithMeta<unknown[]>(
    `/platform/stocktake-jobs${queryString(filters)}`,
  )
  return {
    jobs: data.map((job) => stocktakeJobSummarySchema.parse(job)),
    pagination: pagination(meta, 25),
    buckets: stocktakeBucketSchema.parse(meta?.buckets ?? {}),
    statusCounts: (meta?.status_counts ?? {}) as Record<string, number>,
  }
}

export async function fetchPlatformJob(jobId: string) {
  return platformStocktakeJobSchema.parse(
    await api.get<unknown>(`/platform/stocktake-jobs/${jobId}`),
  )
}

export async function startPlatformReview(jobId: string) {
  return platformStocktakeJobSchema.parse(
    await api.post<unknown>(`/platform/stocktake-jobs/${jobId}/review`),
  )
}

export async function requestPlatformInformation(jobId: string, reason: string) {
  return platformStocktakeJobSchema.parse(
    await api.post<unknown>(`/platform/stocktake-jobs/${jobId}/request-information`, { reason }),
  )
}

export async function issuePlatformQuote(jobId: string, input: QuoteInput) {
  return platformStocktakeJobSchema.parse(
    await api.post<unknown>(`/platform/stocktake-jobs/${jobId}/quotes`, input),
  )
}

export async function verifyPlatformDeposit(
  jobId: string,
  paymentId: string,
  decision: 'confirm' | 'fail',
  reason?: string,
) {
  return platformStocktakeJobSchema.parse(
    await api.post<unknown>(`/platform/stocktake-jobs/${jobId}/deposits/${paymentId}/verify`, {
      decision,
      ...(reason ? { reason } : {}),
    }),
  )
}

export async function waivePlatformDeposit(jobId: string, reason: string) {
  return platformStocktakeJobSchema.parse(
    await api.post<unknown>(`/platform/stocktake-jobs/${jobId}/deposit/waive`, { reason }),
  )
}

export async function decidePlatformAmendment(
  jobId: string,
  amendmentId: string,
  input: { decision: 'approve' | 'reject'; reason?: string; financial_impact?: number },
) {
  return platformStocktakeJobSchema.parse(
    await api.post<unknown>(`/platform/stocktake-jobs/${jobId}/amendments/${amendmentId}/decision`, input),
  )
}

export async function fetchStocktakePolicy() {
  return stocktakePolicySchema.parse(await api.get<unknown>('/platform/stocktake-policy'))
}

export async function updateStocktakePolicy(input: Partial<Omit<StocktakePolicy, 'id' | 'updated_at'>>) {
  return stocktakePolicySchema.parse(await api.patch<unknown>('/platform/stocktake-policy', input))
}
