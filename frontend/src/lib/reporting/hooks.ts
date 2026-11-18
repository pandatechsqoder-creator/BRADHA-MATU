import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  createSupportTicket,
  downloadPlatformReport,
  downloadReport,
  fetchAnalytics,
  fetchNotificationPreferences,
  fetchNotifications,
  fetchPlatformAnalytics,
  fetchPlatformReportsIndex,
  fetchPlatformSupportTicket,
  fetchPlatformSupportTickets,
  fetchReportsIndex,
  fetchSupportTicket,
  fetchSupportTickets,
  generatePlatformReport,
  generateReport,
  markAllNotificationsRead,
  markNotificationRead,
  replyToPlatformSupportTicket,
  replyToSupportTicket,
  updateNotificationPreferences,
  updatePlatformSupportTicket,
  updateSupportTicket,
  type ReportQuery,
  type SupportTicketInput,
} from '../api/reporting'

function tenantKey(tenantId: string | null | undefined, ...rest: unknown[]) {
  return ['tenants', tenantId, ...rest] as const
}

export function useReportsIndex(tenantId: string | null | undefined) {
  return useQuery({
    queryKey: tenantKey(tenantId, 'reports'),
    queryFn: () => fetchReportsIndex(tenantId as string),
    enabled: Boolean(tenantId),
    retry: false,
  })
}

export function useGenerateReport(tenantId: string | undefined) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ type, query }: { type: string; query?: ReportQuery }) =>
      generateReport(tenantId as string, type, query),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: tenantKey(tenantId, 'reports') })
    },
  })
}

export function useDownloadReport(tenantId: string | undefined) {
  return useMutation({
    mutationFn: ({ type, query }: { type: string; query?: ReportQuery }) =>
      downloadReport(tenantId as string, type, query),
  })
}

export function useAnalytics(tenantId: string | null | undefined) {
  return useQuery({
    queryKey: tenantKey(tenantId, 'analytics'),
    queryFn: () => fetchAnalytics(tenantId as string),
    enabled: Boolean(tenantId),
    retry: false,
  })
}

export function useSupportTickets(
  tenantId: string | null | undefined,
  filters: Record<string, string | undefined> = {},
) {
  return useQuery({
    queryKey: tenantKey(tenantId, 'support-tickets', filters),
    queryFn: () => fetchSupportTickets(tenantId as string, filters),
    enabled: Boolean(tenantId),
    retry: false,
  })
}

export function useSupportTicket(
  tenantId: string | null | undefined,
  ticketId: string | undefined,
) {
  return useQuery({
    queryKey: tenantKey(tenantId, 'support-tickets', ticketId),
    queryFn: () => fetchSupportTicket(tenantId as string, ticketId as string),
    enabled: Boolean(tenantId && ticketId),
    retry: false,
  })
}

export function useCreateSupportTicket(tenantId: string | undefined) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (input: SupportTicketInput) => createSupportTicket(tenantId as string, input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: tenantKey(tenantId, 'support-tickets') })
    },
  })
}

export function useReplySupportTicket(tenantId: string | undefined) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ ticketId, body }: { ticketId: string; body: string }) =>
      replyToSupportTicket(tenantId as string, ticketId, body),
    onSuccess: (_data, variables) => {
      void queryClient.invalidateQueries({ queryKey: tenantKey(tenantId, 'support-tickets') })
      void queryClient.invalidateQueries({
        queryKey: tenantKey(tenantId, 'support-tickets', variables.ticketId),
      })
    },
  })
}

export function useUpdateSupportTicket(tenantId: string | undefined) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ ticketId, status }: { ticketId: string; status: 'open' | 'closed' }) =>
      updateSupportTicket(tenantId as string, ticketId, { status }),
    onSuccess: (_data, variables) => {
      void queryClient.invalidateQueries({ queryKey: tenantKey(tenantId, 'support-tickets') })
      void queryClient.invalidateQueries({
        queryKey: tenantKey(tenantId, 'support-tickets', variables.ticketId),
      })
    },
  })
}

export function usePlatformReportsIndex() {
  return useQuery({
    queryKey: ['platform', 'reports'],
    queryFn: fetchPlatformReportsIndex,
    retry: false,
  })
}

export function useGeneratePlatformReport() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ type, query }: { type: string; query?: ReportQuery }) =>
      generatePlatformReport(type, query),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['platform', 'reports'] })
    },
  })
}

export function useDownloadPlatformReport() {
  return useMutation({
    mutationFn: ({ type, query }: { type: string; query?: ReportQuery }) =>
      downloadPlatformReport(type, query),
  })
}

export function usePlatformAnalytics() {
  return useQuery({
    queryKey: ['platform', 'analytics'],
    queryFn: fetchPlatformAnalytics,
    retry: false,
  })
}

export function usePlatformSupportTickets(filters: Record<string, string | undefined> = {}) {
  return useQuery({
    queryKey: ['platform', 'support-tickets', filters],
    queryFn: () => fetchPlatformSupportTickets(filters),
    retry: false,
  })
}

export function usePlatformSupportTicket(ticketId: string | undefined) {
  return useQuery({
    queryKey: ['platform', 'support-tickets', ticketId],
    queryFn: () => fetchPlatformSupportTicket(ticketId as string),
    enabled: Boolean(ticketId),
    retry: false,
  })
}

export function useReplyPlatformSupportTicket() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({
      ticketId,
      body,
      internal,
    }: {
      ticketId: string
      body: string
      internal?: boolean
    }) => replyToPlatformSupportTicket(ticketId, body, internal),
    onSuccess: (_data, variables) => {
      void queryClient.invalidateQueries({ queryKey: ['platform', 'support-tickets'] })
      void queryClient.invalidateQueries({
        queryKey: ['platform', 'support-tickets', variables.ticketId],
      })
    },
  })
}

export function useUpdatePlatformSupportTicket() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({
      ticketId,
      input,
    }: {
      ticketId: string
      input: { status?: string; priority?: string; assigned_to?: string | null }
    }) => updatePlatformSupportTicket(ticketId, input),
    onSuccess: (_data, variables) => {
      void queryClient.invalidateQueries({ queryKey: ['platform', 'support-tickets'] })
      void queryClient.invalidateQueries({
        queryKey: ['platform', 'support-tickets', variables.ticketId],
      })
    },
  })
}

export function useNotifications(filter?: string) {
  return useQuery({
    queryKey: ['me', 'notifications', filter ?? 'all'],
    queryFn: () => fetchNotifications(filter),
    retry: false,
  })
}

export function useMarkNotificationRead() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (notificationId: string) => markNotificationRead(notificationId),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['me', 'notifications'] })
    },
  })
}

export function useMarkAllNotificationsRead() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: () => markAllNotificationsRead(),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['me', 'notifications'] })
    },
  })
}

export function useNotificationPreferences() {
  return useQuery({
    queryKey: ['me', 'notification-preferences'],
    queryFn: fetchNotificationPreferences,
    retry: false,
  })
}

export function useUpdateNotificationPreferences() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: updateNotificationPreferences,
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['me', 'notification-preferences'] })
    },
  })
}
