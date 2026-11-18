import { api } from './client'

export interface Payout {
  id: string; profile_id: string; amount: number; method: string
  recipient: string | null; status: string; completed_at: string | null
}

export interface PayoutBatch {
  id: string; reference: string; status: string; total_amount: number; currency: string
  payout_count: number; prepared_at: string | null; approved_at: string | null
  completed_at: string | null; payouts: Payout[]
}

export const fetchPendingBatches = (): Promise<PayoutBatch[]> =>
  api.get<PayoutBatch[]>('/platform/payouts')

export const prepareBatch = (jobId: string, method: string): Promise<PayoutBatch> =>
  api.post<PayoutBatch>(`/platform/stocktake-jobs/${jobId}/payouts`, { method })

export const approveBatch = (batchId: string, notes?: string): Promise<PayoutBatch> =>
  api.post<PayoutBatch>(`/platform/payouts/${batchId}/approve`, { notes })

export const processBatch = (batchId: string): Promise<PayoutBatch> =>
  api.post<PayoutBatch>(`/platform/payouts/${batchId}/process`)

export const closeJob = (jobId: string): Promise<{ status: string; closed_at: string }> =>
  api.post(`/platform/stocktake-jobs/${jobId}/close`)
