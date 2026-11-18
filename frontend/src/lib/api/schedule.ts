import { z } from 'zod'
import { api } from './client'
import {
  type CreateScheduleInput,
  type GenerateOccurrencesInput,
  type Schedule,
  type ScheduleOccurrence,
  type ScheduleWithOccurrences,
  type SkipOccurrenceInput,
  type UpdateScheduleInput,
  createScheduleInputSchema,
  generateOccurrencesInputSchema,
  scheduleOccurrenceSchema,
  scheduleSchema,
  scheduleWithOccurrencesSchema,
  skipOccurrenceInputSchema,
  updateScheduleInputSchema,
} from './schemas'

export type {
  CreateScheduleInput,
  GenerateOccurrencesInput,
  Schedule,
  ScheduleOccurrence,
  ScheduleWithOccurrences,
  SkipOccurrenceInput,
  UpdateScheduleInput,
}

export async function fetchTenantSchedules(tenantId: string): Promise<Schedule[]> {
  return z.array(scheduleSchema).parse(await api.get<unknown>(`/tenants/${tenantId}/schedules`))
}

export async function fetchTenantSchedule(
  tenantId: string,
  scheduleId: string,
): Promise<ScheduleWithOccurrences> {
  return scheduleWithOccurrencesSchema.parse(
    await api.get<unknown>(`/tenants/${tenantId}/schedules/${scheduleId}`),
  )
}

export async function createTenantSchedule(
  tenantId: string,
  input: CreateScheduleInput,
): Promise<Schedule> {
  createScheduleInputSchema.parse(input)
  return scheduleSchema.parse(
    await api.post<unknown>(`/tenants/${tenantId}/schedules`, input),
  )
}

export async function updateTenantSchedule(
  tenantId: string,
  scheduleId: string,
  input: UpdateScheduleInput,
): Promise<Schedule> {
  updateScheduleInputSchema.parse(input)
  return scheduleSchema.parse(
    await api.patch<unknown>(`/tenants/${tenantId}/schedules/${scheduleId}`, input),
  )
}

export async function generateScheduleOccurrences(
  tenantId: string,
  scheduleId: string,
  input?: GenerateOccurrencesInput,
): Promise<{ generated: number; schedule: Schedule }> {
  generateOccurrencesInputSchema.parse(input ?? {})
  return z
    .object({ generated: z.number(), schedule: scheduleSchema })
    .parse(
      await api.post<unknown>(
        `/tenants/${tenantId}/schedules/${scheduleId}/generate`,
        input ?? { count: 3 },
      ),
    )
}

export async function triggerOnDemandSchedule(
  tenantId: string,
  scheduleId: string,
): Promise<ScheduleOccurrence> {
  return scheduleOccurrenceSchema.parse(
    await api.post<unknown>(`/tenants/${tenantId}/schedules/${scheduleId}/trigger`),
  )
}

export async function skipScheduleOccurrence(
  tenantId: string,
  occurrenceId: string,
  input: SkipOccurrenceInput,
): Promise<ScheduleOccurrence> {
  skipOccurrenceInputSchema.parse(input)
  return scheduleOccurrenceSchema.parse(
    await api.post<unknown>(
      `/tenants/${tenantId}/schedule-occurrences/${occurrenceId}/skip`,
      input,
    ),
  )
}
