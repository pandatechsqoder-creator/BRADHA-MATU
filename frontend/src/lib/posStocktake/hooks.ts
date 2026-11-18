import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  authorizeStocktakeJob,
  checkCatalogueReadiness,
  commissionStocktake,
  declineStocktakeJob,
  fetchPosStocktakeEligibility,
  fetchPosStocktakeJobs,
  generateQuoteFromPos,
  type CommissionStocktakeInput,
} from '../api/posStocktake'

export function useCatalogueReadiness(tenantId: string | null | undefined) {
  return useQuery({
    queryKey: ['tenants', tenantId, 'pos', 'catalogue-readiness'],
    queryFn: () => checkCatalogueReadiness(tenantId as string),
    enabled: Boolean(tenantId),
    retry: false,
  })
}

export function useFetchPosStocktakeEligibility(tenantId: string | null | undefined) {
  return useQuery({
    queryKey: ['tenants', tenantId, 'pos', 'eligibility'],
    queryFn: () => fetchPosStocktakeEligibility(tenantId as string),
    enabled: Boolean(tenantId),
    retry: false,
  })
}

export function usePosStocktakeJobs(tenantId: string | null | undefined) {
  return useQuery({
    queryKey: ['tenants', tenantId, 'pos', 'stocktake-jobs'],
    queryFn: () => fetchPosStocktakeJobs(tenantId as string),
    enabled: Boolean(tenantId),
    retry: false,
  })
}

function useStocktakeInvalidator(tenantId: string | undefined) {
  const queryClient = useQueryClient()
  return () => {
    void queryClient.invalidateQueries({ queryKey: ['tenants', tenantId, 'pos', 'stocktake-jobs'] })
    void queryClient.invalidateQueries({ queryKey: ['tenants', tenantId, 'pos', 'eligibility'] })
    void queryClient.invalidateQueries({ queryKey: ['tenants', tenantId, 'pos', 'catalogue-readiness'] })
  }
}

export function useCommissionStocktake(tenantId: string | undefined) {
  const invalidate = useStocktakeInvalidator(tenantId)
  return useMutation({
    mutationFn: (input: CommissionStocktakeInput) => commissionStocktake(tenantId as string, input),
    onSuccess: invalidate,
  })
}

export function useAuthorizeStocktakeJob(tenantId: string | undefined) {
  const invalidate = useStocktakeInvalidator(tenantId)
  return useMutation({
    mutationFn: (jobId: string) => authorizeStocktakeJob(tenantId as string, jobId),
    onSuccess: invalidate,
  })
}

export function useDeclineStocktakeJob(tenantId: string | undefined) {
  const invalidate = useStocktakeInvalidator(tenantId)
  return useMutation({
    mutationFn: ({ jobId, reason }: { jobId: string; reason: string }) =>
      declineStocktakeJob(tenantId as string, jobId, reason),
    onSuccess: invalidate,
  })
}

export function useGenerateQuoteFromPos(tenantId: string | undefined) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (saleId: string) => generateQuoteFromPos(tenantId as string, saleId),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['tenants', tenantId, 'pos', 'sales'] })
    },
  })
}
