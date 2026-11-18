import { useMutation, useQuery, useQueryClient, type QueryClient } from '@tanstack/react-query'
import {
  acceptResults, flagItem, generateReconciliation, getTenantReview, raiseDispute,
  requestCorrection, requestRecount,
} from '../api/review'

function invalidate(qc: QueryClient, tenantId: string, jobId: string) {
  void qc.invalidateQueries({ queryKey: ['tenant-review', tenantId, jobId] })
  void qc.invalidateQueries({ queryKey: ['tenants', tenantId, 'stocktake-jobs', jobId] })
}

export function useTenantReview(tenantId: string | null | undefined, jobId: string | null | undefined) {
  return useQuery({
    queryKey: ['tenant-review', tenantId, jobId],
    queryFn: () => getTenantReview(tenantId as string, jobId as string),
    enabled: Boolean(tenantId && jobId),
    retry: false,
  })
}

export function useAcceptResults(tenantId: string, jobId: string) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (notes?: string) => acceptResults(tenantId, jobId, notes),
    onSuccess: () => invalidate(qc, tenantId, jobId),
  })
}

export function useRequestCorrection(tenantId: string, jobId: string) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (notes: string) => requestCorrection(tenantId, jobId, notes),
    onSuccess: () => invalidate(qc, tenantId, jobId),
  })
}

export function useRequestRecount(tenantId: string, jobId: string) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (reason: string) => requestRecount(tenantId, jobId, reason),
    onSuccess: () => invalidate(qc, tenantId, jobId),
  })
}

export function useRaiseDispute(tenantId: string, jobId: string) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (data: Record<string, unknown>) => raiseDispute(tenantId, jobId, data),
    onSuccess: () => invalidate(qc, tenantId, jobId),
  })
}

export function useFlagItem(tenantId: string, jobId: string) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: ({ itemId, comment }: { itemId: string; comment: string }) =>
      flagItem(tenantId, jobId, itemId, comment),
    onSuccess: () => invalidate(qc, tenantId, jobId),
  })
}

export function useGenerateReconciliation(tenantId: string, jobId: string) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: () => generateReconciliation(tenantId, jobId),
    onSuccess: () => invalidate(qc, tenantId, jobId),
  })
}
