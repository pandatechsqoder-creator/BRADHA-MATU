import { z } from 'zod'
import { api } from './client'

const nullableString = z.string().nullable().optional()

export const reportColumnSchema = z.object({
  key: z.string(),
  label: z.string(),
  type: z.string(),
})
export type ReportColumn = z.infer<typeof reportColumnSchema>

export const reportSummaryItemSchema = z.object({
  key: z.string(),
  label: z.string(),
  value: z.union([z.number(), z.string()]),
  format: z.string(),
})
export type ReportSummaryItem = z.infer<typeof reportSummaryItemSchema>

export const reportSchema = z.object({
  report_type: z.string(),
  scope: z.enum(['tenant', 'platform']),
  period: z.object({ from: z.string(), to: z.string() }),
  columns: z.array(reportColumnSchema),
  rows: z.array(z.record(z.string(), z.unknown())),
  summary: z.array(reportSummaryItemSchema),
  row_count: z.number(),
  generated_at: z.string(),
})
export type Report = z.infer<typeof reportSchema>

export const reportRunSchema = z.object({
  id: z.string(),
  report_type: z.string(),
  format: z.string(),
  row_count: z.number(),
  generated_at: nullableString,
  generated_by: nullableString,
  tenant: z.string().nullable().optional(),
})
export type ReportRun = z.infer<typeof reportRunSchema>

export const reportsIndexSchema = z.object({
  available: z.array(z.string()),
  runs: z.array(reportRunSchema),
  can_export: z.boolean(),
})
export type ReportsIndex = z.infer<typeof reportsIndexSchema>

export const analyticsKpiSchema = z.object({
  key: z.string(),
  label: z.string(),
  value: z.number(),
  format: z.string(),
  hint: nullableString,
})
export type AnalyticsKpi = z.infer<typeof analyticsKpiSchema>

export const analyticsSeriesSchema = z.object({
  key: z.string(),
  label: z.string(),
  format: z.string(),
  points: z.array(z.object({ date: z.string(), value: z.number() })),
})
export type AnalyticsSeries = z.infer<typeof analyticsSeriesSchema>

export const analyticsBreakdownSchema = z.object({
  key: z.string(),
  label: z.string(),
  format: z.string(),
  items: z.array(z.object({ label: z.string(), value: z.number() })),
})
export type AnalyticsBreakdown = z.infer<typeof analyticsBreakdownSchema>

export const analyticsInsightSchema = z.object({
  key: z.string(),
  severity: z.enum(['success', 'info', 'warning']),
  title: z.string(),
  detail: z.string(),
})
export type AnalyticsInsight = z.infer<typeof analyticsInsightSchema>

export const analyticsSchema = z.object({
  scope: z.enum(['tenant', 'platform']),
  tenant: nullableString,
  user: nullableString,
  generated_at: z.string(),
  kpis: z.array(analyticsKpiSchema),
  series: z.array(analyticsSeriesSchema),
  breakdowns: z.array(analyticsBreakdownSchema),
  insights: z.array(analyticsInsightSchema),
  ai: z.object({
    enabled: z.boolean(),
    provider: nullableString,
    interfaces: z.array(
      z.object({
        key: z.string(),
        label: z.string(),
        status: z.string(),
        description: z.string(),
      }),
    ),
    note: z.string(),
  }),
})
export type Analytics = z.infer<typeof analyticsSchema>

export const supportMessageSchema = z.object({
  id: z.string(),
  body: z.string(),
  is_internal: z.boolean(),
  author: nullableString,
  created_at: nullableString,
})
export type SupportMessage = z.infer<typeof supportMessageSchema>

