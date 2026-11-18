import { api } from './client'

// ---- types ----

export interface ApprovedUnit {
  id: string
  code: string
  name: string
}

export interface ScopeVariant {
  id: string
  product: { id: string; name: string; brand: string | null }
  sku: string
  name: string | null
  barcodes: { barcode: string; type: string; is_primary: boolean }[]
  approved_units: ApprovedUnit[]
}

export interface CountEntry {
  id: string
  variant_id: string | null
  product_id: string | null
  raw_quantity: string
  raw_unit_id: string | null
  normalized_quantity: string
  observations: string[]
  notes: string | null
  status: 'draft' | 'submitted'
  revision_count: number
  captured_at: string | null
  idempotency_key: string
  revisions: { id: string; reason: string; raw_quantity: string; created_at: string }[]
}

export interface CountException {
  id: string
  type: string
  description: string
  status: string
}

export interface UnknownProduct {
  id: string
  barcode: string | null
  name_guess: string | null
  status: string
}

export interface CountSession {
  id: string
  mode: 'blind' | 'guided'
  status: 'active' | 'submitted' | 'cancelled'
  started_at: string | null
  submitted_at: string | null
  scope: ScopeVariant[]
  locked: boolean
  locked_at: string | null
  entries: CountEntry[]
  exceptions: CountException[]
  unknown_products: UnknownProduct[]
}

export interface CountWorkspace {
  job: { id: string; reference: string; title: string; status: string; scope_label: string | null }
  readiness: Record<string, unknown>
  mode: 'blind' | 'guided'
  scope: ScopeVariant[]
  session: CountSession | null
}

export interface CountSummary {
  sessions: number
  submitted: number
  entries: number
  exceptions: number
  unknown_products: number
}

export interface JobCounts {
  job: { id: string; reference: string; title: string; status: string }
  sessions: CountSession[]
  summary: CountSummary
}

// ---- API calls ----

export function fetchCountWorkspace(assignmentId: string): Promise<CountWorkspace> {
  return api.get<CountWorkspace>(`/workforce/assignments/${assignmentId}/count`)
}

export function startCountSession(
  assignmentId: string,
  data: { idempotency_key?: string; device?: Record<string, unknown> },
): Promise<CountSession> {
  return api.post<CountSession>(`/workforce/assignments/${assignmentId}/count/sessions`, data)
}

export function fetchCountSession(assignmentId: string, sessionId: string): Promise<CountSession> {
  return api.get<CountSession>(`/workforce/assignments/${assignmentId}/count/sessions/${sessionId}`)
}

export function upsertCountEntry(
  assignmentId: string,
  sessionId: string,
  data: {
    idempotency_key: string
    variant_id: string
    raw_quantity: number
    raw_unit_id: string
    stock_location_id?: string | null
    observations?: string[]
    notes?: string | null
    captured_at?: string | null
  },
): Promise<CountEntry> {
  return api.post<CountEntry>(`/workforce/assignments/${assignmentId}/count/sessions/${sessionId}/entries`, data)
}

export function reviseCountEntry(
  assignmentId: string,
  sessionId: string,
  entryId: string,
  data: {
    raw_quantity: number
    raw_unit_id: string
    observations?: string[]
    notes?: string | null
    reason: string
  },
): Promise<CountEntry> {
  return api.patch<CountEntry>(
    `/workforce/assignments/${assignmentId}/count/sessions/${sessionId}/entries/${entryId}`,
    data,
  )
}

export function reportCountException(
  assignmentId: string,
  sessionId: string,
  data: {
    type: string
    description: string
    barcode?: string | null
    variant_id?: string | null
    location?: string | null
  },
): Promise<{ id: string; status: string }> {
  return api.post<{ id: string; status: string }>(
    `/workforce/assignments/${assignmentId}/count/sessions/${sessionId}/exceptions`,
    data,
  )
}

export function reportUnknownProduct(
  assignmentId: string,
  sessionId: string,
  data: {
    description: string
    barcode?: string | null
    name_guess?: string | null
    location?: string | null
  },
): Promise<{ id: string; status: string }> {
  return api.post<{ id: string; status: string }>(
    `/workforce/assignments/${assignmentId}/count/sessions/${sessionId}/unknown-products`,
    data,
  )
}

export function submitCountSession(assignmentId: string, sessionId: string): Promise<CountSession> {
  return api.post<CountSession>(`/workforce/assignments/${assignmentId}/count/sessions/${sessionId}/submit`)
}

export function fetchPlatformJobCounts(jobId: string): Promise<JobCounts> {
  return api.get<JobCounts>(`/platform/stocktake-jobs/${jobId}/counts`)
}

export function fetchTenantJobCounts(tenantId: string, jobId: string): Promise<JobCounts> {
  return api.get<JobCounts>(`/tenants/${tenantId}/stocktake-jobs/${jobId}/counts`)
}
