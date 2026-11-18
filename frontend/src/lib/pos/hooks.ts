import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  closePosShift,
  createPosCustomer,
  createPosPurchase,
  createPosReturn,
  createPosSale,
  createPosSupplier,
  createPosTerminal,
  createPosTransfer,
  fetchPosCustomers,
  fetchPosPurchases,
  fetchPosReturns,
  fetchPosSale,
  fetchPosSales,
  fetchPosProducts,
  fetchPosShiftReconciliation,
  fetchPosShifts,
  fetchPosSuppliers,
  fetchPosTerminals,
  fetchPosTransfers,
  openPosShift,
  receivePosTransfer,
  reconcilePosShift,
  updatePosTerminal,
  type CloseShiftInput,
  type CreateCustomerInput,
  type CreatePurchaseInput,
  type CreateReturnInput,
  type CreateSaleInput,
  type CreateSupplierInput,
  type CreateTerminalInput,
  type CreateTransferInput,
  type OpenShiftInput,
  type PosProductSearch,
} from '../api/pos'

export function usePosTerminals(tenantId: string | null | undefined) {
  return useQuery({
    queryKey: ['tenants', tenantId, 'pos', 'terminals'],
    queryFn: () => fetchPosTerminals(tenantId as string),
    enabled: Boolean(tenantId),
    retry: false,
  })
}

export function useCreatePosTerminal(tenantId: string | undefined) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (input: CreateTerminalInput) =>
      createPosTerminal(tenantId as string, input),
    onSuccess: () => {
      void queryClient.invalidateQueries({
        queryKey: ['tenants', tenantId, 'pos', 'terminals'],
      })
    },
  })
}

export function useUpdatePosTerminal(tenantId: string | undefined) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({
      terminalId,
      input,
    }: {
      terminalId: string
      input: Partial<CreateTerminalInput>
    }) => updatePosTerminal(tenantId as string, terminalId, input),
    onSuccess: () => {
      void queryClient.invalidateQueries({
        queryKey: ['tenants', tenantId, 'pos', 'terminals'],
      })
    },
  })
}

export function usePosShifts(tenantId: string | null | undefined) {
  return useQuery({
    queryKey: ['tenants', tenantId, 'pos', 'shifts'],
    queryFn: () => fetchPosShifts(tenantId as string),
    enabled: Boolean(tenantId),
    retry: false,
  })
}

export function useOpenPosShift(
  tenantId: string | undefined,
  terminalId: string | undefined,
) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (input: OpenShiftInput) =>
      openPosShift(tenantId as string, terminalId as string, input),
    onSuccess: () => {
      void queryClient.invalidateQueries({
        queryKey: ['tenants', tenantId, 'pos', 'shifts'],
      })
      void queryClient.invalidateQueries({
        queryKey: ['tenants', tenantId, 'pos', 'terminals'],
      })
    },
  })
}

export function useClosePosShift(tenantId: string | undefined) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: ({ shiftId, input }: { shiftId: string; input: CloseShiftInput }) =>
      closePosShift(tenantId as string, shiftId, input),
    onSuccess: () => {
      void queryClient.invalidateQueries({
        queryKey: ['tenants', tenantId, 'pos', 'shifts'],
      })
      void queryClient.invalidateQueries({
        queryKey: ['tenants', tenantId, 'pos', 'terminals'],
      })
    },
  })
}

export function useReconcilePosShift(tenantId: string | undefined) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (shiftId: string) => reconcilePosShift(tenantId as string, shiftId),
    onSuccess: () => {
      void queryClient.invalidateQueries({
        queryKey: ['tenants', tenantId, 'pos', 'shifts'],
      })
    },
  })
}

export function usePosShiftReconciliation(
  tenantId: string | null | undefined,
  shiftId: string | null | undefined,
) {
  return useQuery({
    queryKey: ['tenants', tenantId, 'pos', 'shifts', shiftId, 'reconciliation'],
    queryFn: () =>
      fetchPosShiftReconciliation(tenantId as string, shiftId as string),
    enabled: Boolean(tenantId && shiftId),
    retry: false,
  })
}

export function usePosSales(tenantId: string | null | undefined, filters: {
  status?: string
  terminal_id?: string
  from?: string
  to?: string
} = {}) {
  return useQuery({
    queryKey: ['tenants', tenantId, 'pos', 'sales', filters],
    queryFn: () => fetchPosSales(tenantId as string, filters),
    enabled: Boolean(tenantId),
    retry: false,
  })
}