export const supportTicketSchema = z.object({
  id: z.string(),
  reference: z.string(),
  tenant: z.object({ id: z.string(), name: z.string() }).nullable().optional(),
  subject: z.string(),
  description: z.string(),
  category: z.string(),
  priority: z.string(),
  status: z.string(),
  sla_due_at: nullableString,
  first_response_at: nullableString,
  resolved_at: nullableString,
  closed_at: nullableString,
  last_reply_at: nullableString,
  created_at: nullableString,
  overdue: z.boolean(),
  messages_count: z.number(),
  created_by: nullableString,
  assigned_to: z.object({ id: z.string(), name: z.string() }).nullable().optional(),
  messages: z.array(supportMessageSchema).optional(),
})
export type SupportTicket = z.infer<typeof supportTicketSchema>

export const notificationSchema = z.object({
  id: z.string(),
  type: z.string(),
  title: z.string(),
  body: nullableString,
  data: z.record(z.string(), z.unknown()).optional(),
  tenant_id: z.number().nullable().optional(),
  read_at: nullableString,
  created_at: nullableString,
})
export type AppNotification = z.infer<typeof notificationSchema>

export const notificationsEnvelopeSchema = z.object({
  notifications: z.array(notificationSchema),
  unread_count: z.number(),
})
export type NotificationsEnvelope = z.infer<typeof notificationsEnvelopeSchema>

export const notificationPreferencesSchema = z.object({
  in_app_enabled: z.boolean(),
  email_enabled: z.boolean(),
  digest_frequency: z.string(),
  event_preferences: z.record(z.string(), z.unknown()).optional(),
  events: z.array(z.string()),
  digest_frequencies: z.array(z.string()),
})
export type NotificationPreferences = z.infer<typeof notificationPreferencesSchema>

export interface ReportQuery {
  from?: string
  to?: string
  limit?: number
}

function qs(params: Record<string, string | number | undefined>): string {
  const search = new URLSearchParams()
  Object.entries(params).forEach(([key, value]) => {
    if (value !== undefined && value !== '') search.set(key, String(value))
  })
  const text = search.toString()
  return text ? `?${text}` : ''
}

function tenantBase(tenantId: string): string {
  return `/tenants/${tenantId}`
}

export function fetchReportsIndex(tenantId: string): Promise<ReportsIndex> {
  return api
    .get<ReportsIndex>(`${tenantBase(tenantId)}/reports`)
    .then((data) => reportsIndexSchema.parse(data))
}

export function generateReport(
  tenantId: string,
  type: string,
  query: ReportQuery = {},
): Promise<Report> {
  return api
    .get<Report>(`${tenantBase(tenantId)}/reports/${type}${qs({ ...query })}`)
    .then((data) => reportSchema.parse(data))
}

export function downloadReport(
  tenantId: string,
  type: string,
  query: ReportQuery = {},
): Promise<Blob> {
  return api.download(`${tenantBase(tenantId)}/reports/${type}/export${qs({ ...query })}`)
}

export function fetchAnalytics(tenantId: string): Promise<Analytics> {
  return api
    .get<Analytics>(`${tenantBase(tenantId)}/analytics/overview`)
    .then((data) => analyticsSchema.parse(data))
}

export function fetchSupportTickets(
  tenantId: string,
  filters: Record<string, string | undefined> = {},
): Promise<SupportTicket[]> {
  return api
    .get<SupportTicket[]>(`${tenantBase(tenantId)}/support-tickets${qs(filters)}`)
    .then((rows) => z.array(supportTicketSchema).parse(rows))
}

export function fetchSupportTicket(tenantId: string, ticketId: string): Promise<SupportTicket> {
  return api
    .get<SupportTicket>(`${tenantBase(tenantId)}/support-tickets/${ticketId}`)
    .then((row) => supportTicketSchema.parse(row))
}

export interface SupportTicketInput {
  subject: string
  description: string
  category?: string
  priority?: string
}

export function createSupportTicket(
  tenantId: string,
  input: SupportTicketInput,
): Promise<SupportTicket> {
  return api
    .post<SupportTicket>(`${tenantBase(tenantId)}/support-tickets`, input)
    .then((row) => supportTicketSchema.parse(row))
}

