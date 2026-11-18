import { z } from 'zod'
import { api } from './client'

const nullableString = z.string().nullable().optional()

export const workforceAssignmentSchema = z.object({
  id: z.string(),
  status: z.string(),
  role: z.string(),
  compensation: z.object({ amount: z.number(), currency: z.string() }),
  offer_expires_at: nullableString,
  offered_at: nullableString,
  responded_at: nullableString,
  accepted_at: nullableString,
  declined_reason: nullableString,
  profile: z
    .object({
      id: nullableString,
      name: nullableString,
      email: nullableString,
      level: nullableString,
      phone: nullableString,
      city: nullableString,
    })
    .nullable()
    .optional(),
  earning: z
    .object({
      status: z.string(),
      amount: z.number(),
      currency: z.string(),
      reference: z.string(),
    })
    .nullable()
    .optional(),
  earnings: z
    .array(
      z.object({
        reference: z.string(),
        amount: z.number(),
        status: z.string(),
        currency: z.string().optional(),
      }),
    )
    .optional(),
  job: z
    .object({
      id: z.string(),
      reference: z.string(),
      title: z.string(),
      status: z.string(),
      priority: z.string().nullable().optional(),
      count_method: z.string().nullable().optional(),
      preferred_start_at: nullableString,
      deadline_at: nullableString,
      required_team_size: z.number().optional(),
      scope_label: nullableString,
      location_count: z.number().optional(),
      tenant: nullableString,
    })
    .nullable()
    .optional(),
  events: z
    .array(
      z.object({
        status: z.string().optional(),
        from_status: nullableString,
        to_status: nullableString,
        actor_type: nullableString,
        reason: nullableString,
        created_at: nullableString,
      }),
    )
    .default([]),
})
export type WorkforceAssignment = z.infer<typeof workforceAssignmentSchema>

export const workforceSkillSchema = z.object({
  id: nullableString,
  skill: z.string(),
  level: nullableString,
  years_experience: z.number().nullable().optional(),
  verified: z.boolean().optional(),
})

export const workforceAreaSchema = z.object({
  id: nullableString,
  area: z.string(),
  city: nullableString,
  county: nullableString,
  is_primary: z.boolean().optional(),
})

export const workforceAvailabilitySchema = z.object({
  id: nullableString,
  weekday: z.number(),
  weekday_label: nullableString,
  start_time: z.string(),
  end_time: z.string(),
  timezone: nullableString,
  active: z.boolean().optional(),
})

export const workforceProfileSchema = z.object({
  id: z.string(),
  user: z
    .object({
      id: nullableString,
      name: nullableString,
      email: nullableString,
    })
    .optional(),
  legal_name: nullableString,
  phone: nullableString,
  city: nullableString,
  county: nullableString,
  country: nullableString,
  languages: z.array(z.string()).default([]),
  transport: nullableString,
  experience_years: z.number().nullable().optional(),
  bio: nullableString,
  level: z.string(),
  level_label: z.string(),
  status: z.string(),
  verification_status: z.string(),
  training_completed: z.boolean(),
  terms_accepted_at: nullableString,
  phone_verified_at: nullableString,
  identity_verified_at: nullableString,
  training_completed_at: nullableString,
  completed_jobs_count: z.number(),
  verified_accuracy: z.number().nullable().optional(),
  reliability_score: z.number().nullable().optional(),
  skills: z.array(workforceSkillSchema).default([]),
  service_areas: z.array(workforceAreaSchema).default([]),
  availability: z.array(workforceAvailabilitySchema).default([]),
  trainings: z
    .array(
      z.object({
        module: z.string(),
        status: z.string(),
        score: z.number().nullable().optional(),
        completed_at: nullableString,
      }),
    )
    .default([]),
  verification_checks: z
    .array(
      z.object({
        check_type: z.string(),
        status: z.string(),
        verified_at: nullableString,
        reference: nullableString,
        notes: nullableString,
        verified_by: nullableString,
      }),
    )
    .default([]),
  stats: z
    .object({
      active_assignments: z.number(),
      completed_assignments: z.number(),
      earnings: z.record(z.string(), z.number()).default({}),
    })
    .optional(),
})
export type WorkforceProfile = z.infer<typeof workforceProfileSchema>

export const workforceProfileEnvelopeSchema = z.object({
  profile: workforceProfileSchema.nullable(),
})

export interface WorkforceProfileInput {
  legal_name: string
  phone?: string | null
  city?: string | null
  county?: string | null
  country?: string | null
  languages?: string[]
  transport?: string | null
  experience_years?: number | null
  bio?: string | null
  terms_accepted?: boolean
}

