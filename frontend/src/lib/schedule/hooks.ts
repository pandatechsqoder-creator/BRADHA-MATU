import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  createTenantSchedule,
  fetchTenantSchedule,
  fetchTenantSchedules,
  generateScheduleOccurrences,
  skipScheduleOccurrence,
  triggerOnDemandSchedule,
  updateTenantSchedule,
  type CreateScheduleInput,
  type Schedule,
  type ScheduleOccurrence,
  type UpdateScheduleInput,
} from '../api/schedule'

export function useTenantSchedules(tenantId: string | null | undefined) {
  return useQuery({
    queryKey: ['tenants', tenantId, 'schedules'],
    queryFn: () => fetchTenantSchedules(tenantId as string),
    enabled: Boolean(tenantId),
    retry: false,
  })
}

export function useTenantSchedule(
  tenantId: string | null | undefined,
  scheduleId: string | null | undefined,
) {
  return useQuery({
    queryKey: ['tenants', tenantId, 'schedules', scheduleId],
    queryFn: () =>
      fetchTenantSchedule(tenantId as string, scheduleId as string),
    enabled: Boolean(tenantId && scheduleId),
    retry: false,
  })
}

export function useCreateTenantSchedule(tenantId: string | undefined) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (input: CreateScheduleInput) =>
      createTenantSchedule(tenantId as string, input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['tenants', tenantId, 'schedules'] })
    },
  })
}

export function useUpdateTenantSchedule(
  tenantId: string | undefined,
  scheduleId: string | undefined,
) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (input: UpdateScheduleInput) =>
      updateTenantSchedule(tenantId as string, scheduleId as string, input),
    onSuccess: (schedule: Schedule) => {
      queryClient.setQueryData(
        ['tenants', tenantId, 'schedules', scheduleId],
        schedule,
      )
      void queryClient.invalidateQueries({ queryKey: ['tenants', tenantId, 'schedules'] })
    },
  })
}

export function useGenerateScheduleOccurrences(
  tenantId: string | undefined,
  scheduleId: string | undefined,
) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (input?: { count?: number }) =>
      generateScheduleOccurrences(tenantId as string, scheduleId as string, { count: input?.count ?? 3 }),
    onSuccess: () => {
      void queryClient.invalidateQueries({
        queryKey: ['tenants', tenantId, 'schedules', scheduleId],
      })
      void queryClient.invalidateQueries({
        queryKey: ['tenants', tenantId, 'schedules'],
      })
    },
  })
}

export function useTriggerOnDemandSchedule(
  tenantId: string | undefined,
  scheduleId: string | undefined,
) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: () =>
      triggerOnDemandSchedule(tenantId as string, scheduleId as string),
    onSuccess: (occurrence: ScheduleOccurrence) => {
      queryClient.setQueryData(
        ['tenants', tenantId, 'schedules', scheduleId, 'occurrences'],
        (old: ScheduleOccurrence[] = []) => [...old, occurrence],
      )
      void queryClient.invalidateQueries({
        queryKey: ['tenants', tenantId, 'schedules', scheduleId],
      })
      void queryClient.invalidateQueries({ queryKey: ['tenants', tenantId, 'schedules'] })
    },
  })
}

export function useSkipScheduleOccurrence(
  tenantId: string | undefined,
  scheduleId: string | undefined,
) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (input: { occurrenceId: string; reason: string }) =>
      skipScheduleOccurrence(tenantId as string, input.occurrenceId, {
        reason: input.reason,
      }),
    onSuccess: () => {
      void queryClient.invalidateQueries({
        queryKey: ['tenants', tenantId, 'schedules', scheduleId],
      })
      void queryClient.invalidateQueries({ queryKey: ['tenants', tenantId, 'schedules'] })
    },
  })
}
