import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, getStoredToken } from '../api/client'

export const STRUCTURED_DATA_TYPES = [
  'faq',
  'service',
  'software_application',
  'article',
  'local_business',
] as const

export type StructuredDataType = (typeof STRUCTURED_DATA_TYPES)[number]

export interface StructuredDataIssue {
  type: string
  field: string
  message: string
}

export interface StructuredDataValidation {
  status: 'valid' | 'invalid'
  types: string[]
  issues: StructuredDataIssue[]
}

export interface StructuredDataRun {
  id: string
  status: string
  types: string[]
  issues: StructuredDataIssue[]
  validated_by: number | null
  created_at: string | null
}

export interface StructuredDataSettings {
  types: string[]
  overrides: {
    service_type?: string
    application_category?: string
  }
}

export interface StructuredDataPayload {
  page: {
    id: string
    slug: string
    title: string
    status: string
    type: string
  }
  settings: StructuredDataSettings
  types: string[]
  structured_data: {
    '@context': string
    '@graph': Record<string, unknown>[]
  }
  validation: StructuredDataValidation
  runs: StructuredDataRun[]
}

export interface StructuredDataSettingsInput {
  types: string[]
  overrides: { service_type?: string; application_category?: string }
}

function invalidate(queryClient: ReturnType<typeof useQueryClient>, pageId: string) {
  void queryClient.invalidateQueries({ queryKey: ['marketing', 'structured-data', pageId] })
}

export function usePageStructuredData(pageId: string) {
  return useQuery({
    queryKey: ['marketing', 'structured-data', pageId],
    queryFn: () => api.get<StructuredDataPayload>(`/platform/marketing/pages/${pageId}/structured-data`),
    enabled: getStoredToken() !== null && pageId !== '',
    retry: false,
  })
}

export function useUpdatePageStructuredData(pageId: string) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (input: StructuredDataSettingsInput) =>
      api.put<StructuredDataPayload>(`/platform/marketing/pages/${pageId}/structured-data`, input),
    onSuccess: () => invalidate(queryClient, pageId),
  })
}

export function useValidatePageStructuredData(pageId: string) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: () =>
      api.post<StructuredDataPayload>(`/platform/marketing/pages/${pageId}/structured-data/validate`),
    onSuccess: () => invalidate(queryClient, pageId),
  })
}
