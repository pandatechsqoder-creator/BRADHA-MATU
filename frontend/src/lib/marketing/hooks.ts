import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, getStoredToken } from '../api/client'

export type PageStatus = 'draft' | 'review' | 'approved' | 'scheduled' | 'published' | 'archived'
export type PageType = 'page' | 'landing' | 'legal' | 'system'

export const PAGE_STATUSES: PageStatus[] = [
  'draft',
  'review',
  'approved',
  'scheduled',
  'published',
  'archived',
]

export const SECTION_TYPES = [
  'hero',
  'rich_text',
  'service_grid',
  'industries',
  'pos_features',
  'process',
  'faq',
  'faq_library',
  'testimonials',
  'cta',
  'contact',
  'form',
  'legal',
] as const

export type SectionType = (typeof SECTION_TYPES)[number]

export interface MarketingPageSummary {
  id: string
  slug: string
  draft_slug: string | null
  effective_slug: string
  title: string
  type: PageType
  status: PageStatus
  is_home: boolean
  noindex: boolean
  published_at: string | null
  scheduled_at: string | null
  is_live: boolean
  has_pending_changes: boolean
  updated_at: string | null
}

export interface PageSection {
  id?: number
  type: string
  sort_order?: number
  content: Record<string, unknown>
  settings: Record<string, unknown>
  is_visible: boolean
}

export interface PageSeo {
  title: string | null
  description: string | null
  canonical_url: string | null
  robots_index: boolean
  robots_follow: boolean
  og_title: string | null
  og_description: string | null
  twitter_card: string
  priority: number
  change_frequency: string
}

export interface PageVersion {
  id: string
  version_no: number
  status: string
  change_summary: string | null
  created_by: number | null
  created_at: string | null
  published_at: string | null
}

export interface MarketingPage extends MarketingPageSummary {
  template: string
  requires_auth: boolean
  locale: string
  created_by: number | null
  reviewed_by: number | null
  approved_by: number | null
  published_by: number | null
  sections: PageSection[]
  seo: PageSeo
  versions: PageVersion[]
  current_version_id: string | null
}

export interface PageListResponse {
  pages: MarketingPageSummary[]
  status_counts: Record<string, number>
  types: string[]
}

export interface MarketingOverview {
  pages: {
    total: number
    by_status: Record<PageStatus, number>
    live: number
    scheduled: number
  }
  media: { assets: number; storage_bytes: number }
  navigation: { menus: number; menu_items: number }
  content: {
    banners: number
    active_banners: number
    ctas: number
    faqs: number
    testimonials: number
    approved_testimonials: number
  }
  workflow: { separation_of_duties: boolean }
}

export interface MenuItem {
  id: number
  label: string
  page_id: string | null
  url: string | null
  target: string
  sort_order: number
  is_visible: boolean
  children: MenuItem[]
}

export interface Menu {
  id: number
  key: string
  name: string
  items: MenuItem[]
}

export interface Banner {
  id: string
  key: string
  title: string
  body: string | null
  media_asset_id: string | null
  image_url: string | null
  link_url: string | null
  starts_at: string | null
  ends_at: string | null
  is_active: boolean
}

export interface Cta {
  id: string
  key: string
  label: string
  url: string
  form_type: string | null
  style: string
  is_active: boolean
}

export interface Faq {
  id: string
  page_id: string | null
  category: string | null
  question: string
  answer: string
  sort_order: number
  is_visible: boolean
}

export interface Testimonial {
  id: string
  page_id: string | null
  quote: string
  author_name: string
  author_role: string | null
  business_name: string | null
  media_asset_id: string | null
  image_url: string | null
  is_approved: boolean
  sort_order: number
}

export interface MediaFolder {
  id: string
  parent_id: string | null
  name: string
  slug: string
  path: string
  asset_count?: number
}

