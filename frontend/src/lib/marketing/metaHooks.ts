import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, getStoredToken } from '../api/client'

export interface MetaConnection {
  id: string
  status: string
  account_label: string | null
  scopes: string[]
  connected_by: string | null
  last_synced_at: string | null
  last_error: string | null
  expires_at: string | null
}

export interface MetaSyncRun {
  id: string
  type: string
  status: string
  started_at: string | null
  finished_at: string | null
  counts: Record<string, number>
  error: string | null
}

export interface MetaIntegrationLog {
  level: string
  event: string
  message: string
  created_at: string | null
}

export interface MetaPageAsset {
  id: string
  name: string
  category: string | null
  status: string | null
  instagram_accounts: { id: string; username: string | null }[]
}

export interface MetaAdAccountAsset {
  id: string
  name: string
  currency: string | null
  spend_cap: number | null
  status: string | null
}

export interface MetaCampaignAsset {
  id: string
  name: string
  status: string | null
  ad_account: string | null
  budget_amount: number | null
  spend_amount: number | null
  impressions: number | null
  clicks: number | null
  synced_at: string | null
}

export interface MetaIntegrationStatus {
  provider: string
  configured: boolean
  redirect_uri: string | null
  required_scopes: string[]
  connection: MetaConnection | null
  capabilities: {
    pages: boolean
    instagram: boolean
    publishing: boolean
    insights: boolean
    ad_reporting: boolean
    spend_actions: boolean
  }
  counts: {
    business_accounts: number
    pages: number
    instagram_accounts: number
    ad_accounts: number
    campaigns: number
    social_posts: number
    published_posts: number
  }
  assets: {
    businesses: { id: string; name: string }[]
    pages: MetaPageAsset[]
    ad_accounts: MetaAdAccountAsset[]
    campaigns: MetaCampaignAsset[]
  }
  runs: MetaSyncRun[]
  logs: MetaIntegrationLog[]
}

export type MetaSyncType = 'assets' | 'insights' | 'ads' | 'all'

const statusKey = ['marketing', 'integrations', 'meta'] as const

export function useMetaIntegration() {
  return useQuery({
    queryKey: statusKey,
    queryFn: () => api.get<MetaIntegrationStatus>('/platform/marketing/integrations/meta'),
    enabled: getStoredToken() !== null,
    retry: false,
  })
}

export function useConnectMeta() {
  return useMutation({
    mutationFn: () =>
      api.get<{ authorization_url: string }>('/platform/marketing/integrations/meta/connect'),
  })
}

export function useDisconnectMeta() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: () => api.delete<{ disconnected: boolean; status: MetaIntegrationStatus }>(
      '/platform/marketing/integrations/meta',
    ),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: statusKey })
      void queryClient.invalidateQueries({ queryKey: ['marketing', 'social-posts'] })
    },
  })
}

export function useSyncMeta() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (type: MetaSyncType) =>
      api.post<{ runs: { id: string; type: string; status: string }[] }>(
        '/platform/marketing/integrations/meta/sync',
        { type },
      ),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: statusKey }),
  })
}
