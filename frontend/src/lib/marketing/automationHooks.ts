import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api } from '../api/client'

export interface AutomationCatalogue {
  enabled: boolean
  kill_switch: { enabled: boolean; key: string; message: string }
  triggers: Array<{ value: string; label: string }>
  step_types: Array<{ value: string; label: string }>
  external_channels: string[]
  outbound_provider: { approved: boolean; message: string }
}

export interface AutomationStepView {
  id: number
  position: number
  type: string
  config: Record<string, unknown>
}

export interface AutomationWorkflowView {
  id: string
  name: string
  description: string | null
  trigger: string
  trigger_label: string
  status: string
  conditions: Record<string, unknown>
  steps: AutomationStepView[]
  creator: { id: string; name: string } | null
  activated_at: string | null
  paused_at: string | null
  stats: {
    runs: number
    completed: number
    blocked: number
    failed: number
    waiting: number
    last_run: { status: string; created_at: string | null; finished_at: string | null } | null
  }
  created_at: string | null
  updated_at: string | null
}

export interface AutomationRunStepView {
  position: number
  type: string
  status: string
  attempts: number
  last_error: string | null
  scheduled_for: string | null
  executed_at: string | null
  output: Record<string, unknown>
}

export interface AutomationRunView {
  id: string
  status: string
  trigger: string
  attempts: number
  last_error: string | null
  resume_at: string | null
  started_at: string | null
  finished_at: string | null
  created_at: string | null
  lead: { id: string; reference: string; contact_name: string } | null
  context: Record<string, unknown>
  steps: AutomationRunStepView[]
}

export interface AutomationStepInput {
  type: string
  config: Record<string, unknown>
}

export interface AutomationWorkflowInput {
  name: string
  description?: string
  trigger: string
  conditions?: { lead_type?: string[]; source?: string; utm_campaign?: string }
  steps: AutomationStepInput[]
}

const listKey = ['marketing', 'automation', 'workflows']

export function useAutomationWorkflows() {
  return useQuery({
    queryKey: listKey,
    queryFn: () => api.get<{ settings: AutomationCatalogue; workflows: AutomationWorkflowView[] }>('/platform/marketing/workflows'),
  })
}

export function useAutomationExecutions(workflowId: string | null) {
  return useQuery({
    queryKey: ['marketing', 'automation', 'executions', workflowId],
    queryFn: () => api.get<{ workflow: { id: string; name: string; status: string }; runs: AutomationRunView[] }>(
      `/platform/marketing/workflows/${workflowId}/executions`,
    ),
    enabled: workflowId !== null,
  })
}

export function useCreateAutomationWorkflow() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (input: AutomationWorkflowInput) =>
      api.post<{ workflow: AutomationWorkflowView }>('/platform/marketing/workflows', input),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: listKey }),
  })
}

export function useAutomationWorkflowAction() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ id, action }: { id: string; action: 'activate' | 'pause' }) =>
      api.post<{ workflow: AutomationWorkflowView }>(`/platform/marketing/workflows/${id}/${action}`, {}),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: listKey })
      queryClient.invalidateQueries({ queryKey: ['marketing', 'automation', 'executions'] })
    },
  })
}

export function useAutomationKillSwitch() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (enabled: boolean) =>
      api.post<{ enabled: boolean; settings: AutomationCatalogue }>('/platform/marketing/automation/kill-switch', { enabled }),
    onSuccess: () => queryClient.invalidateQueries({ queryKey: listKey }),
  })
}

export function useRunDueAutomation() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: () => api.post<{ processed: number; enabled: boolean }>('/platform/marketing/automation/run-due', {}),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: listKey })
      queryClient.invalidateQueries({ queryKey: ['marketing', 'automation', 'executions'] })
    },
  })
}
