import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  createConnection,
  deleteConnection,
  fetchConflicts,
  fetchConnections,
  fetchMapping,
  fetchRuns,
  fetchWebhookEvents,
  resolveConflict,
  runSync,
  saveMapping,
  testConnection,
  updateConnection,
  type ConnectionInput,
  type SyncInput,
} from '../api/integrations'

function baseKey(tenantId: string | null | undefined) {
  return ['tenants', tenantId, 'integrations'] as const
}

export function useIntegrations(tenantId: string | null | undefined) {
  return useQuery({
    queryKey: baseKey(tenantId),
    queryFn: () => fetchConnections(tenantId as string),
    enabled: Boolean(tenantId),
    retry: false,
  })
}

export function useCreateIntegration(tenantId: string | undefined) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (input: ConnectionInput) => createConnection(tenantId as string, input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: baseKey(tenantId) })
    },
  })
}

export function useUpdateIntegration(tenantId: string | undefined) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({
      connectionId,
      input,
    }: {
      connectionId: string
      input: Partial<ConnectionInput> & { status?: 'active' | 'paused' }
    }) => updateConnection(tenantId as string, connectionId, input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: baseKey(tenantId) })
    },
  })
}

export function useDeleteIntegration(tenantId: string | undefined) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (connectionId: string) => deleteConnection(tenantId as string, connectionId),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: baseKey(tenantId) })
    },
  })
}

export function useTestIntegration(tenantId: string | undefined) {
  return useMutation({
    mutationFn: (connectionId: string) => testConnection(tenantId as string, connectionId),
  })
}

export function useIntegrationMapping(
  tenantId: string | null | undefined,
  connectionId: string | undefined,
  entity: 'products' | 'movements',
) {
  return useQuery({
    queryKey: [...baseKey(tenantId), connectionId, 'mapping', entity],
    queryFn: () => fetchMapping(tenantId as string, connectionId as string, entity),
    enabled: Boolean(tenantId && connectionId),
    retry: false,
  })
}

export function useSaveIntegrationMapping(tenantId: string | undefined) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({
      connectionId,
      entity,
      fieldMap,
    }: {
      connectionId: string
      entity: 'products' | 'movements'
      fieldMap: Record<string, string>
    }) => saveMapping(tenantId as string, connectionId, entity, fieldMap),
    onSuccess: (_data, variables) => {
      void queryClient.invalidateQueries({
        queryKey: [...baseKey(tenantId), variables.connectionId, 'mapping', variables.entity],
      })
    },
  })
}

export function useRunIntegrationSync(tenantId: string | undefined) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ connectionId, input }: { connectionId: string; input: SyncInput }) =>
      runSync(tenantId as string, connectionId, input),
    onSuccess: (_data, variables) => {
      void queryClient.invalidateQueries({ queryKey: baseKey(tenantId) })
      void queryClient.invalidateQueries({
        queryKey: [...baseKey(tenantId), variables.connectionId],
      })
    },
  })
}

export function useIntegrationRuns(
  tenantId: string | null | undefined,
  connectionId: string | undefined,
  enabled = true,
) {
  return useQuery({
    queryKey: [...baseKey(tenantId), connectionId, 'runs'],
    queryFn: () => fetchRuns(tenantId as string, connectionId as string),
    enabled: Boolean(tenantId && connectionId && enabled),
    retry: false,
  })
}

export function useIntegrationConflicts(
  tenantId: string | null | undefined,
  connectionId: string | undefined,
  enabled = true,
) {
  return useQuery({
    queryKey: [...baseKey(tenantId), connectionId, 'conflicts'],
    queryFn: () => fetchConflicts(tenantId as string, connectionId as string),
    enabled: Boolean(tenantId && connectionId && enabled),
    retry: false,
  })
}

export function useResolveIntegrationConflict(tenantId: string | undefined) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({
      connectionId,
      conflictId,
      resolution,
    }: {
      connectionId: string
      conflictId: string
      resolution: 'accept_external' | 'keep_local' | 'ignored'
    }) => resolveConflict(tenantId as string, connectionId, conflictId, resolution),
    onSuccess: (_data, variables) => {
      void queryClient.invalidateQueries({
        queryKey: [...baseKey(tenantId), variables.connectionId],
      })
      void queryClient.invalidateQueries({ queryKey: baseKey(tenantId) })
    },
  })
}

export function useIntegrationWebhooks(
  tenantId: string | null | undefined,
  connectionId: string | undefined,
  enabled = true,
) {
  return useQuery({
    queryKey: [...baseKey(tenantId), connectionId, 'webhooks'],
    queryFn: () => fetchWebhookEvents(tenantId as string, connectionId as string),
    enabled: Boolean(tenantId && connectionId && enabled),
    retry: false,
  })
}
