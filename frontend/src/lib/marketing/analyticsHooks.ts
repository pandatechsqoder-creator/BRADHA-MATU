import { useQuery } from '@tanstack/react-query'
import { api } from '../api/client'

export interface AnalyticsOverview {
  period_days: number
  sessions: number
  page_views: number
  events: number
  conversions: number
  lead_conversions: number
  conversion_rate: number | null
  consent: {
    decisions: number
    analytics_granted: number
    necessary_only: number
    grant_rate: number | null
    policy_version: string
    unconsented_excluded: boolean
  }
  daily: Array<{ date: string; events: number; conversions: number }>
  top_events: Array<{ event: string; count: number }>
}

export interface AnalyticsFunnel {
  period_days: number
  stages: Array<{ status: string; count: number }>
  leads: number
  conversion_events: Array<{ event: string; count: number }>
  converted_leads: number
  reconciled: boolean
  reconciliation_note: string
}

export interface AnalyticsCampaignRow {
  id: string
  name: string
  status: string
  channel: string
  budget_amount: number
  budget_currency: string
  utm_campaign: string
  analytics_events: number
  analytics_sessions: number
  analytics_conversions: number
  recorded_results: {
    reach: number
    impressions: number
    clicks: number
    leads: number
    conversions: number
    spend: number | null
  }
  cost_per_lead: number | null
}

export interface AnalyticsLandingPage {
  path: string
  views: number
  sessions: number
  conversions: number
}

export interface AnalyticsSearch {
  available: boolean
  reason?: string
  message?: string
  clicks: number
  impressions: number
  ctr: number | null
  position: number | null
  top_queries: Array<{ query: string; clicks: number; impressions: number }>
  top_pages: Array<{ page: string; clicks: number; impressions: number }>
}

export interface AnalyticsEventRow {
  event: string
  occurred_at: string | null
  path: string | null
  device: string | null
  utm_campaign: string | null
}

export function useAnalyticsOverview(days: number) {
  return useQuery({
    queryKey: ['marketing', 'analytics', 'overview', days],
    queryFn: () => api.get<AnalyticsOverview>(`/platform/marketing/analytics/overview?days=${days}`),
  })
}

export function useAnalyticsFunnel(days: number) {
  return useQuery({
    queryKey: ['marketing', 'analytics', 'funnel', days],
    queryFn: () => api.get<AnalyticsFunnel>(`/platform/marketing/analytics/funnel?days=${days}`),
  })
}

export function useAnalyticsCampaigns(days: number) {
  return useQuery({
    queryKey: ['marketing', 'analytics', 'campaigns', days],
    queryFn: () => api.get<{ campaigns: AnalyticsCampaignRow[] }>(`/platform/marketing/analytics/campaigns?days=${days}`),
  })
}

export function useAnalyticsLandingPages(days: number) {
  return useQuery({
    queryKey: ['marketing', 'analytics', 'landing-pages', days],
    queryFn: () => api.get<{ pages: AnalyticsLandingPage[] }>(`/platform/marketing/analytics/landing-pages?days=${days}`),
  })
}

export function useAnalyticsSearch(days: number) {
  return useQuery({
    queryKey: ['marketing', 'analytics', 'search', days],
    queryFn: () => api.get<AnalyticsSearch>(`/platform/marketing/analytics/search?days=${days}`),
  })
}

export function useAnalyticsEvents(days: number) {
  return useQuery({
    queryKey: ['marketing', 'analytics', 'events', days],
    queryFn: () => api.get<{ events: AnalyticsEventRow[] }>(`/platform/marketing/analytics/events?days=${days}`),
  })
}
