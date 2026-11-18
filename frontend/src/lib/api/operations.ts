import { z } from 'zod'
import { api } from './client'

const nullableString = z.string().nullable().optional()

export const readinessSchema = z.object({
  accepted: z.boolean(),
  has_procedure: z.boolean(),
  sop_required: z.boolean(),
  sop_acknowledged: z.boolean(),
  checklist_required: z.number(),
  checklist_answered: z.number(),
  checklist_complete: z.boolean(),
  checked_in: z.boolean(),
  count_in_progress: z.boolean(),
  can_check_in: z.boolean(),
  can_start_count: z.boolean(),
  percent: z.number(),
  stage: z.string(),
  blockers: z.array(z.string()).default([]),
})
export type Readiness = z.infer<typeof readinessSchema>

export const progressEventSchema = z.object({
  stage: z.string(),
  percent: z.number(),
  message: nullableString,
  occurred_at: nullableString,
})
export type ProgressEvent = z.infer<typeof progressEventSchema>

export const checklistResponseSchema = z
  .object({
    value: z.string(),
    notes: nullableString,
    responded_at: nullableString,
  })
  .nullable()

export const checklistItemSchema = z.object({
  id: z.string(),
  label: z.string(),
  instructions: nullableString,
  response_type: z.enum(['boolean', 'text', 'select']),
  required: z.boolean(),
  options: z.array(z.string()).default([]),
  response: checklistResponseSchema,
})
export type ChecklistItem = z.infer<typeof checklistItemSchema>

export const checklistSchema = z.object({
  id: z.string(),
  name: z.string(),
  kind: z.string(),
  required: z.boolean(),
  status: z.string().optional(),
  items: z.array(checklistItemSchema).default([]),
})
export type Checklist = z.infer<typeof checklistSchema>

const attendanceSchema = z.object({
  id: z.string().optional(),
  status: z.string(),
  scheduled_start_at: nullableString,
  checked_in_at: nullableString,
  checked_out_at: nullableString,
  minutes_on_site: z.number().nullable().optional(),
})
export type Attendance = z.infer<typeof attendanceSchema>

const checkinSchema = z.object({
  id: z.string(),
  site_label: nullableString,
  latitude: z.number().nullable().optional(),
  longitude: z.number().nullable().optional(),
  method: z.string(),
  checked_in_at: nullableString,
})

const checkoutSchema = z.object({
  id: z.string(),
  notes: nullableString,
  checked_out_at: nullableString,
})

export const workerProcedureSchema = z.object({
  id: z.string(),
  version: z.number(),
  title: z.string(),
  summary: nullableString,
  require_acknowledgement: z.boolean(),
  assigned_at: nullableString,
  acknowledged: z.boolean(),
  acknowledged_at: nullableString,
  steps: z
    .array(
      z.object({
        position: z.number(),
        title: z.string(),
        instructions: nullableString,
        required: z.boolean(),
        evidence_required: z.boolean(),
      }),
    )
    .default([]),
})
export type WorkerProcedure = z.infer<typeof workerProcedureSchema>

export const workerOperationsSchema = z.object({
  assignment: z.object({
    id: z.string(),
    status: z.string(),
    role: z.string(),
    compensation: z.object({ amount: z.number(), currency: z.string() }),
    job: z
      .object({
        id: z.string(),
        reference: z.string(),
        title: z.string(),
        status: z.string(),
        status_label: z.string(),
        priority: nullableString,
        count_method: nullableString,
        scope_label: nullableString,
        preferred_start_at: nullableString,
        deadline_at: nullableString,
        tenant: nullableString,
        required_team_size: z.number().optional(),
      })
      .nullable(),
  }),
  procedure: workerProcedureSchema.nullable(),
  checklists: z.array(checklistSchema).default([]),
  checkin: checkinSchema.nullable(),
  checkout: checkoutSchema.nullable(),
  attendance: attendanceSchema.nullable(),
  readiness: readinessSchema,
  progress: z.object({
    events: z.array(progressEventSchema).default([]),
    latest_percent: z.number().default(0),
  }),
})
export type WorkerOperations = z.infer<typeof workerOperationsSchema>