export interface SkillInput {
  skill: string
  level?: 'basic' | 'intermediate' | 'advanced'
  years_experience?: number | null
}

export interface ServiceAreaInput {
  area: string
  city?: string | null
  county?: string | null
  is_primary?: boolean
}

export interface AvailabilityInput {
  weekday: number
  start_time: string
  end_time: string
  timezone?: string
  active?: boolean
}

export async function fetchWorkforceProfile(): Promise<WorkforceProfile | null> {
  const payload = await api.get<{ profile: unknown }>('/workforce/profile')
  return workforceProfileEnvelopeSchema.parse(payload).profile
}

export async function createWorkforceProfile(input: WorkforceProfileInput): Promise<WorkforceProfile> {
  const payload = await api.post<{ profile: unknown }>('/workforce/profile', input)
  return workforceProfileSchema.parse(payload.profile)
}

export async function updateWorkforceProfile(input: Partial<WorkforceProfileInput>): Promise<WorkforceProfile> {
  const payload = await api.patch<{ profile: unknown }>('/workforce/profile', input)
  return workforceProfileSchema.parse(payload.profile)
}

export async function replaceWorkforceSkills(skills: SkillInput[]): Promise<WorkforceProfile> {
  const payload = await api.put<{ profile: unknown }>('/workforce/profile/skills', { skills })
  return workforceProfileSchema.parse(payload.profile)
}

export async function replaceWorkforceServiceAreas(areas: ServiceAreaInput[]): Promise<WorkforceProfile> {
  const payload = await api.put<{ profile: unknown }>('/workforce/profile/service-areas', { areas })
  return workforceProfileSchema.parse(payload.profile)
}

export async function replaceWorkforceAvailability(windows: AvailabilityInput[]): Promise<WorkforceProfile> {
  const payload = await api.put<{ profile: unknown }>('/workforce/profile/availability', { windows })
  return workforceProfileSchema.parse(payload.profile)
}

export async function fetchWorkforceAssignments(): Promise<WorkforceAssignment[]> {
  const payload = await api.get<{ data: unknown }>('/workforce/assignments')
  return z.array(workforceAssignmentSchema).parse(payload.data ?? [])
}

export async function acceptWorkforceAssignment(id: string): Promise<WorkforceAssignment> {
  const payload = await api.post<unknown>(`/workforce/assignments/${id}/accept`)
  return workforceAssignmentSchema.parse(payload)
}

export async function declineWorkforceAssignment(id: string, reason: string): Promise<WorkforceAssignment> {
  const payload = await api.post<unknown>(`/workforce/assignments/${id}/decline`, { reason })
  return workforceAssignmentSchema.parse(payload)
}

export const workforceEarningSchema = z.object({
  id: z.string(),
  reference: z.string(),
  amount: z.number(),
  currency: z.string(),
  status: z.string(),
  description: nullableString,
  earned_at: nullableString,
  job: z
    .object({ reference: z.string(), title: z.string() })
    .nullable()
    .optional(),
})
export type WorkforceEarning = z.infer<typeof workforceEarningSchema>

export const workforceEarningsSchema = z.object({
  data: z.array(workforceEarningSchema).default([]),
  totals: z.record(z.string(), z.number()).default({}),
  currency: z.string().default('KES'),
})
export type WorkforceEarnings = z.infer<typeof workforceEarningsSchema>

export async function fetchWorkforceEarnings(): Promise<WorkforceEarnings> {
  return workforceEarningsSchema.parse(await api.get<unknown>('/workforce/earnings'))
}

export const stockTakerSummarySchema = z.object({
  id: z.string(),
  user: z
    .object({ id: nullableString, name: nullableString, email: nullableString })
    .optional(),
  legal_name: nullableString,
  city: nullableString,
  county: nullableString,
  level: z.string(),
  level_label: z.string(),
  status: z.string(),
  verification_status: z.string(),
  training_completed: z.boolean(),
  experience_years: z.number().nullable().optional(),
  skills: z.array(z.string()).default([]),
  service_areas: z.array(z.string()).default([]),
  availability_windows: z.number(),
  completed_jobs_count: z.number(),
  verified_accuracy: z.number().nullable().optional(),
  created_at: nullableString,
})
export type StockTakerSummary = z.infer<typeof stockTakerSummarySchema>

export interface StockTakerListFilters {
  status?: string
  level?: string
  verification_status?: string
  search?: string
  page?: number
}