export function usePosProducts(tenantId: string | null | undefined, filters: {
  terminalId: string
  search?: string
  brand?: string
  category?: string
  page?: number
  perPage?: number
}) {
  return useQuery({
    queryKey: ['tenants', tenantId, 'pos', 'products', filters],
    queryFn: (): Promise<PosProductSearch> => fetchPosProducts(tenantId as string, filters),
    enabled: Boolean(tenantId && filters.terminalId),
    retry: false,
  })
}

export function useCreatePosSale(tenantId: string | undefined) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (input: CreateSaleInput) =>
      createPosSale(tenantId as string, input),
    onSuccess: () => {
      void queryClient.invalidateQueries({
        queryKey: ['tenants', tenantId, 'pos', 'sales'],
      })
      void queryClient.invalidateQueries({
        queryKey: ['tenants', tenantId, 'pos', 'shifts'],
      })
    },
  })
}

export function usePosSale(tenantId: string | null | undefined, saleId: string | null | undefined) {
  return useQuery({
    queryKey: ['tenants', tenantId, 'pos', 'sales', saleId],
    queryFn: () => fetchPosSale(tenantId as string, saleId as string),
    enabled: Boolean(tenantId && saleId),
    retry: false,
  })
}

export function usePosSuppliers(tenantId: string | null | undefined) {
  return useQuery({
    queryKey: ['tenants', tenantId, 'pos', 'suppliers'],
    queryFn: () => fetchPosSuppliers(tenantId as string),
    enabled: Boolean(tenantId),
    retry: false,
  })
}

export function useCreatePosSupplier(tenantId: string | undefined) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (input: CreateSupplierInput) =>
      createPosSupplier(tenantId as string, input),
    onSuccess: () => {
      void queryClient.invalidateQueries({
        queryKey: ['tenants', tenantId, 'pos', 'suppliers'],
      })
    },
  })
}

export function usePosPurchases(tenantId: string | null | undefined) {
  return useQuery({
    queryKey: ['tenants', tenantId, 'pos', 'purchases'],
    queryFn: () => fetchPosPurchases(tenantId as string),
    enabled: Boolean(tenantId),
    retry: false,
  })
}

export function useCreatePosPurchase(tenantId: string | undefined) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (input: CreatePurchaseInput) =>
      createPosPurchase(tenantId as string, input),
    onSuccess: () => {
      void queryClient.invalidateQueries({
        queryKey: ['tenants', tenantId, 'pos', 'purchases'],
      })
    },
  })
}
export function usePosCustomers(tenantId: string | null | undefined, search?: string) {
  return useQuery({
    queryKey: ['tenants', tenantId, 'pos', 'customers', search ?? ''],
    queryFn: () => fetchPosCustomers(tenantId as string, search),
    enabled: Boolean(tenantId),
    retry: false,
  })
}

export function useCreatePosCustomer(tenantId: string | undefined) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (input: CreateCustomerInput) => createPosCustomer(tenantId as string, input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['tenants', tenantId, 'pos', 'customers'] })
    },
  })
}

export function usePosReturns(tenantId: string | null | undefined) {
  return useQuery({
    queryKey: ['tenants', tenantId, 'pos', 'returns'],
    queryFn: () => fetchPosReturns(tenantId as string),
    enabled: Boolean(tenantId),
    retry: false,
  })
}

export function useCreatePosReturn(tenantId: string | undefined) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (input: CreateReturnInput) => createPosReturn(tenantId as string, input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['tenants', tenantId, 'pos', 'returns'] })
      void queryClient.invalidateQueries({ queryKey: ['tenants', tenantId, 'pos', 'sales'] })
      void queryClient.invalidateQueries({ queryKey: ['tenants', tenantId, 'pos', 'shifts'] })
    },
  })
}

export function usePosTransfers(tenantId: string | null | undefined) {
  return useQuery({
    queryKey: ['tenants', tenantId, 'pos', 'transfers'],
    queryFn: () => fetchPosTransfers(tenantId as string),
    enabled: Boolean(tenantId),
    retry: false,
  })
}

export function useCreatePosTransfer(tenantId: string | undefined) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (input: CreateTransferInput) => createPosTransfer(tenantId as string, input),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['tenants', tenantId, 'pos', 'transfers'] })
      void queryClient.invalidateQueries({ queryKey: ['tenants', tenantId, 'inventory'] })
    },
  })
}

export function useReceivePosTransfer(tenantId: string | undefined) {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (transferId: string) => receivePosTransfer(tenantId as string, transferId),
    onSuccess: () => {
      void queryClient.invalidateQueries({ queryKey: ['tenants', tenantId, 'pos', 'transfers'] })
      void queryClient.invalidateQueries({ queryKey: ['tenants', tenantId, 'inventory'] })
    },
  })
}