export interface MediaAsset {
  id: string
  folder_id: string | null
  folder_path: string | null
  filename: string
  mime: string
  extension: string
  size_bytes: number
  width: number | null
  height: number | null
  version: number
  alt_text: string | null
  caption: string | null
  description: string | null
  url: string
  usage_count: number
  created_at: string | null
}

export interface MediaUsage {
  id: number
  usable_type: string
  usable_id: number
  field: string
  used_at: string | null
}

export interface MediaAssetDetail {
  asset: MediaAsset
  versions: { version: number; size_bytes: number; checksum: string; created_at: string | null }[]
  usages: MediaUsage[]
}

export interface MediaPagination {
  page: number
  per_page: number
  total: number
  last_page: number
}

function invalidate(queryClient: ReturnType<typeof useQueryClient>) {
  void queryClient.invalidateQueries({ queryKey: ['marketing'] })
}

function useMarketingInvalidator() {
  const queryClient = useQueryClient()
  return () => invalidate(queryClient)
}

export function useMarketingOverview() {
  return useQuery({
    queryKey: ['marketing', 'overview'],
    queryFn: () => api.get<MarketingOverview>('/platform/marketing/overview'),
    enabled: getStoredToken() !== null,
    retry: false,
  })
}

export function useMarketingPages(params: { search?: string; status?: string; type?: string }) {
  return useQuery({
    queryKey: ['marketing', 'pages', params],
    queryFn: () => {
      const query = new URLSearchParams()
      if (params.search) query.set('search', params.search)
      if (params.status) query.set('status', params.status)
      if (params.type) query.set('type', params.type)
      return api.get<PageListResponse>(`/platform/marketing/pages?${query.toString()}`)
    },
    retry: false,
  })
}

export function useMarketingPage(pageId: string | undefined) {
  return useQuery({
    queryKey: ['marketing', 'pages', pageId],
    queryFn: () => api.get<{ page: MarketingPage }>(`/platform/marketing/pages/${pageId}`),
    enabled: Boolean(pageId),
    retry: false,
  })
}

export interface PageInput {
  title?: string
  slug?: string
  type?: PageType
  template?: string
  locale?: string
  requires_auth?: boolean
  noindex?: boolean
  seo?: Partial<PageSeo>
}

export function useCreateMarketingPage() {
  const invalidateAll = useMarketingInvalidator()
  return useMutation({
    mutationFn: (input: PageInput) =>
      api.post<{ page: MarketingPage }>('/platform/marketing/pages', {
        title: input.title,
        slug: input.slug,
        type: input.type ?? 'page',
        ...(input.seo ? { seo: input.seo } : {}),
      }),
    onSuccess: invalidateAll,
  })
}

export function useUpdateMarketingPage(pageId: string | undefined) {
  const invalidateAll = useMarketingInvalidator()
  return useMutation({
    mutationFn: (input: PageInput) =>
      api.patch<{ page: MarketingPage }>(`/platform/marketing/pages/${pageId}`, input),
    onSuccess: invalidateAll,
  })
}

export function useReplaceSections(pageId: string | undefined) {
  const invalidateAll = useMarketingInvalidator()
  return useMutation({
    mutationFn: (sections: PageSection[]) =>
      api.put<{ page: MarketingPage }>(`/platform/marketing/pages/${pageId}/sections`, { sections }),
    onSuccess: invalidateAll,
  })
}

export type PageTransition =
  | 'submit-review'
  | 'approve'
  | 'reject'
  | 'schedule'
  | 'publish'
  | 'unpublish'

export function usePageTransition(pageId: string | undefined) {
  const invalidateAll = useMarketingInvalidator()
  return useMutation({
    mutationFn: ({
      action,
      payload,
    }: {
      action: PageTransition
      payload?: Record<string, unknown>
    }) =>
      api.post<{ page: MarketingPage; version?: PageVersion }>(
        `/platform/marketing/pages/${pageId}/${action}`,
        payload ?? {},
      ),
    onSuccess: invalidateAll,
  })
}

