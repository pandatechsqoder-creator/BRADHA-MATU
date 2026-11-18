import { api } from './client'

export interface QaFinding {
  id: string
  type: string
  severity: string
  classification: string | null
  counted_quantity: string | null
  verified_quantity: string | null
  variance: string | null
  notes: string | null
  resolution: string
}

export interface ReviewEntry {
  id: string
  variant_id: string | null
  sku: string | null
  product_name: string | null
  raw_quantity: string
  unit: string | null
  observations: string[]
  notes: string | null
  has_finding: boolean
  finding: QaFinding | null
}

export interface VerificationSample {
  id: string
  result: string
  original_quantity: string | null
  verified_quantity: string | null
  variance: string | null
}

export interface VerificationTask {
  id: string
  status: string
  sample_size: number
  items_verified: number
  items_matched: number
  items_mismatched: number
  summary: string | null
  completed_at: string | null
  samples: VerificationSample[]
}

export interface RecountRequest {
  id: string
  status: string
  reason: string
  cost_responsibility: string
  requested_at: string
  approved_at: string | null
  completed_at: string | null
}

export interface WorkerAccuracy {
  total_entries: number
  total_discrepancies: number
  worker_errors: number
  expected_variance: number
  system_errors: number
  accuracy_rate: number | null
  note: string
}

export interface QaReviewPayload {
  review: {
    id: string
    status: string
    decision: string | null
    summary: string | null
    findings_count: number
    entries_reviewed: number
    started_at: string | null
    completed_at: string | null
  }
  job: { id: string; reference: string; title: string; status: string }
  session_id: string | null
  entries: ReviewEntry[]
  findings: QaFinding[]
  verification_tasks: VerificationTask[]
  recount_requests: RecountRequest[]
  worker_accuracy: WorkerAccuracy
}

export function startQaReview(jobId: string): Promise<QaReviewPayload> {
  return api.post<QaReviewPayload>(`/platform/stocktake-jobs/${jobId}/qa/review`)
}

export function getQaReview(jobId: string): Promise<QaReviewPayload> {
  return api.get<QaReviewPayload>(`/platform/stocktake-jobs/${jobId}/qa/review`)
}

export function recordFinding(jobId: string, data: Record<string, unknown>): Promise<QaFinding> {
  return api.post<QaFinding>(`/platform/stocktake-jobs/${jobId}/qa/findings`, data)
}

export function completeReview(jobId: string, data: Record<string, unknown>): Promise<unknown> {
  return api.post(`/platform/stocktake-jobs/${jobId}/qa/complete`, data)
}

export function createVerificationTask(jobId: string, data: { sample_size: number }): Promise<VerificationTask> {
  return api.post<VerificationTask>(`/platform/stocktake-jobs/${jobId}/verification-tasks`, data)
}

export function recordSample(jobId: string, taskId: string, sampleId: string, data: { verified_quantity: number; notes?: string }): Promise<{ id: string; result: string; variance: number }> {
  return api.put(`/platform/stocktake-jobs/${jobId}/verification-tasks/${taskId}/samples/${sampleId}`, data)
}

export function completeVerificationTask(jobId: string, taskId: string): Promise<VerificationTask> {
  return api.post<VerificationTask>(`/platform/stocktake-jobs/${jobId}/verification-tasks/${taskId}/complete`)
}

export function requestRecount(jobId: string, data: Record<string, unknown>): Promise<RecountRequest> {
  return api.post<RecountRequest>(`/platform/stocktake-jobs/${jobId}/recount-requests`, data)
}

export function approveRecount(jobId: string, recountId: string): Promise<RecountRequest> {
  return api.post<RecountRequest>(`/platform/stocktake-jobs/${jobId}/recount-requests/${recountId}/approve`)
}

export function completeRecount(jobId: string, recountId: string): Promise<RecountRequest> {
  return api.post<RecountRequest>(`/platform/stocktake-jobs/${jobId}/recount-requests/${recountId}/complete`)
}

export function resolveDiscrepancy(discrepancyId: string, data: { type?: string; resolution: string }): Promise<{ id: string; status: string }> {
  return api.post<{ id: string; status: string }>(`/platform/discrepancies/${discrepancyId}/resolve`, data)
}
