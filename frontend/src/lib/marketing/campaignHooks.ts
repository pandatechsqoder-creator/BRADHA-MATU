import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, getStoredToken } from '../api/client'

export interface CampaignLandingPage {
  id: string
  slug: string
  title: string
}

export interface CampaignAudience {
  summary?: string
  segments?: string[]
}

export interface CampaignUtm {
  source?: string
  medium?: string
  campaign?: string
  term?: string
  content?: string
}

export interface CampaignAsset {
  id: number
  type: string
  status: string
  content: Record<string, unknown>
  media_asset: { id: string; filename: string; mime: string } | null
  created_by: string | null
  created_at: string | null
}

export interface CampaignApproval {
  decision: string
  notes: string | null
  requested_by: string | null
  decided_by: string | null
  decided_at: string | null
  created_at: string | null
}

export interface CampaignRevision {
  revision_no: number
  change_summary: string | null
  status: string | null
  budget_amount: string | null
  created_by: string | null
  created_at: string | null
}

export interface CampaignResults {
  reach?: number
  impressions?: number
  clicks?: number
  leads?: number
  conversions?: number
  spend?: number
  notes?: string
}

export interface Campaign {
  id: string
  name: string
  objective: string
  channel: string
  status: string
  audience: CampaignAudience
  budget_amount: number | null
  budget_currency: string
  starts_at: string | null
  ends_at: string | null
  landing_page: CampaignLandingPage | null
  utm: CampaignUtm
  utm_url: string | null
  owner: string | null
  approver: string | null
  approved_at: string | null
  activated_at: string | null
  results: CampaignResults
  created_by: string | null
  created_at: string | null
  updated_at: string | null
  assets?: CampaignAsset[]
  approvals?: CampaignApproval[]
  revisions?: CampaignRevision[]
}

export interface CampaignListResponse {
  campaigns: Campaign[]
  counts: Record<string, number>
}

export interface CampaignInput {
  name: string
  objective: string
  channel: string
  audience?: CampaignAudience | null
  budget_amount?: number | null
  budget_currency?: string
  starts_at?: string | null
  ends_at?: string | null
  landing_page_id?: string | null
  utm?: CampaignUtm | null
}

export type CampaignAction = 'submit-review' | 'approve' | 'reject' | 'activate' | 'pause' | 'resume' | 'complete' | 'cancel'

const listKey = ['marketing', 'campaigns'] as const

export function useCampaigns(filters: { status?: string; channel?: string }) {
  const query = new URLSearchParams()
  if (filters.status) query.set('status', filters.status)
  if (filters.channel) query.set('channel', filters.channel)

  return useQuery({
    queryKey: [...listKey, filters.status ?? 'all', filters.channel ?? 'all'],
    queryFn: () => api.get<CampaignListResponse>(`/platform/marketing/campaigns?${query.toString()}`),
    enabled: getStoredToken() !== null,
    retry: false,
  })
}

export function useCampaign(id: string | null) {
  return useQuery({
    queryKey: [...listKey, 'detail', id],
    queryFn: () => api.get<Campaign>(`/platform/marketing/campaigns/${id}`),
    enabled: id !== null && getStoredToken() !== null,
    retry: false,
  })
}

export function useCreateCampaign() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (input: CampaignInput) => api.post<Campaign>('/platform/marketing/campaigns', input),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: listKey }),
  })
}

export function useUpdateCampaign() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ id, input }: { id: string; input: Partial<CampaignInput> }) =>
      api.patch<Campaign>(`/platform/marketing/campaigns/${id}`, input),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: listKey }),
  })
}

export function useCampaignAction() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ id, action, payload }: { id: string; action: CampaignAction; payload?: Record<string, unknown> }) =>
      api.post<Campaign>(`/platform/marketing/campaigns/${id}/${action}`, payload ?? {}),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: listKey }),
  })
}

export function useAddCampaignAsset() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ id, input }: { id: string; input: { type: string; content?: Record<string, unknown> | null } }) =>
      api.post<Campaign>(`/platform/marketing/campaigns/${id}/assets`, input),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: listKey }),
  })
}

export function useRemoveCampaignAsset() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ id, assetId }: { id: string; assetId: number }) =>
      api.delete<Campaign>(`/platform/marketing/campaigns/${id}/assets/${assetId}`),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: listKey }),
  })
}

export function useRecordCampaignResults() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ id, results }: { id: string; results: CampaignResults }) =>
      api.post<Campaign>(`/platform/marketing/campaigns/${id}/results`, { results }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: listKey }),
  })
}