export function useRestorePageVersion(pageId: string | undefined) {
  const invalidateAll = useMarketingInvalidator()
  return useMutation({
    mutationFn: (versionId: string) =>
      api.post<{ page: MarketingPage }>(
        `/platform/marketing/pages/${pageId}/versions/${versionId}/restore`,
      ),
    onSuccess: invalidateAll,
  })
}

export function useDeleteMarketingPage() {
  const invalidateAll = useMarketingInvalidator()
  return useMutation({
    mutationFn: (pageId: string) =>
      api.delete<{ deleted: boolean }>(`/platform/marketing/pages/${pageId}`),
    onSuccess: invalidateAll,
  })
}

export function usePagePreview(pageId: string | undefined) {
  return useMutation({
    mutationFn: (versionId?: string) =>
      api.get<{ url: string; version_id: string; version_no: number; expires_at: string }>(
        `/platform/marketing/pages/${pageId}/preview${versionId ? `?version=${versionId}` : ''}`,
      ),
  })
}

export function useMenus() {
  return useQuery({
    queryKey: ['marketing', 'menus'],
    queryFn: () => api.get<{ menus: Menu[] }>('/platform/marketing/menus'),
    retry: false,
  })
}

export function useCreateMenu() {
  const invalidateAll = useMarketingInvalidator()
  return useMutation({
    mutationFn: (input: { key: string; name: string }) =>
      api.post<{ menu: Menu }>('/platform/marketing/menus', input),
    onSuccess: invalidateAll,
  })
}

export function useDeleteMenu() {
  const invalidateAll = useMarketingInvalidator()
  return useMutation({
    mutationFn: (key: string) => api.delete<{ deleted: boolean }>(`/platform/marketing/menus/${key}`),
    onSuccess: invalidateAll,
  })
}

export interface MenuItemInput {
  label: string
  page_id?: string | null
  url?: string | null
  parent_id?: number | null
  target?: string
  sort_order?: number
  is_visible?: boolean
}

export function useCreateMenuItem(menuKey: string) {
  const invalidateAll = useMarketingInvalidator()
  return useMutation({
    mutationFn: (input: MenuItemInput) =>
      api.post<{ item: MenuItem }>(`/platform/marketing/menus/${menuKey}/items`, input),
    onSuccess: invalidateAll,
  })
}

export function useUpdateMenuItem(menuKey: string) {
  const invalidateAll = useMarketingInvalidator()
  return useMutation({
    mutationFn: ({ itemId, input }: { itemId: number; input: Partial<MenuItemInput> }) =>
      api.patch<{ item: MenuItem }>(`/platform/marketing/menus/${menuKey}/items/${itemId}`, input),
    onSuccess: invalidateAll,
  })
}

export function useDeleteMenuItem(menuKey: string) {
  const invalidateAll = useMarketingInvalidator()
  return useMutation({
    mutationFn: (itemId: number) =>
      api.delete<{ deleted: boolean }>(`/platform/marketing/menus/${menuKey}/items/${itemId}`),
    onSuccess: invalidateAll,
  })
}

export function useBanners() {
  return useQuery({
    queryKey: ['marketing', 'banners'],
    queryFn: () => api.get<{ banners: Banner[] }>('/platform/marketing/banners'),
    retry: false,
  })
}

export function useCreateBanner() {
  const invalidateAll = useMarketingInvalidator()
  return useMutation({
    mutationFn: (input: Record<string, unknown>) =>
      api.post<{ banner: Banner }>('/platform/marketing/banners', input),
    onSuccess: invalidateAll,
  })
}

export function useUpdateBanner() {
  const invalidateAll = useMarketingInvalidator()
  return useMutation({
    mutationFn: ({ bannerId, input }: { bannerId: string; input: Record<string, unknown> }) =>
      api.patch<{ banner: Banner }>(`/platform/marketing/banners/${bannerId}`, input),
    onSuccess: invalidateAll,
  })
}

