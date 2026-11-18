import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  acceptWorkforceAssignment,
  createWorkforceProfile,
  declineWorkforceAssignment,
  fetchAssignmentRecommendations,
  fetchStockTaker,
  fetchStockTakers,
  fetchWorkforceAssignments,
  fetchWorkforceEarnings,
  fetchWorkforceProfile,
  offerAssignment,
  recordStockTakerTraining,
  replaceStockTakerSkills,
  replaceWorkforceAvailability,
  replaceWorkforceServiceAreas,
  replaceWorkforceSkills,
  updateStockTaker,
  updateWorkforceProfile,
  verifyStockTaker,
  withdrawAssignment,
  type AvailabilityInput,
  type OfferAssignmentInput,
  type ServiceAreaInput,
  type SkillInput,
  type StockTakerListFilters,
  type StockTakerManageInput,
  type WorkforceProfileInput,
} from '../api/workforce'

function useWorkforceInvalidator() {
  const queryClient = useQueryClient()
  return () => {
    void queryClient.invalidateQueries({ queryKey: ['workforce'] })
    void queryClient.invalidateQueries({ queryKey: ['platform', 'workforce'] })
    void queryClient.invalidateQueries({ queryKey: ['platform', 'stocktake-jobs'] })
    void queryClient.invalidateQueries({ queryKey: ['platform', 'assignment-recommendations'] })
    void queryClient.invalidateQueries({ queryKey: ['dashboard'] })
  }
}

export function useWorkforceProfile() {
  return useQuery({
    queryKey: ['workforce', 'profile'],
    queryFn: fetchWorkforceProfile,
    retry: false,
  })
}

export function useSaveWorkforceProfile() {
  const invalidate = useWorkforceInvalidator()
  return useMutation({
    mutationFn: (input: { profileId: string | null; values: WorkforceProfileInput }) =>
      input.profileId
        ? updateWorkforceProfile(input.values)
        : createWorkforceProfile(input.values),
    onSuccess: invalidate,
  })
}

export function useReplaceWorkforceSkills() {
  const invalidate = useWorkforceInvalidator()
  return useMutation({
    mutationFn: (skills: SkillInput[]) => replaceWorkforceSkills(skills),
    onSuccess: invalidate,
  })
}

export function useReplaceWorkforceServiceAreas() {
  const invalidate = useWorkforceInvalidator()
  return useMutation({
    mutationFn: (areas: ServiceAreaInput[]) => replaceWorkforceServiceAreas(areas),
    onSuccess: invalidate,
  })
}

export function useReplaceWorkforceAvailability() {
  const invalidate = useWorkforceInvalidator()
  return useMutation({
    mutationFn: (windows: AvailabilityInput[]) => replaceWorkforceAvailability(windows),
    onSuccess: invalidate,
  })
}

export function useWorkforceAssignments() {
  return useQuery({
    queryKey: ['workforce', 'assignments'],
    queryFn: fetchWorkforceAssignments,
    retry: false,
  })
}

export function useAcceptWorkforceAssignment() {
  const invalidate = useWorkforceInvalidator()
  return useMutation({
    mutationFn: (id: string) => acceptWorkforceAssignment(id),
    onSuccess: invalidate,
  })
}

export function useDeclineWorkforceAssignment() {
  const invalidate = useWorkforceInvalidator()
  return useMutation({
    mutationFn: (input: { id: string; reason: string }) =>
      declineWorkforceAssignment(input.id, input.reason),
    onSuccess: invalidate,
  })
}

export function useWorkforceEarnings() {
  return useQuery({
    queryKey: ['workforce', 'earnings'],
    queryFn: fetchWorkforceEarnings,
    retry: false,
  })
}

export function useStockTakers(filters: StockTakerListFilters = {}) {
  return useQuery({
    queryKey: ['platform', 'workforce', 'stock-takers', filters],
    queryFn: () => fetchStockTakers(filters),
    retry: false,
  })
}

export function useStockTaker(id: string | null) {
  return useQuery({
    queryKey: ['platform', 'workforce', 'stock-takers', id],
    queryFn: () => fetchStockTaker(id as string),
    enabled: Boolean(id),
    retry: false,
  })
}

export function useUpdateStockTaker(id: string) {
  const invalidate = useWorkforceInvalidator()
  return useMutation({
    mutationFn: (input: StockTakerManageInput) => updateStockTaker(id, input),
    onSuccess: invalidate,
  })
}

export function useVerifyStockTaker(id: string) {
  const invalidate = useWorkforceInvalidator()
  return useMutation({
    mutationFn: (input: Parameters<typeof verifyStockTaker>[1]) => verifyStockTaker(id, input),
    onSuccess: invalidate,
  })
}

export function useRecordStockTakerTraining(id: string) {
  const invalidate = useWorkforceInvalidator()
  return useMutation({
    mutationFn: (input: Parameters<typeof recordStockTakerTraining>[1]) =>
      recordStockTakerTraining(id, input),
    onSuccess: invalidate,
  })
}

export function useReplaceStockTakerSkills(id: string) {
  const invalidate = useWorkforceInvalidator()
  return useMutation({
    mutationFn: (skills: SkillInput[]) => replaceStockTakerSkills(id, skills),
    onSuccess: invalidate,
  })
}

export function useAssignmentRecommendations(jobId: string | null) {
  return useQuery({
    queryKey: ['platform', 'assignment-recommendations', jobId],
    queryFn: () => fetchAssignmentRecommendations(jobId as string),
    enabled: Boolean(jobId),
    retry: false,
  })
}

export function useOfferAssignment(jobId: string | null) {
  const invalidate = useWorkforceInvalidator()
  return useMutation({
    mutationFn: (input: OfferAssignmentInput) => offerAssignment(jobId as string, input),
    onSuccess: invalidate,
  })
}

export function useWithdrawAssignment() {
  const invalidate = useWorkforceInvalidator()
  return useMutation({
    mutationFn: (input: { id: string; reason?: string }) => withdrawAssignment(input.id, input.reason),
    onSuccess: invalidate,
  })
}
