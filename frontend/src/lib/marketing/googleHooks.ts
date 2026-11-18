import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, getStoredToken } from '../api/client'

export interface GoogleConnection {
  id: string
  status: string
  account_label: string | null
  scopes: string[]
  connected_by: string | null
  last_synced_at: string | null
  last_error: string | null
  expires_at: string | null
}

export interface GoogleSyncRun {
  id: string
  type: string
  status: string
  started_at: string | null
  finished_at: string | null
  counts: Record<string, number>
  error: string | null
}

export interface GoogleIntegrationLog {
  level: string
  event: string
  message: string
  created_at: string | null
}

export interface GoogleIntegrationStatus {
  provider: string
  configured: boolean
  redirect_uri: string | null
  required_scopes: string[]
  connection: GoogleConnection | null
  capabilities: {
    search_console: boolean
    ga4: boolean
    business_profile: boolean
    reviews: boolean
    tag_manager: boolean
  }
  counts: {
    search_properties: number
    search_metric_rows: number
    ga4_properties: number
    ga4_metric_rows: number
    business_accounts: number
    business_locations: number
    gtm_containers: number
  }
  runs: GoogleSyncRun[]
  logs: GoogleIntegrationLog[]
}

export type GoogleSyncType = 'search_console' | 'ga4' | 'business' | 'gtm' | 'all'

const statusKey = ['marketing', 'integrations', 'google'] as const

export function useGoogleIntegration() {
  return useQuery({
    queryKey: statusKey,
    queryFn: () => api.get<GoogleIntegrationStatus>('/platform/marketing/integrations/google'),
    enabled: getStoredToken() !== null,
    retry: false,
  })
}

export function useConnectGoogle() {
  return useMutation({
    mutationFn: () =>
      api.get<{ authorization_url: string }>('/platform/marketing/integrations/google/connect'),
  })
}

export function useDisconnectGoogle() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: () => api.delete<{ disconnected: boolean; status: GoogleIntegrationStatus }>(
      '/platform/marketing/integrations/google',
    ),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: statusKey }),
  })
}

export function useSyncGoogle() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (type: GoogleSyncType) =>
      api.post<{ runs: { id: string; type: string; status: string }[] }>(
        '/platform/marketing/integrations/google/sync',
        { type },
      ),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: statusKey }),
  })
}
