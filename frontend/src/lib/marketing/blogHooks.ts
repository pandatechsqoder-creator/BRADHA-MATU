import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, getStoredToken } from '../api/client'

export type BlogStatus = 'draft' | 'review' | 'approved' | 'scheduled' | 'published' | 'archived'

export const BLOG_STATUSES: BlogStatus[] = [
  'draft',
  'review',
  'approved',
  'scheduled',
  'published',
  'archived',
]

export interface BlogCategoryRef {
  id: string
  slug: string
  name: string
}

export interface BlogTagRef {
  id: string
  slug: string
  name: string
}

export interface BlogPost {
  id: string
  slug: string
  title: string
  excerpt: string | null
  body: string
  format: 'markdown' | 'plain'
  status: BlogStatus
  author_name: string
  author_id: string | null
  category: BlogCategoryRef | null
  tags: BlogTagRef[]
  related_ids: string[]
  reading_minutes: number
  seo_title: string | null
  seo_description: string | null
  canonical_url: string | null
  og_title: string | null
  og_description: string | null
  robots_index: boolean
  robots_follow: boolean
  noindex: boolean
  published_at: string | null
  scheduled_at: string | null
  is_live: boolean
  updated_at: string | null
}

export interface BlogPostInput {
  title: string
  slug?: string | null
  excerpt?: string | null
  body: string
  format?: 'markdown' | 'plain'
  category_id?: string | null
  tags?: string[]
  related_ids?: string[]
  author_name?: string | null
  seo_title?: string | null
  seo_description?: string | null
  robots_index?: boolean
  robots_follow?: boolean
  noindex?: boolean
}

export interface BlogListPayload {
  posts: BlogPost[]
  status_counts: Record<string, number>
  categories: BlogCategoryRef[]
  tags: BlogTagRef[]
}

export interface PostCategory {
  id: string
  slug: string
  name: string
  description: string | null
  parent_id: string | null
  sort_order: number
  post_count: number
}

export interface PostTag {
  id: string
  slug: string
  name: string
  post_count: number
}

export interface ResourceDownload {
  id: string
  slug: string
  title: string
  description: string | null
  media_asset_id: string | null
  media_asset_url: string | null
  requires_consent: boolean
  is_published: boolean
  download_count: number
  published_at: string | null
  updated_at: string | null
}

export type EditorialStatus = 'planned' | 'in_progress' | 'review' | 'done'

export interface EditorialItem {
  id: string
  title: string
  brief: string | null
  assignee_id: string | null
  assignee_name: string | null
  due_on: string | null
  status: EditorialStatus
  blog_post_id: string | null
  blog_post_title: string | null
  updated_at: string | null
}

function invalidate(queryClient: ReturnType<typeof useQueryClient>) {
  void queryClient.invalidateQueries({ queryKey: ['marketing', 'blog'] })
}

export function useBlogPosts() {
  return useQuery({
    queryKey: ['marketing', 'blog', 'posts'],
    queryFn: () => api.get<BlogListPayload>('/platform/marketing/posts'),
    enabled: getStoredToken() !== null,
    retry: false,
  })
}

export function useCreateBlogPost() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (input: BlogPostInput) => api.post<{ post: BlogPost }>('/platform/marketing/posts', input),
    onSuccess: () => invalidate(queryClient),
  })
}

export function useUpdateBlogPost(postId: string) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (input: Partial<BlogPostInput> & { slug?: string | null }) =>
      api.patch<{ post: BlogPost }>(`/platform/marketing/posts/${postId}`, input),
    onSuccess: () => invalidate(queryClient),
  })
}

export function useDeleteBlogPost(postId: string) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: () => api.delete<{ deleted: boolean }>(`/platform/marketing/posts/${postId}`),
    onSuccess: () => invalidate(queryClient),
  })
}

export function useBlogTransition(postId: string) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ action, payload }: { action: string; payload?: Record<string, unknown> }) =>
      api.post<{ post: BlogPost }>(`/platform/marketing/posts/${postId}/${action}`, payload ?? {}),
    onSuccess: () => invalidate(queryClient),
  })
}

export function usePostCategories() {
  return useQuery({
    queryKey: ['marketing', 'blog', 'categories'],
    queryFn: () => api.get<{ categories: PostCategory[] }>('/platform/marketing/post-categories'),
    enabled: getStoredToken() !== null,
    retry: false,
  })
}

export function useCreatePostCategory() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (input: { name: string; slug?: string | null; description?: string | null }) =>
      api.post<{ category: PostCategory }>('/platform/marketing/post-categories', input),
    onSuccess: () => invalidate(queryClient),
  })
}

export function usePostTags() {
  return useQuery({
    queryKey: ['marketing', 'blog', 'tags'],
    queryFn: () => api.get<{ tags: PostTag[] }>('/platform/marketing/post-tags'),
    enabled: getStoredToken() !== null,
    retry: false,
  })
}

export function useCreatePostTag() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (input: { name: string; slug?: string | null }) =>
      api.post<{ tag: PostTag }>('/platform/marketing/post-tags', input),
    onSuccess: () => invalidate(queryClient),
  })
}

export function useResourceDownloads() {
  return useQuery({
    queryKey: ['marketing', 'blog', 'resource-downloads'],
    queryFn: () => api.get<{ resource_downloads: ResourceDownload[] }>('/platform/marketing/resource-downloads'),
    enabled: getStoredToken() !== null,
    retry: false,
  })
}

export function useCreateResourceDownload() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (input: {
      title: string
      slug?: string | null
      description?: string | null
      media_asset_id?: string | null
      requires_consent: boolean
      is_published: boolean
    }) => api.post<{ resource_download: ResourceDownload }>('/platform/marketing/resource-downloads', input),
    onSuccess: () => invalidate(queryClient),
  })
}

export function useUpdateResourceDownload(resourceId: string) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (input: {
      title?: string
      description?: string | null
      media_asset_id?: string | null
      requires_consent?: boolean
      is_published?: boolean
    }) => api.patch<{ resource_download: ResourceDownload }>(`/platform/marketing/resource-downloads/${resourceId}`, input),
    onSuccess: () => invalidate(queryClient),
  })
}

export function useEditorialItems() {
  return useQuery({
    queryKey: ['marketing', 'blog', 'editorial-items'],
    queryFn: () => api.get<{ editorial_items: EditorialItem[] }>('/platform/marketing/editorial-items'),
    enabled: getStoredToken() !== null,
    retry: false,
  })
}

export function useCreateEditorialItem() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (input: { title: string; brief?: string | null; due_on?: string | null; status?: EditorialStatus }) =>
      api.post<{ editorial_item: EditorialItem }>('/platform/marketing/editorial-items', input),
    onSuccess: () => invalidate(queryClient),
  })
}

export function useUpdateEditorialItem(itemId: string) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (input: { status?: EditorialStatus; due_on?: string | null; brief?: string | null }) =>
      api.patch<{ editorial_item: EditorialItem }>(`/platform/marketing/editorial-items/${itemId}`, input),
    onSuccess: () => invalidate(queryClient),
  })
}
