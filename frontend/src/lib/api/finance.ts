import { z } from 'zod'
import { api } from './client'
import { stocktakePaymentSchema } from './stocktake'

const nullableString = z.string().nullable().optional()

export const financePaymentSchema = stocktakePaymentSchema.extend({
  job: z
    .object({
      id: z.string(),
      reference: z.string(),
      title: z.string(),
      status: z.string(),
    })
    .nullable()
    .optional(),
  tenant: z.object({ id: z.string(), name: z.string() }).nullable().optional(),
})
export type FinancePayment = z.infer<typeof financePaymentSchema>

export const trialBalanceSchema = z.object({
  accounts: z
    .array(
      z.object({
        code: z.string(),
        name: z.string(),
        type: z.string(),
        normal_balance: z.string(),
        debit: z.number(),
        credit: z.number(),
        balance: z.number(),
      }),
    )
    .default([]),
  totals: z.object({ debit: z.number(), credit: z.number() }),
  balanced: z.boolean(),
})
export type TrialBalance = z.infer<typeof trialBalanceSchema>

export const financeOverviewSchema = z.object({
  collected_today: z.number(),
  collected_this_month: z.number(),
  by_method: z.array(z.object({ method: z.string(), payments: z.number(), total: z.number() })).default([]),
  awaiting_verification: z.number(),
  processing: z.number(),
  failed_payments: z.number(),
  awaiting_deposit_jobs: z.number(),
  awaiting_balance_jobs: z.number(),
  refunds_requested: z.number(),
  ledger: trialBalanceSchema,
  recent_payments: z.array(financePaymentSchema).default([]),
  recent_reconciliations: z
    .array(
      z.object({
        id: z.string(),
        reference: z.string(),
        provider: z.string(),
        status: z.string(),
        period_start: nullableString,
        period_end: nullableString,
        difference: z.number(),
      }),
    )
    .default([]),
})
export type FinanceOverview = z.infer<typeof financeOverviewSchema>

export const financeLedgerLineSchema = z.object({
  account: nullableString,
  account_name: nullableString,
  debit: z.number(),
  credit: z.number(),
  memo: nullableString,
})

export const financeJournalSchema = z.object({
  id: z.string(),
  reference: z.string(),
  description: z.string(),
  currency: z.string(),
  transaction_date: nullableString,
  posted_at: nullableString,
  job: z.object({ id: z.string(), reference: z.string() }).nullable().optional(),
  tenant: nullableString,
  actor: nullableString,
  source_type: z.string(),
  reversal_of: z.boolean(),
  lines: z.array(financeLedgerLineSchema).default([]),
})
export type FinanceJournal = z.infer<typeof financeJournalSchema>

export const financeReconciliationItemSchema = z.object({
  provider_reference: z.string(),
  amount: z.number(),
  status: z.string(),
  payment_id: nullableString,
  note: nullableString,
})
export type FinanceReconciliationItem = z.infer<typeof financeReconciliationItemSchema>

export const financeReconciliationSchema = z.object({
  id: z.string(),
  reference: z.string(),
  provider: z.string(),
  currency: z.string(),
  period_start: nullableString,
  period_end: nullableString,
  status: z.string(),
  statement_total: z.number(),
  payment_total: z.number(),
  ledger_total: z.number(),
  difference: z.number(),
  exception_count: z.number(),
  notes: nullableString,
  created_by: nullableString,
  completed_at: nullableString,
  created_at: nullableString,
  items: z.array(financeReconciliationItemSchema).nullable().optional(),
})
export type FinanceReconciliation = z.infer<typeof financeReconciliationSchema>

export const financeRefundSchema = z.object({
  id: z.string(),
  status: z.string(),
  amount: z.number(),
  currency: z.string(),
  reason: z.string(),
  payment: z
    .object({ id: z.string(), reference: z.string(), method: z.string(), purpose: z.string() })
    .nullable()
    .optional(),
  job: z.object({ id: z.string(), reference: z.string(), title: z.string() }).nullable().optional(),
  requested_by: nullableString,
  approved_by: nullableString,
  approved_at: nullableString,
  provider_reference: nullableString,
  processed_at: nullableString,
  failure_reason: nullableString,
  created_at: nullableString,
})
export type FinanceRefund = z.infer<typeof financeRefundSchema>

export const paymentOptionSchema = z.object({
  method: z.string(),
  provider: z.string(),
  label: z.string(),
  mode: z.string(),
})
export type PaymentOption = z.infer<typeof paymentOptionSchema>

export const financeOptionsSchema = z.object({
  providers: z.array(paymentOptionSchema).default([]),
  sandbox: z.boolean(),
})
export type FinanceOptions = z.infer<typeof financeOptionsSchema>

const envelopeMetaSchema = z
  .object({
    current_page: z.number().optional(),
    per_page: z.number().optional(),
    total: z.number().optional(),
    last_page: z.number().optional(),
    counts: z.union([z.record(z.string(), z.number()), z.array(z.unknown())]).optional(),
    trial_balance: trialBalanceSchema.optional(),
  })
  .optional()

export interface FinancePaymentFilters {
  status?: string
  method?: string
  purpose?: string
  search?: string
  page?: number
}

export interface FinanceLedgerFilters {
  from?: string
  to?: string
  tenant_id?: string
  job_id?: string
  page?: number
}

