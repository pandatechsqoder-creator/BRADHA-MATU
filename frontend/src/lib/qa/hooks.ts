import { useMutation, useQuery, useQueryClient, type QueryClient } from '@tanstack/react-query'
import {
  approveRecount, completeRecount, completeReview, completeVerificationTask, createVerificationTask,
  getQaReview, recordFinding, recordSample, requestRecount, startQaReview,
  type QaReviewPayload,
} from '../api/qa'

function invalidate(queryClient: QueryClient, jobId: string) {
  void queryClient.invalidateQueries({ queryKey: ['qa', 'review', jobId] })
  void queryClient.invalidateQueries({ queryKey: ['platform', 'stocktake-jobs', jobId] })
}

export function useQaReview(jobId: string | null | undefined) {
  return useQuery({
    queryKey: ['qa', 'review', jobId],
    queryFn: () => getQaReview(jobId as string),
    enabled: Boolean(jobId),
    retry: false,
  })
}

export function useStartQaReview(jobId: string) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: () => startQaReview(jobId),
    onSuccess: (payload: QaReviewPayload) => {
      qc.setQueryData(['qa', 'review', jobId], payload)
      invalidate(qc, jobId)
    },
  })
}

export function useRecordFinding(jobId: string) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (data: Record<string, unknown>) => recordFinding(jobId, data),
    onSuccess: () => invalidate(qc, jobId),
  })
}

export function useCompleteReview(jobId: string) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (data: Record<string, unknown>) => completeReview(jobId, data),
    onSuccess: () => invalidate(qc, jobId),
  })
}

export function useCreateVerificationTask(jobId: string) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (data: { sample_size: number }) => createVerificationTask(jobId, data),
    onSuccess: () => invalidate(qc, jobId),
  })
}

export function useRecordSample(jobId: string, taskId: string) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (args: { sampleId: string; verified_quantity: number; notes?: string }) =>
      recordSample(jobId, taskId, args.sampleId, { verified_quantity: args.verified_quantity, notes: args.notes }),
    onSuccess: () => invalidate(qc, jobId),
  })
}

export function useCompleteVerificationTask(jobId: string) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (taskId: string) => completeVerificationTask(jobId, taskId),
    onSuccess: () => invalidate(qc, jobId),
  })
}

export function useRequestRecount(jobId: string) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (data: Record<string, unknown>) => requestRecount(jobId, data),
    onSuccess: () => invalidate(qc, jobId),
  })
}

export function useApproveRecount(jobId: string) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (recountId: string) => approveRecount(jobId, recountId),
    onSuccess: () => invalidate(qc, jobId),
  })
}

export function useCompleteRecount(jobId: string) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: (recountId: string) => completeRecount(jobId, recountId),
    onSuccess: () => invalidate(qc, jobId),
  })
}
