import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  acceptTenantQuote,
  amendTenantJob,
  cancelTenantJob,
  createTenantJob,
  decidePlatformAmendment,
  declineTenantQuote,
  estimateTenantJob,
  fetchPlatformJob,
  fetchPlatformJobs,
  fetchStocktakePolicy,
  fetchTenantJob,
  fetchTenantJobs,
  issuePlatformQuote,
  payTenantJob,
  simulateTenantPayment,
  requestPlatformInformation,
  startPlatformReview,
  submitTenantJob,
  updateStocktakePolicy,
  updateTenantJob,
  verifyPlatformDeposit,
  waivePlatformDeposit,
  type PaymentInput,
  type PlatformJobFilters,
  type QuoteInput,
  type StocktakeJobInput,
  type StocktakePolicy,
  type StocktakeScopeInput,
  type TenantJobFilters,
} from '../api/stocktake'

function useTenantJobInvalidator(tenantId: string | undefined) {
  const queryClient = useQueryClient()
  return () => {
    void queryClient.invalidateQueries({ queryKey: ['tenants', tenantId, 'stocktake-jobs'] })
    void queryClient.invalidateQueries({ queryKey: ['dashboard'] })
  }
}

function usePlatformJobInvalidator() {
  const queryClient = useQueryClient()
  return () => {
    void queryClient.invalidateQueries({ queryKey: ['platform', 'stocktake-jobs'] })
  }
}

export function useTenantStocktakeJobs(tenantId: string | null | undefined, filters: TenantJobFilters = {}) {
  return useQuery({
    queryKey: ['tenants', tenantId, 'stocktake-jobs', 'list', filters],
    queryFn: () => fetchTenantJobs(tenantId as string, filters),
    enabled: Boolean(tenantId),
    retry: false,
  })
}

export function useTenantStocktakeJob(tenantId: string | null | undefined, jobId: string | null | undefined) {
  return useQuery({
    queryKey: ['tenants', tenantId, 'stocktake-jobs', jobId],
    queryFn: () => fetchTenantJob(tenantId as string, jobId as string),
    enabled: Boolean(tenantId && jobId),
    retry: false,
  })
}

export function useStocktakeEstimate(
  tenantId: string | null | undefined,
  input: StocktakeScopeInput | null,
) {
  return useQuery({
    queryKey: ['tenants', tenantId, 'stocktake-jobs', 'estimate', input],
    queryFn: () => estimateTenantJob(tenantId as string, input as StocktakeScopeInput),
    enabled: Boolean(tenantId && input),
    retry: false,
    staleTime: 15_000,
  })
}

export function useCreateStocktakeJob(tenantId: string | undefined) {
  const invalidate = useTenantJobInvalidator(tenantId)
  return useMutation({
    mutationFn: (input: StocktakeJobInput) => createTenantJob(tenantId as string, input),
    onSuccess: invalidate,
  })
}

export function useUpdateStocktakeJob(tenantId: string | undefined, jobId: string | undefined) {
  const queryClient = useQueryClient()
  const invalidate = useTenantJobInvalidator(tenantId)
  return useMutation({
    mutationFn: (input: Partial<StocktakeJobInput>) =>
      updateTenantJob(tenantId as string, jobId as string, input),
    onSuccess: (job) => {
      queryClient.setQueryData(['tenants', tenantId, 'stocktake-jobs', jobId], job)
      invalidate()
    },
  })
}

export function useSubmitStocktakeJob(tenantId: string | undefined, jobId: string | undefined) {
  const queryClient = useQueryClient()
  const invalidate = useTenantJobInvalidator(tenantId)
  return useMutation({
    mutationFn: (requestDeclaration: { confirm_accuracy: boolean; exceptions?: string | null }) =>
      submitTenantJob(tenantId as string, jobId as string, requestDeclaration),
    onSuccess: (job) => {
      queryClient.setQueryData(['tenants', tenantId, 'stocktake-jobs', jobId], job)
      invalidate()
    },
  })
}

export function usePayStocktakeJob(tenantId: string | undefined, jobId: string | undefined) {
  const queryClient = useQueryClient()
  const invalidate = useTenantJobInvalidator(tenantId)
  return useMutation({
    mutationFn: (input: { data: PaymentInput; idempotencyKey: string }) =>
      payTenantJob(tenantId as string, jobId as string, input.data, input.idempotencyKey),
    onSuccess: ({ job }) => {
      queryClient.setQueryData(['tenants', tenantId, 'stocktake-jobs', jobId], job)
      invalidate()
    },
  })
}

export function useSimulateStocktakePayment(tenantId: string | undefined, jobId: string | undefined) {
  const queryClient = useQueryClient()
  const invalidate = useTenantJobInvalidator(tenantId)
  return useMutation({
    mutationFn: (input: { paymentId: string; outcome: 'success' | 'failure' }) =>
      simulateTenantPayment(tenantId as string, jobId as string, input.paymentId, input.outcome),
    onSuccess: ({ job }) => {
      queryClient.setQueryData(['tenants', tenantId, 'stocktake-jobs', jobId], job)
      invalidate()
    },
  })
}

