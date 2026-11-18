import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  createFinanceReconciliation,
  createFinanceRefund,
  decideFinanceRefund,
  fetchFinanceLedger,
  fetchFinanceOverview,
  fetchFinancePayments,
  fetchFinanceReconciliation,
  fetchFinanceReconciliations,
  fetchFinanceRefunds,
  fetchFinanceOptions,
  verifyFinancePayment,
  type FinanceLedgerFilters,
  type FinancePaymentFilters,
  type ReconciliationInput,
  type RefundInput,
} from '../api/finance'

function useFinanceInvalidator() {
  const queryClient = useQueryClient()
  return () => {
    void queryClient.invalidateQueries({ queryKey: ['platform', 'finance'] })
    void queryClient.invalidateQueries({ queryKey: ['platform', 'stocktake-jobs'] })
  }
}

export function useFinanceOverview() {
  return useQuery({
    queryKey: ['platform', 'finance', 'overview'],
    queryFn: fetchFinanceOverview,
    retry: false,
  })
}

export function useFinancePayments(filters: FinancePaymentFilters = {}) {
  return useQuery({
    queryKey: ['platform', 'finance', 'payments', filters],
    queryFn: () => fetchFinancePayments(filters),
    retry: false,
  })
}

export function useFinanceLedger(filters: FinanceLedgerFilters = {}) {
  return useQuery({
    queryKey: ['platform', 'finance', 'ledger', filters],
    queryFn: () => fetchFinanceLedger(filters),
    retry: false,
  })
}

export function useFinanceReconciliations() {
  return useQuery({
    queryKey: ['platform', 'finance', 'reconciliations'],
    queryFn: fetchFinanceReconciliations,
    retry: false,
  })
}

export function useFinanceReconciliation(id: string | null) {
  return useQuery({
    queryKey: ['platform', 'finance', 'reconciliations', id],
    queryFn: () => fetchFinanceReconciliation(id as string),
    enabled: Boolean(id),
    retry: false,
  })
}

export function useFinanceRefunds(filters: { status?: string; page?: number } = {}) {
  return useQuery({
    queryKey: ['platform', 'finance', 'refunds', filters],
    queryFn: () => fetchFinanceRefunds(filters),
    retry: false,
  })
}

export function useFinanceOptions() {
  return useQuery({
    queryKey: ['platform', 'finance', 'options'],
    queryFn: fetchFinanceOptions,
    retry: false,
  })
}

export function useVerifyFinancePayment() {
  const invalidate = useFinanceInvalidator()
  return useMutation({
    mutationFn: (input: { paymentId: string; decision: 'confirm' | 'fail'; reference?: string; reason?: string }) =>
      verifyFinancePayment(input.paymentId, input.decision, input.reference, input.reason),
    onSuccess: invalidate,
  })
}

export function useCreateFinanceReconciliation() {
  const invalidate = useFinanceInvalidator()
  return useMutation({
    mutationFn: (input: ReconciliationInput) => createFinanceReconciliation(input),
    onSuccess: invalidate,
  })
}

export function useCreateFinanceRefund() {
  const invalidate = useFinanceInvalidator()
  return useMutation({
    mutationFn: (input: RefundInput) => createFinanceRefund(input),
    onSuccess: invalidate,
  })
}

export function useDecideFinanceRefund() {
  const invalidate = useFinanceInvalidator()
  return useMutation({
    mutationFn: (input: {
      refundId: string
      decision: 'approve' | 'process' | 'fail' | 'cancel'
      reference?: string
      reason?: string
    }) => decideFinanceRefund(input.refundId, input.decision, input.reference, input.reason),
    onSuccess: invalidate,
  })
}
