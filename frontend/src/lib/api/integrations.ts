import { z } from 'zod'
import { api } from './client'

const nullableString = z.string().nullable().optional()

export const integrationConnectionSchema = z.object({
  id: z.string(),
  name: z.string(),
  provider: z.string(),
  type: z.enum(['csv', 'api']),
  status: z.enum(['active', 'paused', 'error']),
  settings: z.record(z.string(), z.unknown()).default({}),
  has_credentials: z.boolean(),
  has_webhook_secret: z.boolean(),
  last_synced_at: nullableString,
  last_error: nullableString,
  runs_count: z.number(),
  conflicts_count: z.number(),
  created_at: nullableString,
})
export type IntegrationConnection = z.infer<typeof integrationConnectionSchema>

export const integrationMappingSchema = z.object({
  entity: z.enum(['products', 'movements']),
  field_map: z.record(z.string(), z.string()).default({}),
  options: z.record(z.string(), z.unknown()).default({}),
  canonical: z.record(z.string(), z.string()),
})
export type IntegrationMapping = z.infer<typeof integrationMappingSchema>

export const integrationRunSchema = z.object({
  id: z.string(),
  entity: z.string(),
  direction: z.string(),
  status: z.enum(['running', 'completed', 'partial', 'failed']),
  records_in: z.number(),
  records_upserted: z.number(),
  records_skipped: z.number(),
  conflict_count: z.number(),
  message: nullableString,
  started_at: nullableString,
  finished_at: nullableString,
})
export type IntegrationRun = z.infer<typeof integrationRunSchema>

export const integrationConflictSchema = z.object({
  id: z.string(),
  entity: z.string(),
  reference: z.string(),
  reason: z.string(),
  payload: z.record(z.string(), z.unknown()).nullable().optional(),
  resolution: z.string().nullable(),
  resolved_at: nullableString,
  created_at: nullableString,
})
export type IntegrationConflict = z.infer<typeof integrationConflictSchema>

export const integrationWebhookEventSchema = z.object({
  id: z.string(),
  event: z.string(),
  signature_valid: z.boolean(),
  status: z.string(),
  attempts: z.number(),
  last_error: nullableString,
  processed_at: nullableString,
  created_at: nullableString,
})
export type IntegrationWebhookEvent = z.infer<typeof integrationWebhookEventSchema>

export interface ConnectionInput {
  name: string
  provider: string
  type: 'csv' | 'api'
  status?: 'active' | 'paused'
  credentials?: { token?: string } | null
  settings?: { url?: string; auto_sync?: boolean } | null
  webhook_secret?: string | null
}

export interface SyncInput {
  entity: 'products' | 'movements'
  direction: 'import' | 'pull'
  rows?: Record<string, unknown>[]
}

function base(tenantId: string): string {
  return `/tenants/${tenantId}/integrations`
}

export function fetchConnections(tenantId: string): Promise<IntegrationConnection[]> {
  return api.get<IntegrationConnection[]>(base(tenantId)).then((rows) =>
    z.array(integrationConnectionSchema).parse(rows),
  )
}

export function createConnection(
  tenantId: string,
  input: ConnectionInput,
): Promise<IntegrationConnection> {
  return api
    .post<IntegrationConnection>(base(tenantId), input)
    .then((row) => integrationConnectionSchema.parse(row))
}

export function updateConnection(
  tenantId: string,
  connectionId: string,
  input: Partial<ConnectionInput>,
): Promise<IntegrationConnection> {
  return api
    .patch<IntegrationConnection>(`${base(tenantId)}/${connectionId}`, input)
    .then((row) => integrationConnectionSchema.parse(row))
}

export function deleteConnection(tenantId: string, connectionId: string): Promise<unknown> {
  return api.delete(`${base(tenantId)}/${connectionId}`)
}

export function testConnection(
  tenantId: string,
  connectionId: string,
): Promise<{ status: string; message: string; http_status?: number }> {
  return api.post(`${base(tenantId)}/${connectionId}/test`)
}

export function fetchMapping(
  tenantId: string,
  connectionId: string,
  entity: 'products' | 'movements',
): Promise<IntegrationMapping> {
  return api
    .get<IntegrationMapping>(`${base(tenantId)}/${connectionId}/mappings/${entity}`)
    .then((row) => integrationMappingSchema.parse(row))
}

export function saveMapping(
  tenantId: string,
  connectionId: string,
  entity: 'products' | 'movements',
  fieldMap: Record<string, string>,
): Promise<IntegrationMapping> {
  return api
    .put<IntegrationMapping>(`${base(tenantId)}/${connectionId}/mappings/${entity}`, {
      field_map: fieldMap,
    })
    .then((row) => integrationMappingSchema.parse(row))
}

export function runSync(
  tenantId: string,
  connectionId: string,
  input: SyncInput,
): Promise<IntegrationRun> {
  return api
    .post<IntegrationRun>(`${base(tenantId)}/${connectionId}/sync`, input)
    .then((row) => integrationRunSchema.parse(row))
}

export function fetchRuns(tenantId: string, connectionId: string): Promise<IntegrationRun[]> {
  return api
    .get<IntegrationRun[]>(`${base(tenantId)}/${connectionId}/runs`)
    .then((rows) => z.array(integrationRunSchema).parse(rows))
}

export function fetchConflicts(
  tenantId: string,
  connectionId: string,
): Promise<IntegrationConflict[]> {
  return api
    .get<IntegrationConflict[]>(`${base(tenantId)}/${connectionId}/conflicts`)
    .then((rows) => z.array(integrationConflictSchema).parse(rows))
}

export function resolveConflict(
  tenantId: string,
  connectionId: string,
  conflictId: string,
  resolution: 'accept_external' | 'keep_local' | 'ignored',
): Promise<IntegrationConflict> {
  return api
    .post<IntegrationConflict>(
      `${base(tenantId)}/${connectionId}/conflicts/${conflictId}/resolve`,
      { resolution },
    )
    .then((row) => integrationConflictSchema.parse(row))
}

export function fetchWebhookEvents(
  tenantId: string,
  connectionId: string,
): Promise<IntegrationWebhookEvent[]> {
  return api
    .get<IntegrationWebhookEvent[]>(`${base(tenantId)}/${connectionId}/webhooks`)
    .then((rows) => z.array(integrationWebhookEventSchema).parse(rows))
}