export async function fetchWorkerOperations(assignmentId: string): Promise<WorkerOperations> {
  return workerOperationsSchema.parse(await api.get<unknown>(`/workforce/assignments/${assignmentId}/operations`))
}

export async function acknowledgeWorkerProcedure(assignmentId: string): Promise<WorkerOperations> {
  return workerOperationsSchema.parse(
    await api.post<unknown>(`/workforce/assignments/${assignmentId}/procedure/acknowledge`),
  )
}

export async function respondChecklistItem(
  assignmentId: string,
  itemId: string,
  input: { value: string; notes?: string | null },
): Promise<WorkerOperations> {
  return workerOperationsSchema.parse(
    await api.put<unknown>(`/workforce/assignments/${assignmentId}/checklists/${itemId}`, input),
  )
}

export interface CheckInInput {
  site_label?: string | null
  latitude?: number | null
  longitude?: number | null
  distance_m?: number | null
  method?: 'gps' | 'manual'
  notes?: string | null
}

export async function checkInWorker(assignmentId: string, input: CheckInInput): Promise<WorkerOperations> {
  return workerOperationsSchema.parse(
    await api.post<unknown>(`/workforce/assignments/${assignmentId}/check-in`, input),
  )
}

export async function checkOutWorker(assignmentId: string, notes?: string | null): Promise<WorkerOperations> {
  return workerOperationsSchema.parse(
    await api.post<unknown>(`/workforce/assignments/${assignmentId}/check-out`, { notes }),
  )
}

export const procedureTemplateStepSchema = z.object({
  position: z.number().optional(),
  title: z.string(),
  instructions: nullableString,
  required: z.boolean().default(true),
  evidence_required: z.boolean().default(false),
})
export type ProcedureTemplateStep = z.infer<typeof procedureTemplateStepSchema>

const templateChecklistItemSchema = z.object({
  label: z.string(),
  instructions: nullableString,
  response_type: z.enum(['boolean', 'text', 'select']).default('boolean'),
  required: z.boolean().default(true),
  options: z.array(z.string()).nullable().optional(),
})

const templateChecklistSchema = z.object({
  name: z.string(),
  kind: z.enum(['pre_start', 'site', 'close_out']).default('pre_start'),
  required: z.boolean().default(true),
  items: z.array(templateChecklistItemSchema).default([]),
})

export const procedureTemplateVersionSchema = z.object({
  id: z.string(),
  version: z.number(),
  title: z.string(),
  summary: nullableString,
  steps: z.union([z.number(), z.array(procedureTemplateStepSchema)]),
  checklists: z.array(templateChecklistSchema).default([]),
  require_acknowledgement: z.boolean(),
  status: z.string(),
  published_at: nullableString,
})
export type ProcedureTemplateVersion = z.infer<typeof procedureTemplateVersionSchema>

export const procedureTemplateSchema = z.object({
  id: z.string(),
  name: z.string(),
  slug: z.string(),
  description: nullableString,
  category: z.string(),
  status: z.string(),
  scope: z.string(),
  current_version: procedureTemplateVersionSchema.nullable(),
  versions: z.array(procedureTemplateVersionSchema).default([]),
  created_at: nullableString,
  updated_at: nullableString,
})
export type ProcedureTemplate = z.infer<typeof procedureTemplateSchema>

export async function fetchProcedureTemplates(search?: string): Promise<ProcedureTemplate[]> {
  const suffix = search ? `?search=${encodeURIComponent(search)}` : ''
  const payload = await api.get<{ templates: unknown }>(`/platform/procedure-templates${suffix}`)
  return z.array(procedureTemplateSchema).parse(payload.templates ?? [])
}

