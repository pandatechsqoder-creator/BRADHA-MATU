import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  fetchCountWorkspace,
  fetchPlatformJobCounts,
  fetchTenantJobCounts,
  reportCountException,
  reportUnknownProduct,
  reviseCountEntry,
  startCountSession,
  submitCountSession,
  upsertCountEntry,
  type CountSession,
  type CountWorkspace,
} from '../api/count'

function useCountInvalidator(assignmentId: string) {
  const queryClient = useQueryClient()
  return () => {
    void queryClient.invalidateQueries({ queryKey: ['count', 'workspace', assignmentId] })
  }
}

export function useCountWorkspace(assignmentId: string | null | undefined) {
  return useQuery({
    queryKey: ['count', 'workspace', assignmentId],
    queryFn: () => fetchCountWorkspace(assignmentId as string),
    enabled: Boolean(assignmentId),
    retry: false,
  })
}

export function useStartCountSession(assignmentId: string) {
  const queryClient = useQueryClient()
  const invalidate = useCountInvalidator(assignmentId)
  return useMutation({
    mutationFn: (data: { idempotency_key?: string } = {}) => startCountSession(assignmentId, data),
    onSuccess: (session) => {
      queryClient.setQueryData<CountWorkspace>(
        ['count', 'workspace', assignmentId],
        (prev) => (prev ? { ...prev, session } : prev),
      )
      invalidate()
    },
  })
}

export function useUpsertCountEntry(assignmentId: string, sessionId: string) {
  const invalidate = useCountInvalidator(assignmentId)
  return useMutation({
    mutationFn: (data: Parameters<typeof upsertCountEntry>[2]) =>
      upsertCountEntry(assignmentId, sessionId, data),
    onSuccess: () => invalidate(),
  })
}

export function useReviseCountEntry(assignmentId: string, sessionId: string) {
  const invalidate = useCountInvalidator(assignmentId)
  return useMutation({
    mutationFn: (args: { entryId: string } & Parameters<typeof reviseCountEntry>[3]) => {
      const { entryId, ...data } = args
      return reviseCountEntry(assignmentId, sessionId, entryId, data)
    },
    onSuccess: () => invalidate(),
  })
}

export function useReportException(assignmentId: string, sessionId: string) {
  const invalidate = useCountInvalidator(assignmentId)
  return useMutation({
    mutationFn: (data: Parameters<typeof reportCountException>[2]) =>
      reportCountException(assignmentId, sessionId, data),
    onSuccess: () => invalidate(),
  })
}

export function useReportUnknownProduct(assignmentId: string, sessionId: string) {
  const invalidate = useCountInvalidator(assignmentId)
  return useMutation({
    mutationFn: (data: Parameters<typeof reportUnknownProduct>[2]) =>
      reportUnknownProduct(assignmentId, sessionId, data),
    onSuccess: () => invalidate(),
  })
}

export function useSubmitCountSession(assignmentId: string, sessionId: string) {
  const queryClient = useQueryClient()
  const invalidate = useCountInvalidator(assignmentId)
  return useMutation({
    mutationFn: () => submitCountSession(assignmentId, sessionId),
    onSuccess: (session: CountSession) => {
      queryClient.setQueryData<CountWorkspace>(
        ['count', 'workspace', assignmentId],
        (prev) => (prev ? { ...prev, session } : prev),
      )
      invalidate()
      void queryClient.invalidateQueries({ queryKey: ['workforce', 'assignments'] })
    },
  })
}

export function usePlatformJobCounts(jobId: string | null | undefined, enabled = true) {
  return useQuery({
    queryKey: ['platform', 'stocktake-jobs', jobId, 'counts'],
    queryFn: () => fetchPlatformJobCounts(jobId as string),
    enabled: Boolean(jobId) && enabled,
    retry: false,
  })
}

export function useTenantJobCounts(
  tenantId: string | null | undefined,
  jobId: string | null | undefined,
  enabled = true,
) {
  return useQuery({
    queryKey: ['tenants', tenantId, 'stocktake-jobs', jobId, 'counts'],
    queryFn: () => fetchTenantJobCounts(tenantId as string, jobId as string),
    enabled: Boolean(tenantId && jobId) && enabled,
    retry: false,
  })
}
