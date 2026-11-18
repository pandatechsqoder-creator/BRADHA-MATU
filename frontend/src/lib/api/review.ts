import { api } from './client'

export interface ReviewItem {
  id: string
  status: string
  sku: string | null
  product_name: string | null
  counted_quantity: string | null
  expected_quantity: string | null
  variance: string | null
  tenant_comment: string | null
}

export interface DisputeMessage {
  id: string
  message: string
  sender_role: string
  sent_at: string
}

export interface Dispute {
  id: string
  status: string
  category: string
  priority: string
  reason: string
  raised_at: string
  resolved_at: string | null
  messages: DisputeMessage[]
  decisions: { id: string; decision: string; reasoning: string | null }[]
}

export interface ReconciliationItem {
  id: string
  sku: string | null
  product_name: string | null
  counted_quantity: string | null
  expected_quantity: string | null
  variance: string | null
  type: 'match' | 'variance' | 'unknown'
}

export interface Reconciliation {
  id: string
  status: string
  total_items: number
  items_matched: number
  items_with_variance: number
  items_unknown: number
  generated_at: string | null
  items: ReconciliationItem[]
}

export interface TenantReview {
  review: {
    id: string; status: string; total_items: number; items_accepted: number; items_flagged: number;
    expires_at: string | null; accepted_at: string | null; decision_notes: string | null;
  }
  job: { id: string; reference: string; title: string; status: string }
  items: ReviewItem[]
  disputes: Dispute[]
  reconciliation: Reconciliation | null
  expires_at: string | null
}

const BASE = (tenantId: string, jobId: string) => `/tenants/${tenantId}/stocktake-jobs/${jobId}/review`

export function getTenantReview(tenantId: string, jobId: string): Promise<TenantReview> {
  return api.get<TenantReview>(BASE(tenantId, jobId))
}

export function acceptResults(tenantId: string, jobId: string, notes?: string): Promise<{ status: string }> {
  return api.post<{ status: string }>(`${BASE(tenantId, jobId)}/accept`, { notes })
}

export function requestCorrection(tenantId: string, jobId: string, notes: string): Promise<{ status: string }> {
  return api.post<{ status: string }>(`${BASE(tenantId, jobId)}/correction`, { notes })
}

export function requestRecount(tenantId: string, jobId: string, reason: string): Promise<{ status: string }> {
  return api.post<{ status: string }>(`${BASE(tenantId, jobId)}/recount`, { reason })
}

export function raiseDispute(tenantId: string, jobId: string, data: Record<string, unknown>): Promise<Dispute> {
  return api.post<Dispute>(`${BASE(tenantId, jobId)}/dispute`, data)
}

export function flagItem(tenantId: string, jobId: string, itemId: string, comment: string): Promise<{ id: string; status: string }> {
  return api.put<{ id: string; status: string }>(`${BASE(tenantId, jobId)}/items/${itemId}/flag`, { comment })
}

export function generateReconciliation(tenantId: string, jobId: string): Promise<Reconciliation> {
  return api.post<Reconciliation>(`${BASE(tenantId, jobId)}/reconciliation`)
}
