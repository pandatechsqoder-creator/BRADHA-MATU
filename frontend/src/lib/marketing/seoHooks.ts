import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, getStoredToken } from '../api/client'

export interface SeoDiagnosticsSummary {
  pages: number
  indexable_pages: number
  redirects: number
  issues: number
  missing_metadata: number
  duplicate_titles: number
  duplicate_descriptions: number
  broken_links: number
  canonical_conflicts: number
  broken_canonicals: number
  redirect_chains: number
  orphan_pages: number
}

export interface SeoDiagnostics {
  generated_at: string
  summary: SeoDiagnosticsSummary
  issues: {
    missing_metadata: { path: string; page: string; problems: string[] }[]
    duplicate_titles: { value: string; paths: string[] }[]
    duplicate_descriptions: { value: string; paths: string[] }[]
    broken_links: { from: string; url: string; path: string }[]
    canonical_conflicts: { canonical: string; paths: string[] }[]
    broken_canonicals: { path: string; canonical: string }[]
    redirect_chains: { from: string; chain: string[]; loop: boolean }[]
    orphan_pages: { path: string; page: string }[]
  }
}

export interface VitalsMetrics {
  lcp_p75: number | null
  cls_p75: number | null
  inp_p75: number | null
  ttfb_p75: number | null
  lcp_good_pct: number | null
}

export interface VitalsSummary extends VitalsMetrics {
  samples: number
  devices: Record<string, number>
}

export interface VitalsPathMetrics extends VitalsMetrics {
  path: string
  samples: number
}

export interface SeoVitals {
  window_days: number
  summary: VitalsSummary
  by_path: VitalsPathMetrics[]
  recent: {
    path: string
    device: string
    lcp: number | null
    cls: number | null
    inp: number | null
    ttfb: number | null
    measured_at: string | null
  }[]
}

export interface SeoRedirect {
  id: string
  from_path: string
  to_path: string
  status_code: number
  is_active: boolean
  hits: number
  reason: string | null
  page: { id: string; slug: string; title: string } | null
  created_at: string | null
}

export interface RedirectInput {
  from_path: string
  to_path: string
  status_code?: number
  reason?: string | null
}

function invalidate(queryClient: ReturnType<typeof useQueryClient>) {
  void queryClient.invalidateQueries({ queryKey: ['marketing', 'seo'] })
}

export function useSeoDiagnostics() {
  return useQuery({
    queryKey: ['marketing', 'seo', 'diagnostics'],
    queryFn: () => api.get<SeoDiagnostics>('/platform/marketing/seo/diagnostics'),
    enabled: getStoredToken() !== null,
    retry: false,
  })
}

export function useSeoVitals(days: number, path: string) {
  return useQuery({
    queryKey: ['marketing', 'seo', 'vitals', days, path],
    queryFn: () => {
      const query = new URLSearchParams({ days: String(days) })
      if (path.trim() !== '') query.set('path', path.trim())
      return api.get<SeoVitals>(`/platform/marketing/seo/vitals?${query.toString()}`)
    },
    enabled: getStoredToken() !== null,
    retry: false,
  })
}

export function useSeoRedirects() {
  return useQuery({
    queryKey: ['marketing', 'seo', 'redirects'],
    queryFn: () => api.get<{ redirects: SeoRedirect[] }>('/platform/marketing/seo/redirects'),
    enabled: getStoredToken() !== null,
    retry: false,
  })
}

export function useCreateSeoRedirect() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (input: RedirectInput) =>
      api.post<{ redirect: SeoRedirect }>('/platform/marketing/seo/redirects', input),
    onSuccess: () => invalidate(queryClient),
  })
}

export function useUpdateSeoRedirect() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({
      redirectId,
      input,
    }: {
      redirectId: string
      input: Partial<Omit<RedirectInput, 'from_path'> & { is_active: boolean }>
    }) => api.patch<{ redirect: SeoRedirect }>(`/platform/marketing/seo/redirects/${redirectId}`, input),
    onSuccess: () => invalidate(queryClient),
  })
}

export function useDeleteSeoRedirect() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (redirectId: string) =>
      api.delete<{ deleted: boolean }>(`/platform/marketing/seo/redirects/${redirectId}`),
    onSuccess: () => invalidate(queryClient),
  })
}