export function replyToSupportTicket(
  tenantId: string,
  ticketId: string,
  body: string,
): Promise<SupportTicket> {
  return api
    .post<SupportTicket>(`${tenantBase(tenantId)}/support-tickets/${ticketId}/messages`, { body })
    .then((row) => supportTicketSchema.parse(row))
}

export function updateSupportTicket(
  tenantId: string,
  ticketId: string,
  input: { status: 'open' | 'closed' },
): Promise<SupportTicket> {
  return api
    .patch<SupportTicket>(`${tenantBase(tenantId)}/support-tickets/${ticketId}`, input)
    .then((row) => supportTicketSchema.parse(row))
}

export function fetchPlatformReportsIndex(): Promise<ReportsIndex> {
  return api.get<ReportsIndex>('/platform/reports').then((data) => reportsIndexSchema.parse(data))
}

export function generatePlatformReport(type: string, query: ReportQuery = {}): Promise<Report> {
  return api
    .get<Report>(`/platform/reports/${type}${qs({ ...query })}`)
    .then((data) => reportSchema.parse(data))
}

export function downloadPlatformReport(type: string, query: ReportQuery = {}): Promise<Blob> {
  return api.download(`/platform/reports/${type}/export${qs({ ...query })}`)
}

export function fetchPlatformAnalytics(): Promise<Analytics> {
  return api.get<Analytics>('/platform/analytics/overview').then((data) => analyticsSchema.parse(data))
}

export function fetchPlatformSupportTickets(
  filters: Record<string, string | undefined> = {},
): Promise<SupportTicket[]> {
  return api
    .get<SupportTicket[]>(`/platform/support-tickets${qs(filters)}`)
    .then((rows) => z.array(supportTicketSchema).parse(rows))
}

export function fetchPlatformSupportTicket(ticketId: string): Promise<SupportTicket> {
  return api
    .get<SupportTicket>(`/platform/support-tickets/${ticketId}`)
    .then((row) => supportTicketSchema.parse(row))
}

export function replyToPlatformSupportTicket(
  ticketId: string,
  body: string,
  internal = false,
): Promise<SupportTicket> {
  return api
    .post<SupportTicket>(`/platform/support-tickets/${ticketId}/messages`, { body, internal })
    .then((row) => supportTicketSchema.parse(row))
}

export function updatePlatformSupportTicket(
  ticketId: string,
  input: { status?: string; priority?: string; assigned_to?: string | null },
): Promise<SupportTicket> {
  return api
    .patch<SupportTicket>(`/platform/support-tickets/${ticketId}`, input)
    .then((row) => supportTicketSchema.parse(row))
}

export function fetchNotifications(filter?: string): Promise<NotificationsEnvelope> {
  return api
    .get<NotificationsEnvelope>(`/me/notifications${qs({ filter })}`)
    .then((data) => notificationsEnvelopeSchema.parse(data))
}

export function markNotificationRead(notificationId: string): Promise<AppNotification> {
  return api
    .post<AppNotification>(`/me/notifications/${notificationId}/read`)
    .then((row) => notificationSchema.parse(row))
}

export function markAllNotificationsRead(): Promise<{ marked: number }> {
  return api.post<{ marked: number }>('/me/notifications/read-all')
}

export function fetchNotificationPreferences(): Promise<NotificationPreferences> {
  return api
    .get<NotificationPreferences>('/me/notification-preferences')
    .then((data) => notificationPreferencesSchema.parse(data))
}

export function updateNotificationPreferences(
  input: Partial<{
    in_app_enabled: boolean
    email_enabled: boolean
    digest_frequency: string
    event_preferences: Record<string, boolean>
  }>,
): Promise<NotificationPreferences> {
  return api
    .put<NotificationPreferences>('/me/notification-preferences', input)
    .then((data) => notificationPreferencesSchema.parse(data))
}