export interface ReconciliationInput {
  provider: 'mpesa' | 'card' | 'bank' | 'manual'
  period_start: string
  period_end: string
  currency?: string
  notes?: string
  statement_lines: { provider_reference: string; amount: number }[]
}

export interface RefundInput {
  payment_id: string
  amount: number
  reason: string
}

function toQuery(params: Record<string, string | number | undefined>): string {
  const search = new URLSearchParams()
  Object.entries(params).forEach(([key, value]) => {
    if (value !== undefined && value !== '') search.set(key, String(value))
  })
  const query = search.toString()
  return query ? `?${query}` : ''
}

export async function fetchFinanceOverview(): Promise<FinanceOverview> {
  const data = await api.get<unknown>('/platform/finance/overview')
  return financeOverviewSchema.parse(data)
}

export async function fetchFinancePayments(filters: FinancePaymentFilters = {}) {
  const { data, meta } = await api.getWithMeta<unknown[]>(
    `/platform/finance/payments${toQuery({ ...filters })}`,
  )
  const parsedMeta = envelopeMetaSchema.parse(meta)
  return {
    payments: z.array(financePaymentSchema).parse(data),
    pagination: {
      current_page: parsedMeta?.current_page ?? 1,
      per_page: parsedMeta?.per_page ?? 25,
      total: parsedMeta?.total ?? data.length,
      last_page: parsedMeta?.last_page ?? 1,
    },
    counts: parsedMeta?.counts && !Array.isArray(parsedMeta.counts) ? parsedMeta.counts : {},
  }
}
export type FinancePaymentsResult = Awaited<ReturnType<typeof fetchFinancePayments>>

export async function verifyFinancePayment(
  paymentId: string,
  decision: 'confirm' | 'fail',
  reference?: string,
  reason?: string,
): Promise<FinancePayment> {
  const data = await api.post<unknown>(`/platform/finance/payments/${paymentId}/verify`, {
    decision,
    reference: reference ?? null,
    reason: reason ?? null,
  })
  return financePaymentSchema.parse(data)
}

export async function fetchFinanceLedger(filters: FinanceLedgerFilters = {}) {
  const { data, meta } = await api.getWithMeta<unknown[]>(
    `/platform/finance/ledger${toQuery({ ...filters })}`,
  )
  const parsedMeta = envelopeMetaSchema.parse(meta)
  return {
    journals: z.array(financeJournalSchema).parse(data),
    pagination: {
      current_page: parsedMeta?.current_page ?? 1,
      per_page: parsedMeta?.per_page ?? 30,
      total: parsedMeta?.total ?? data.length,
      last_page: parsedMeta?.last_page ?? 1,
    },
    trialBalance: parsedMeta?.trial_balance ?? null,
  }
}
export type FinanceLedgerResult = Awaited<ReturnType<typeof fetchFinanceLedger>>

export async function fetchFinanceReconciliations() {
  const { data, meta } = await api.getWithMeta<unknown[]>('/platform/finance/reconciliations')
  const parsedMeta = envelopeMetaSchema.parse(meta)
  return {
    reconciliations: z.array(financeReconciliationSchema).parse(data),
    pagination: {
      current_page: parsedMeta?.current_page ?? 1,
      per_page: parsedMeta?.per_page ?? 20,
      total: parsedMeta?.total ?? data.length,
      last_page: parsedMeta?.last_page ?? 1,
    },
  }
}

export async function createFinanceReconciliation(input: ReconciliationInput): Promise<FinanceReconciliation> {
  const data = await api.post<unknown>('/platform/finance/reconciliations', input)
  return financeReconciliationSchema.parse(data)
}

export async function fetchFinanceReconciliation(id: string): Promise<FinanceReconciliation> {
  const data = await api.get<unknown>(`/platform/finance/reconciliations/${id}`)
  return financeReconciliationSchema.parse(data)
}

export async function fetchFinanceRefunds(filters: { status?: string; page?: number } = {}) {
  const { data, meta } = await api.getWithMeta<unknown[]>(
    `/platform/finance/refunds${toQuery({ ...filters })}`,
  )
  const parsedMeta = envelopeMetaSchema.parse(meta)
  return {
    refunds: z.array(financeRefundSchema).parse(data),
    pagination: {
      current_page: parsedMeta?.current_page ?? 1,
      per_page: parsedMeta?.per_page ?? 25,
      total: parsedMeta?.total ?? data.length,
      last_page: parsedMeta?.last_page ?? 1,
    },
    counts: parsedMeta?.counts && !Array.isArray(parsedMeta.counts) ? parsedMeta.counts : {},
  }
}
export type FinanceRefundsResult = Awaited<ReturnType<typeof fetchFinanceRefunds>>

export async function createFinanceRefund(input: RefundInput): Promise<FinanceRefund> {
  const data = await api.post<unknown>('/platform/finance/refunds', input)
  return financeRefundSchema.parse(data)
}

export async function decideFinanceRefund(
  refundId: string,
  decision: 'approve' | 'process' | 'fail' | 'cancel',
  reference?: string,
  reason?: string,
): Promise<FinanceRefund> {
  const data = await api.post<unknown>(`/platform/finance/refunds/${refundId}/decision`, {
    decision,
    reference: reference ?? null,
    reason: reason ?? null,
  })
  return financeRefundSchema.parse(data)
}

export async function fetchFinanceOptions(): Promise<FinanceOptions> {
  const data = await api.get<unknown>('/platform/finance/options')
  return financeOptionsSchema.parse(data)
}
