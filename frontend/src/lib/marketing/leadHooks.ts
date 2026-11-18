import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api } from '../api/client'

export type LeadType = 'quote' | 'pos_demo' | 'contact' | 'callback' | 'partnership' | 'other'
export type LeadStatus =
  | 'new'
  | 'assigned'
  | 'contacted'
  | 'qualified'
  | 'quote_sent'
  | 'follow_up'
  | 'won'
  | 'lost'
  | 'archived'

export const LEAD_TYPES: LeadType[] = ['quote', 'pos_demo', 'contact', 'callback', 'partnership', 'other']
export const LEAD_STATUSES: LeadStatus[] = [
  'new',
  'assigned',
  'contacted',
  'qualified',
  'quote_sent',
  'follow_up',
  'won',
  'lost',
  'archived',
]
export const LEAD_ACTIVITY_TYPES = ['note', 'call', 'email', 'sms', 'whatsapp'] as const

export const leadTypeLabels: Record<LeadType, string> = {
  quote: 'Quotation',
  pos_demo: 'POS demo',
  contact: 'Contact',
  callback: 'Callback',
  partnership: 'Partnership',
  other: 'Other',
}

export const leadStatusLabels: Record<LeadStatus, string> = {
  new: 'New',
  assigned: 'Assigned',
  contacted: 'Contacted',
  qualified: 'Qualified',
  quote_sent: 'Quote sent',
  follow_up: 'Follow up',
  won: 'Won',
  lost: 'Lost',
  archived: 'Archived',
}

export interface LeadSummary {
  id: string
  reference: string
  type: LeadType
  status: LeadStatus
  business_name: string | null
  contact_name: string
  email: string | null
  phone: string | null
  town: string | null
  county: string | null
  service_requested: string | null
  source: string
  utm_source: string | null
  utm_medium: string | null
  utm_campaign: string | null
  assigned_to: { id: string; name: string } | null
  last_activity_at: string | null
  created_at: string | null
  updated_at: string | null
}

export interface LeadActivity {
  id: number
  type: string
  body: string | null
  metadata: Record<string, unknown>
  author: { id: string; name: string } | null
  created_at: string | null
}

export interface LeadConsent {
  channel: string
  status: string
  source: string | null
  policy_version: string | null
  occurred_at: string | null
}

export interface LeadDetail extends LeadSummary {
  industry: string | null
  branches_count: number | null
  catalogue_size: number | null
  stocktake_frequency: string | null
  message: string | null
  utm_term: string | null
  utm_content: string | null
  landing_page: string | null
  referrer: string | null
  consent_marketing: boolean
  consent_transactional: boolean
  consent_at: string | null
  owner: { id: string; name: string } | null
  converted_at: string | null
  activities: LeadActivity[]
  assignments: {
    id: number
    assigned_to: { id: string; name: string } | null
    assigned_at: string | null
    unassigned_at: string | null
    reason: string | null
  }[]
  consents: LeadConsent[]
}

export interface LeadListResponse {
  leads: LeadSummary[]
  pagination: { page: number; per_page: number; total: number; last_page: number }
  status_counts: Record<string, number>
  type_counts: Record<string, number>
  pii_masked: boolean
}

export interface LeadFilters {
  search?: string
  status?: string
  type?: string
  from?: string
  to?: string
  per_page?: number
}

function leadQuery(filters: LeadFilters): string {
  const query = new URLSearchParams()
  if (filters.search) query.set('search', filters.search)
  if (filters.status) query.set('status', filters.status)
  if (filters.type) query.set('type', filters.type)
  if (filters.from) query.set('from', filters.from)
  if (filters.to) query.set('to', filters.to)
  if (filters.per_page) query.set('per_page', String(filters.per_page))
  return query.toString()
}

export function useLeads(filters: LeadFilters) {
  return useQuery({
    queryKey: ['marketing', 'leads', filters],
    queryFn: () => api.get<LeadListResponse>(`/platform/marketing/leads?${leadQuery(filters)}`),
    retry: false,
  })
}

export function useLead(leadId: string | undefined) {
  return useQuery({
    queryKey: ['marketing', 'leads', leadId],
    queryFn: () => api.get<{ lead: LeadDetail }>(`/platform/marketing/leads/${leadId}`),
    enabled: Boolean(leadId),
    retry: false,
  })
}

export function useLeadAssignees() {
  return useQuery({
    queryKey: ['marketing', 'leads', 'assignees'],
    queryFn: () => api.get<{ assignees: { id: string; name: string; email: string }[] }>('/platform/marketing/leads/assignees'),
    retry: false,
  })
}

function useInvalidateLead(leadId: string | undefined) {
  const queryClient = useQueryClient()
  return () => {
    queryClient.invalidateQueries({ queryKey: ['marketing', 'leads'] })
    if (leadId) queryClient.invalidateQueries({ queryKey: ['marketing', 'leads', leadId] })
  }
}

export function useAssignLead(leadId: string) {
  const invalidate = useInvalidateLead(leadId)
  return useMutation({
    mutationFn: (payload: { assigned_to: string; reason?: string }) =>
      api.post(`/platform/marketing/leads/${leadId}/assign`, payload),
    onSuccess: invalidate,
  })
}

export function useUpdateLeadStatus(leadId: string) {
  const invalidate = useInvalidateLead(leadId)
  return useMutation({
    mutationFn: (status: LeadStatus) => api.post(`/platform/marketing/leads/${leadId}/status`, { status }),
    onSuccess: invalidate,
  })
}

export function useAddLeadActivity(leadId: string) {
  const invalidate = useInvalidateLead(leadId)
  return useMutation({
    mutationFn: (payload: { type: string; body: string }) =>
      api.post(`/platform/marketing/leads/${leadId}/activities`, payload),
    onSuccess: invalidate,
  })
}

export function useRecordLeadConsent(leadId: string) {
  const invalidate = useInvalidateLead(leadId)
  return useMutation({
    mutationFn: (payload: { channel: string; status: 'granted' | 'withdrawn' }) =>
      api.post(`/platform/marketing/leads/${leadId}/consents`, payload),
    onSuccess: invalidate,
  })
}

export async function downloadLeadsCsv(filters: LeadFilters): Promise<void> {
  const blob = await api.download(`/platform/marketing/leads/export?${leadQuery(filters)}`)
  const url = URL.createObjectURL(blob)
  const anchor = document.createElement('a')
  anchor.href = url
  anchor.download = `leads-${new Date().toISOString().slice(0, 10)}.csv`
  document.body.appendChild(anchor)
  anchor.click()
  anchor.remove()
  URL.revokeObjectURL(url)
}