export function useAcceptStocktakeQuote(tenantId: string | undefined, jobId: string | undefined) {
  const queryClient = useQueryClient()
  const invalidate = useTenantJobInvalidator(tenantId)
  return useMutation({
    mutationFn: () => acceptTenantQuote(tenantId as string, jobId as string),
    onSuccess: (job) => {
      queryClient.setQueryData(['tenants', tenantId, 'stocktake-jobs', jobId], job)
      invalidate()
    },
  })
}

export function useDeclineStocktakeQuote(tenantId: string | undefined, jobId: string | undefined) {
  const queryClient = useQueryClient()
  const invalidate = useTenantJobInvalidator(tenantId)
  return useMutation({
    mutationFn: (reason: string) => declineTenantQuote(tenantId as string, jobId as string, reason),
    onSuccess: (job) => {
      queryClient.setQueryData(['tenants', tenantId, 'stocktake-jobs', jobId], job)
      invalidate()
    },
  })
}

export function useCancelStocktakeJob(tenantId: string | undefined, jobId: string | undefined) {
  const queryClient = useQueryClient()
  const invalidate = useTenantJobInvalidator(tenantId)
  return useMutation({
    mutationFn: (reason: string) => cancelTenantJob(tenantId as string, jobId as string, reason),
    onSuccess: (job) => {
      queryClient.setQueryData(['tenants', tenantId, 'stocktake-jobs', jobId], job)
      invalidate()
    },
  })
}

export function useAmendStocktakeJob(tenantId: string | undefined, jobId: string | undefined) {
  const queryClient = useQueryClient()
  const invalidate = useTenantJobInvalidator(tenantId)
  return useMutation({
    mutationFn: (input: { reason: string; changes: Record<string, unknown> }) =>
      amendTenantJob(tenantId as string, jobId as string, input),
    onSuccess: ({ job }) => {
      queryClient.setQueryData(['tenants', tenantId, 'stocktake-jobs', jobId], job)
      invalidate()
    },
  })
}

export function usePlatformStocktakeJobs(filters: PlatformJobFilters = {}) {
  return useQuery({
    queryKey: ['platform', 'stocktake-jobs', 'list', filters],
    queryFn: () => fetchPlatformJobs(filters),
    retry: false,
  })
}

export function usePlatformStocktakeJob(jobId: string | null | undefined) {
  return useQuery({
    queryKey: ['platform', 'stocktake-jobs', jobId],
    queryFn: () => fetchPlatformJob(jobId as string),
    enabled: Boolean(jobId),
    retry: false,
  })
}

export function useStocktakePolicy() {
  return useQuery({
    queryKey: ['platform', 'stocktake-policy'],
    queryFn: fetchStocktakePolicy,
    retry: false,
  })
}

export function useUpdateStocktakePolicy() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (input: Partial<Omit<StocktakePolicy, 'id' | 'updated_at'>>) =>
      updateStocktakePolicy(input),
    onSuccess: (policy) => {
      queryClient.setQueryData(['platform', 'stocktake-policy'], policy)
    },
  })
}

function usePlatformJobAction<TInput>(
  jobId: string | undefined,
  action: (input: TInput) => Promise<import('../api/stocktake').PlatformStocktakeJob>,
) {
  const queryClient = useQueryClient()
  const invalidate = usePlatformJobInvalidator()
  return useMutation({
    mutationFn: action,
    onSuccess: (job) => {
      queryClient.setQueryData(['platform', 'stocktake-jobs', jobId], job)
      invalidate()
    },
  })
}

export function useStartPlatformReview(jobId: string | undefined) {
  return usePlatformJobAction<void>(jobId, () => startPlatformReview(jobId as string))
}

export function useRequestPlatformInformation(jobId: string | undefined) {
  return usePlatformJobAction(jobId, (reason: string) =>
    requestPlatformInformation(jobId as string, reason),
  )
}

export function useIssuePlatformQuote(jobId: string | undefined) {
  return usePlatformJobAction(jobId, (input: QuoteInput) => issuePlatformQuote(jobId as string, input))
}

export function useVerifyPlatformDeposit(jobId: string | undefined) {
  return usePlatformJobAction(
    jobId,
    (input: { paymentId: string; decision: 'confirm' | 'fail'; reason?: string }) =>
      verifyPlatformDeposit(jobId as string, input.paymentId, input.decision, input.reason),
  )
}

export function useWaivePlatformDeposit(jobId: string | undefined) {
  return usePlatformJobAction(jobId, (reason: string) =>
    waivePlatformDeposit(jobId as string, reason),
  )
}

export function useDecidePlatformAmendment(jobId: string | undefined) {
  return usePlatformJobAction(
    jobId,
    (input: {
      amendmentId: string
      decision: 'approve' | 'reject'
      reason?: string
      financial_impact?: number
    }) => decidePlatformAmendment(jobId as string, input.amendmentId, input),
  )
}
