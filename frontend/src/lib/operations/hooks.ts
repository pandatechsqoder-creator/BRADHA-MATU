import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  acknowledgeWorkerProcedure,
  applyJobProcedure,
  checkInWorker,
  checkOutWorker,
  createProcedureTemplate,
  fetchOperationsDashboard,
  fetchPlatformJobOperations,
  fetchProcedureTemplates,
  fetchTenantJobOperations,
  fetchWorkerOperations,
  publishProcedureVersion,
  respondChecklistItem,
  type ApplyProcedureInput,
  type CheckInInput,
  type ProcedureTemplateInput,
  type WorkerOperations,
} from '../api/operations'

function useWorkerOperationsInvalidator(assignmentId: string) {
  const queryClient = useQueryClient()
  return () => {
    void queryClient.invalidateQueries({ queryKey: ['operations', 'worker', assignmentId] })
    void queryClient.invalidateQueries({ queryKey: ['workforce', 'assignments'] })
    void queryClient.invalidateQueries({ queryKey: ['workforce', 'earnings'] })
    void queryClient.invalidateQueries({ queryKey: ['platform', 'operations'] })
  }
}

export function useWorkerOperations(assignmentId: string | null | undefined) {
  return useQuery({
    queryKey: ['operations', 'worker', assignmentId],
    queryFn: () => fetchWorkerOperations(assignmentId as string),
    enabled: Boolean(assignmentId),
    retry: false,
  })
}

function useWorkerOperationAction<TInput>(
  assignmentId: string,
  action: (input: TInput) => Promise<WorkerOperations>,
) {
  const queryClient = useQueryClient()
  const invalidate = useWorkerOperationsInvalidator(assignmentId)
  return useMutation({
    mutationFn: action,
    onSuccess: (payload) => {
      queryClient.setQueryData(['operations', 'worker', assignmentId], payload)
      invalidate()
    },
  })
}

export function useAcknowledgeProcedure(assignmentId: string) {
  return useWorkerOperationAction<void>(assignmentId, () => acknowledgeWorkerProcedure(assignmentId))
}

export function useRespondChecklistItem(assignmentId: string) {
  return useWorkerOperationAction<{ itemId: string; value: string; notes?: string | null }>(
    assignmentId,
    (input) => respondChecklistItem(assignmentId, input.itemId, { value: input.value, notes: input.notes }),
  )
}

export function useCheckIn(assignmentId: string) {
  return useWorkerOperationAction<CheckInInput>(assignmentId, (input) => checkInWorker(assignmentId, input))
}

export function useCheckOut(assignmentId: string) {
  return useWorkerOperationAction<string | null>(assignmentId, (notes) => checkOutWorker(assignmentId, notes))
}

export function usePlatformOperationsDashboard() {
  return useQuery({
    queryKey: ['platform', 'operations'],
    queryFn: fetchOperationsDashboard,
    retry: false,
    refetchInterval: 30_000,
  })
}

export function usePlatformJobOperations(jobId: string | null | undefined, enabled = true) {
  return useQuery({
    queryKey: ['platform', 'stocktake-jobs', jobId, 'operations'],
    queryFn: () => fetchPlatformJobOperations(jobId as string),
    enabled: Boolean(jobId) && enabled,
    retry: false,
    refetchInterval: 30_000,
  })
}

export function useTenantJobOperations(
  tenantId: string | null | undefined,
  jobId: string | null | undefined,
  enabled = true,
) {
  return useQuery({
    queryKey: ['tenants', tenantId, 'stocktake-jobs', jobId, 'operations'],
    queryFn: () => fetchTenantJobOperations(tenantId as string, jobId as string),
    enabled: Boolean(tenantId && jobId) && enabled,
    retry: false,
    refetchInterval: 30_000,
  })
}

export function useProcedureTemplates(search?: string) {
  return useQuery({
    queryKey: ['platform', 'procedure-templates', search ?? ''],
    queryFn: () => fetchProcedureTemplates(search),
    retry: false,
  })
}

export function useCreateProcedureTemplate() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (input: ProcedureTemplateInput) => createProcedureTemplate(input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['platform', 'procedure-templates'] })
    },
  })
}

export function usePublishProcedureVersion(templateId: string) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (input: Partial<ProcedureTemplateInput> & { steps: ProcedureTemplateInput['steps'] }) =>
      publishProcedureVersion(templateId, input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['platform', 'procedure-templates'] })
    },
  })
}

export function useApplyJobProcedure(jobId: string) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (input: ApplyProcedureInput) => applyJobProcedure(jobId, input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['platform', 'stocktake-jobs', jobId, 'operations'] })
      void queryClient.invalidateQueries({ queryKey: ['platform', 'stocktake-jobs', jobId] })
      void queryClient.invalidateQueries({ queryKey: ['platform', 'stocktake-jobs'] })
      void queryClient.invalidateQueries({ queryKey: ['platform', 'operations'] })
    },
  })
}