export interface ProcedureTemplateInput {
  name: string
  description?: string | null
  category?: string | null
  title?: string | null
  summary?: string | null
  require_acknowledgement?: boolean
  steps: ProcedureTemplateStepInput[]
  checklists: ProcedureChecklistInput[]
}

export interface ProcedureTemplateStepInput {
  title: string
  instructions?: string | null
  required?: boolean
  evidence_required?: boolean
}

export interface ProcedureChecklistInput {
  name: string
  kind: 'pre_start' | 'site' | 'close_out'
  required?: boolean
  items: ProcedureChecklistItemInput[]
}

export interface ProcedureChecklistItemInput {
  label: string
  instructions?: string | null
  response_type?: 'boolean' | 'text' | 'select'
  required?: boolean
  options?: string[] | null
}

export async function createProcedureTemplate(input: ProcedureTemplateInput): Promise<ProcedureTemplate> {
  const payload = await api.post<{ template: unknown }>('/platform/procedure-templates', input)
  return procedureTemplateSchema.parse(payload.template)
}

export async function publishProcedureVersion(
  templateId: string,
  input: Partial<ProcedureTemplateInput> & { steps: ProcedureTemplateStepInput[] },
): Promise<ProcedureTemplate> {
  const payload = await api.post<{ template: unknown }>(`/platform/procedure-templates/${templateId}/versions`, input)
  return procedureTemplateSchema.parse(payload.template)
}

export const jobProcedureSchema = z.object({
  id: z.string(),
  template_id: nullableString,
  template_name: nullableString,
  version: z.number(),
  title: z.string(),
  summary: nullableString,
  require_acknowledgement: z.boolean(),
  status: z.string(),
  assigned_at: nullableString,
  acknowledgements: z.number(),
  steps: z
    .array(
      z.object({
        id: z.number(),
        position: z.number(),
        title: z.string(),
        instructions: nullableString,
        required: z.boolean(),
        evidence_required: z.boolean(),
      }),
    )
    .default([]),
  checklists: z
    .array(
      z.object({
        id: z.string(),
        name: z.string(),
        kind: z.string(),
        required: z.boolean(),
        items_total: z.number(),
        items_required: z.number(),
      }),
    )
    .default([]),
})
export type JobProcedure = z.infer<typeof jobProcedureSchema>

const jobSummarySchema = z.object({
  id: z.string(),
  reference: z.string(),
  title: z.string(),
  status: z.string(),
  status_label: z.string(),
  priority: nullableString,
  scope_label: nullableString,
  tenant: nullableString,
  preferred_start_at: nullableString,
  deadline_at: nullableString,
})

export const platformAssignmentOperationsSchema = z.object({
  id: z.string(),
  status: z.string(),
  role: z.string(),
  profile: z.object({
    id: nullableString,
    name: nullableString,
    level: nullableString,
  }),
  acknowledged_at: nullableString,
  checklist_required: z.number(),
  checklist_answered: z.number(),
  checkin: checkinSchema.nullable(),
  checkout: checkoutSchema.nullable(),
  attendance: attendanceSchema.nullable(),
  readiness: readinessSchema,
})
export type PlatformAssignmentOperations = z.infer<typeof platformAssignmentOperationsSchema>

export const platformJobOperationsSchema = z.object({
  job: jobSummarySchema,
  procedure: jobProcedureSchema.nullable(),
  assignments: z.array(platformAssignmentOperationsSchema).default([]),
  checklists: z
    .array(
      z.object({
        id: z.string(),
        name: z.string(),
        kind: z.string(),
        required: z.boolean(),
        items_total: z.number(),
        items_required: z.number(),
        items_answered: z.number(),
      }),
    )
    .default([]),
  summary: z.object({
    team_required: z.number(),
    team_accepted: z.number(),
    checked_in: z.number(),
    sop_acknowledged: z.number(),
    can_start_count: z.boolean(),
    blockers: z.array(z.string()).default([]),
    percent: z.number(),
    stage: z.string(),
    next_action: nullableString,
  }),
  progress: z.object({
    percent: z.number(),
    stage: z.string(),
    events: z.array(progressEventSchema).default([]),
  }),
  attendance: z.array(attendanceSchema).default([]),
})
export type PlatformJobOperations = z.infer<typeof platformJobOperationsSchema>