export function useDeleteBanner() {
  const invalidateAll = useMarketingInvalidator()
  return useMutation({
    mutationFn: (bannerId: string) =>
      api.delete<{ deleted: boolean }>(`/platform/marketing/banners/${bannerId}`),
    onSuccess: invalidateAll,
  })
}

export function useCtas() {
  return useQuery({
    queryKey: ['marketing', 'ctas'],
    queryFn: () => api.get<{ ctas: Cta[] }>('/platform/marketing/ctas'),
    retry: false,
  })
}

export function useCreateCta() {
  const invalidateAll = useMarketingInvalidator()
  return useMutation({
    mutationFn: (input: Record<string, unknown>) =>
      api.post<{ cta: Cta }>('/platform/marketing/ctas', input),
    onSuccess: invalidateAll,
  })
}

export function useUpdateCta() {
  const invalidateAll = useMarketingInvalidator()
  return useMutation({
    mutationFn: ({ ctaId, input }: { ctaId: string; input: Record<string, unknown> }) =>
      api.patch<{ cta: Cta }>(`/platform/marketing/ctas/${ctaId}`, input),
    onSuccess: invalidateAll,
  })
}

export function useDeleteCta() {
  const invalidateAll = useMarketingInvalidator()
  return useMutation({
    mutationFn: (ctaId: string) => api.delete<{ deleted: boolean }>(`/platform/marketing/ctas/${ctaId}`),
    onSuccess: invalidateAll,
  })
}

export function useFaqs() {
  return useQuery({
    queryKey: ['marketing', 'faqs'],
    queryFn: () => api.get<{ faqs: Faq[] }>('/platform/marketing/faqs'),
    retry: false,
  })
}

export function useCreateFaq() {
  const invalidateAll = useMarketingInvalidator()
  return useMutation({
    mutationFn: (input: Record<string, unknown>) =>
      api.post<{ faq: Faq }>('/platform/marketing/faqs', input),
    onSuccess: invalidateAll,
  })
}

export function useUpdateFaq() {
  const invalidateAll = useMarketingInvalidator()
  return useMutation({
    mutationFn: ({ faqId, input }: { faqId: string; input: Record<string, unknown> }) =>
      api.patch<{ faq: Faq }>(`/platform/marketing/faqs/${faqId}`, input),
    onSuccess: invalidateAll,
  })
}

export function useDeleteFaq() {
  const invalidateAll = useMarketingInvalidator()
  return useMutation({
    mutationFn: (faqId: string) => api.delete<{ deleted: boolean }>(`/platform/marketing/faqs/${faqId}`),
    onSuccess: invalidateAll,
  })
}

export function useTestimonials() {
  return useQuery({
    queryKey: ['marketing', 'testimonials'],
    queryFn: () => api.get<{ testimonials: Testimonial[] }>('/platform/marketing/testimonials'),
    retry: false,
  })
}

export function useCreateTestimonial() {
  const invalidateAll = useMarketingInvalidator()
  return useMutation({
    mutationFn: (input: Record<string, unknown>) =>
      api.post<{ testimonial: Testimonial }>('/platform/marketing/testimonials', input),
    onSuccess: invalidateAll,
  })
}

export function useUpdateTestimonial() {
  const invalidateAll = useMarketingInvalidator()
  return useMutation({
    mutationFn: ({ testimonialId, input }: { testimonialId: string; input: Record<string, unknown> }) =>
      api.patch<{ testimonial: Testimonial }>(`/platform/marketing/testimonials/${testimonialId}`, input),
    onSuccess: invalidateAll,
  })
}

export function useDeleteTestimonial() {
  const invalidateAll = useMarketingInvalidator()
  return useMutation({
    mutationFn: (testimonialId: string) =>
      api.delete<{ deleted: boolean }>(`/platform/marketing/testimonials/${testimonialId}`),
    onSuccess: invalidateAll,
  })
}

