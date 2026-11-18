import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, getStoredToken } from '../api/client'

export interface SocialPostTarget {
  id: string
  name: string
}

export interface SocialPostMedia {
  id: string
  filename: string
  mime: string
}

export interface SocialPostApproval {
  decision: string
  notes: string | null
  decided_by: string | null
  decided_at: string | null
  created_at: string | null
}

export interface SocialPost {
  id: string
  caption: string
  status: string
  link_url: string | null
  page: SocialPostTarget | null
  instagram_account: (SocialPostTarget & { username: string | null }) | null
  media_asset: SocialPostMedia | null
  scheduled_at: string | null
  published_at: string | null
  external_id: string | null
  error: string | null
  insights: Record<string, unknown>
  created_by: string | null
  approvals: SocialPostApproval[]
  created_at: string | null
  updated_at: string | null
}

export interface SocialPostPageOption {
  id: string
  name: string
  status: string | null
  instagram_accounts: { id: string; username: string | null }[]
}

export interface SocialMediaAssetOption {
  id: string
  filename: string
  mime: string
  url: string
}

export interface SocialPostsResponse {
  posts: SocialPost[]
  counts: Record<string, number>
  options: {
    pages: SocialPostPageOption[]
    media_assets: SocialMediaAssetOption[]
  }
}

export interface SocialPostInput {
  caption: string
  meta_page_id?: string | null
  meta_instagram_account_id?: string | null
  media_asset_id?: string | null
  link_url?: string | null
}

export type SocialPostAction = 'submit-review' | 'approve' | 'reject' | 'schedule' | 'publish'

const listKey = ['marketing', 'social-posts'] as const

export function useSocialPosts(status?: string) {
  return useQuery({
    queryKey: [...listKey, status ?? 'all'],
    queryFn: () => api.get<SocialPostsResponse>(
      `/platform/marketing/social-posts${status ? `?status=${encodeURIComponent(status)}` : ''}`,
    ),
    enabled: getStoredToken() !== null,
    retry: false,
  })
}

export function useCreateSocialPost() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (input: SocialPostInput) =>
      api.post<{ post: SocialPost }>('/platform/marketing/social-posts', input),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: listKey }),
  })
}

export function useUpdateSocialPost() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ id, input }: { id: string; input: SocialPostInput }) =>
      api.patch<{ post: SocialPost }>(`/platform/marketing/social-posts/${id}`, input),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: listKey }),
  })
}

export function useDeleteSocialPost() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (id: string) => api.delete<{ deleted: boolean }>(`/platform/marketing/social-posts/${id}`),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: listKey }),
  })
}

export function useSocialPostAction() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ id, action, payload }: { id: string; action: SocialPostAction; payload?: Record<string, unknown> }) =>
      api.post<{ post: SocialPost }>(`/platform/marketing/social-posts/${id}/${action}`, payload ?? {}),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: listKey }),
  })
}