export async function fetchStockTakers(filters: StockTakerListFilters = {}) {
  const query = new URLSearchParams()
  Object.entries(filters).forEach(([key, value]) => {
    if (value !== undefined && value !== '') query.set(key, String(value))
  })
  const suffix = query.toString() ? `?${query.toString()}` : ''
  const { data, meta } = await api.getWithMeta<unknown>(`/platform/stock-takers${suffix}`)
  return {
    profiles: z.array(stockTakerSummarySchema).parse(data),
    meta: meta as {
      total?: number
      current_page?: number
      last_page?: number
      counts?: Record<string, number>
      pending_verification?: number
    },
  }
}

export async function fetchStockTaker(id: string): Promise<{
  profile: WorkforceProfile
  assignments: WorkforceAssignment[]
}> {
  const payload = await api.get<{ profile: unknown; assignments: unknown }>(`/platform/stock-takers/${id}`)
  return {
    profile: workforceProfileSchema.parse(payload.profile),
    assignments: z.array(workforceAssignmentSchema).parse(payload.assignments ?? []),
  }
}

export interface StockTakerManageInput extends Partial<WorkforceProfileInput> {
  level?: string
  status?: string
  reason?: string
}

export async function updateStockTaker(id: string, input: StockTakerManageInput): Promise<WorkforceProfile> {
  const payload = await api.patch<{ profile: unknown }>(`/platform/stock-takers/${id}`, input)
  return workforceProfileSchema.parse(payload.profile)
}

export async function verifyStockTaker(
  id: string,
  input: {
    check_type: 'identity' | 'phone' | 'background' | 'reference' | 'training'
    decision: 'pending' | 'verified' | 'rejected'
    reference?: string | null
    notes?: string | null
  },
): Promise<WorkforceProfile> {
  const payload = await api.post<{ profile: unknown }>(`/platform/stock-takers/${id}/verification`, input)
  return workforceProfileSchema.parse(payload.profile)
}

export async function recordStockTakerTraining(
  id: string,
  input: { module: string; status?: 'completed' | 'failed'; score?: number | null; notes?: string | null },
): Promise<WorkforceProfile> {
  const payload = await api.post<{ profile: unknown }>(`/platform/stock-takers/${id}/training`, input)
  return workforceProfileSchema.parse(payload.profile)
}

export async function replaceStockTakerSkills(id: string, skills: SkillInput[]): Promise<WorkforceProfile> {
  const payload = await api.put<{ profile: unknown }>(`/platform/stock-takers/${id}/skills`, { skills })
  return workforceProfileSchema.parse(payload.profile)
}

export const assignmentRecommendationSchema = z.object({
  profile: stockTakerSummarySchema,
  eligible: z.boolean(),
  score: z.number(),
  reasons: z.array(z.string()).default([]),
  blockers: z.array(z.string()).default([]),
  active_assignments: z.number(),
  proposed_compensation: z.number(),
  already_assigned: z.boolean(),
})
export type AssignmentRecommendation = z.infer<typeof assignmentRecommendationSchema>

export const assignmentRecommendationsSchema = z.object({
  job: z.object({
    id: z.string(),
    reference: z.string(),
    status: z.string(),
    required_team_size: z.number(),
    required_skills: z.array(z.string()).default([]),
    scope_label: nullableString,
    preferred_start_at: nullableString,
  }),
  required_team_size: z.number(),
  filled_slots: z.number(),
  proposed_compensation: z.number(),
  recommendations: z.array(assignmentRecommendationSchema).default([]),
  assignments: z.array(workforceAssignmentSchema).default([]),
})
export type AssignmentRecommendations = z.infer<typeof assignmentRecommendationsSchema>

export async function fetchAssignmentRecommendations(jobId: string): Promise<AssignmentRecommendations> {
  return assignmentRecommendationsSchema.parse(
    await api.get<unknown>(`/platform/stocktake-jobs/${jobId}/assignment-recommendations`),
  )
}

export interface OfferAssignmentInput {
  profile_id: string
  role?: 'lead' | 'counter' | 'supervisor'
  compensation_amount?: number
  expires_in_hours?: number
  override_reason?: string
}

export async function offerAssignment(jobId: string, input: OfferAssignmentInput) {
  return api.post<{
    assignment: unknown
    job: { id: string; reference: string; status: string; required_team_size: number }
    assignments: unknown[]
  }>(`/platform/stocktake-jobs/${jobId}/assignments`, input)
}

export async function withdrawAssignment(
  assignmentId: string,
  reason?: string,
): Promise<WorkforceAssignment> {
  const payload = await api.post<{ assignment: unknown }>(`/platform/assignments/${assignmentId}/withdraw`, {
    reason,
  })
  return workforceAssignmentSchema.parse(payload.assignment)
}