export function useMediaFolders() {
  return useQuery({
    queryKey: ['marketing', 'media', 'folders'],
    queryFn: () => api.get<{ folders: MediaFolder[] }>('/platform/marketing/media/folders'),
    retry: false,
  })
}

export function useCreateMediaFolder() {
  const invalidateAll = useMarketingInvalidator()
  return useMutation({
    mutationFn: (input: { name: string; parent_id?: string | null }) =>
      api.post<{ folder: MediaFolder }>('/platform/marketing/media/folders', input),
    onSuccess: invalidateAll,
  })
}

export function useDeleteMediaFolder() {
  const invalidateAll = useMarketingInvalidator()
  return useMutation({
    mutationFn: (folderId: string) =>
      api.delete<{ deleted: boolean }>(`/platform/marketing/media/folders/${folderId}`),
    onSuccess: invalidateAll,
  })
}

export function useMediaAssets(params: {
  folder?: string
  search?: string
  mime?: string
  page?: number
}) {
  return useQuery({
    queryKey: ['marketing', 'media', 'assets', params],
    queryFn: async () => {
      const query = new URLSearchParams()
      if (params.folder) query.set('folder', params.folder)
      if (params.search) query.set('search', params.search)
      if (params.mime) query.set('mime', params.mime)
      if (params.page) query.set('page', String(params.page))
      const { data, meta } = await api.getWithMeta<{ assets: MediaAsset[] }>(
        `/platform/marketing/media/assets?${query.toString()}`,
      )
      const pagination = (
        meta as { pagination?: MediaPagination } | undefined
      )?.pagination ?? { page: 1, per_page: 24, total: data.assets.length, last_page: 1 }
      return { assets: data.assets, pagination }
    },
    retry: false,
  })
}

export function useMediaAsset(assetId: string | undefined) {
  return useQuery({
    queryKey: ['marketing', 'media', 'assets', assetId],
    queryFn: () => api.get<MediaAssetDetail>(`/platform/marketing/media/assets/${assetId}`),
    enabled: Boolean(assetId),
    retry: false,
  })
}

export function useUploadMedia() {
  const invalidateAll = useMarketingInvalidator()
  return useMutation({
    mutationFn: async ({
      file,
      folderId,
      altText,
      caption,
    }: {
      file: File
      folderId?: string | null
      altText?: string
      caption?: string
    }) => {
      const form = new FormData()
      form.append('file', file)
      if (folderId) form.append('folder_id', folderId)
      if (altText) form.append('alt_text', altText)
      if (caption) form.append('caption', caption)
      return api.upload<{ asset: MediaAsset; duplicate: boolean }>(
        '/platform/marketing/media/assets',
        form,
      )
    },
    onSuccess: invalidateAll,
  })
}

export function useUpdateMediaAsset() {
  const invalidateAll = useMarketingInvalidator()
  return useMutation({
    mutationFn: ({
      assetId,
      input,
    }: {
      assetId: string
      input: { alt_text?: string | null; caption?: string | null; description?: string | null; folder_id?: string | null }
    }) => api.patch<{ asset: MediaAsset }>(`/platform/marketing/media/assets/${assetId}`, input),
    onSuccess: invalidateAll,
  })
}

export function useReplaceMediaAsset() {
  const invalidateAll = useMarketingInvalidator()
  return useMutation({
    mutationFn: async ({ assetId, file }: { assetId: string; file: File }) => {
      const form = new FormData()
      form.append('file', file)
      return api.upload<{ asset: MediaAsset; usage_count: number; usage_warning: string | null }>(
        `/platform/marketing/media/assets/${assetId}/replace`,
        form,
      )
    },
    onSuccess: invalidateAll,
  })
}

export function useDeleteMediaAsset() {
  const invalidateAll = useMarketingInvalidator()
  return useMutation({
    mutationFn: (assetId: string) =>
      api.delete<{ deleted: boolean; usage_count: number; usage_warning: string | null }>(
        `/platform/marketing/media/assets/${assetId}`,
      ),
    onSuccess: invalidateAll,
  })
}