export async function fetchPlatformJobOperations(jobId: string): Promise<PlatformJobOperations> {
  return platformJobOperationsSchema.parse(await api.get<unknown>(`/platform/stocktake-jobs/${jobId}/operations`))
}

export interface ApplyProcedureInput {
  template_id?: string
  version_id?: string
  title?: string
  summary?: string | null
  require_acknowledgement?: boolean
  steps?: ProcedureTemplateStepInput[]
  checklists?: ProcedureChecklistInput[]
}

export async function applyJobProcedure(jobId: string, input: ApplyProcedureInput) {
  const payload = await api.post<{ procedure: unknown; operations: unknown }>(
    `/platform/stocktake-jobs/${jobId}/procedure`,
    input,
  )
  return {
    procedure: jobProcedureSchema.nullable().parse(payload.procedure),
    operations: platformJobOperationsSchema.parse(payload.operations),
  }
}

export const operationsDashboardSchema = z.object({
  summary: z.object({
    active_jobs: z.number(),
    in_progress: z.number(),
    awaiting_check_in: z.number(),
    unassigned: z.number(),
    sop_pending: z.number(),
    overdue: z.number(),
  }),
  jobs: z
    .array(
      z.object({
        job: jobSummarySchema,
        team: z.object({ required: z.number(), accepted: z.number(), checked_in: z.number() }),
        procedure: z
          .object({ title: z.string(), version: z.number(), acknowledged: z.number(), assigned: z.boolean() })
          .nullable(),
        checklists: z.object({ required: z.number(), answered: z.number(), complete: z.boolean() }),
        progress: z.object({ percent: z.number(), stage: z.string() }),
        next_action: nullableString,
        blockers: z.array(z.string()).default([]),
        is_overdue: z.boolean(),
        last_activity_at: nullableString,
      }),
    )
    .default([]),
  generated_at: z.string(),
})
export type OperationsDashboard = z.infer<typeof operationsDashboardSchema>

export async function fetchOperationsDashboard(): Promise<OperationsDashboard> {
  return operationsDashboardSchema.parse(await api.get<unknown>('/platform/operations'))
}

export const tenantJobOperationsSchema = z.object({
  job: jobSummarySchema,
  procedure: z
    .object({
      title: z.string(),
      version: z.number(),
      summary: nullableString,
      steps: z
        .array(
          z.object({
            position: z.number(),
            title: z.string(),
            instructions: nullableString,
          }),
        )
        .default([]),
    })
    .nullable(),
  team: z
    .array(
      z.object({
        name: nullableString,
        role: z.string(),
        status: z.string(),
        checked_in_at: nullableString,
        checked_out_at: nullableString,
        attendance_status: nullableString,
      }),
    )
    .default([]),
  attendance: z
    .array(
      z.object({
        status: z.string(),
        checked_in_at: nullableString,
        checked_out_at: nullableString,
        minutes_on_site: z.number().nullable().optional(),
      }),
    )
    .default([]),
  summary: z.object({
    team_required: z.number(),
    team_accepted: z.number(),
    sop_assigned: z.boolean(),
    sop_acknowledged: z.number(),
    percent: z.number(),
    stage: z.string(),
  }),
  progress: z.object({
    percent: z.number(),
    stage: z.string(),
    events: z.array(progressEventSchema).default([]),
  }),
})
export type TenantJobOperations = z.infer<typeof tenantJobOperationsSchema>

export async function fetchTenantJobOperations(
  tenantId: string,
  jobId: string,
): Promise<TenantJobOperations> {
  return tenantJobOperationsSchema.parse(
    await api.get<unknown>(`/tenants/${tenantId}/stocktake-jobs/${jobId}/operations`),
  )
}
