import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useIdempotencyKey } from '@/hooks/use-idempotency-key'
import { api, type Paginated } from '@/lib/api'

export const METHODS = [
  ['cash', 'Cash'], ['bank_transfer', 'Bank transfer'], ['mobile_financial_service', 'Mobile financial service'], ['cheque', 'Cheque'], ['other', 'Other'],
] as const
export type Method = (typeof METHODS)[number][0]

export type Payment = {
  id: number
  payment_reference: string
  vendor: { id: number; code: string; name: string }
  payment_date: string
  amount: string
  allocated_amount: string
  unallocated_amount: string
  method: Method
  transaction_reference: string | null
  notes: string | null
  status: 'active' | 'reversed'
  created_by?: { id: number; name: string }
  created_at: string
  reversed_at: string | null
  reversal_reason: string | null
  allocations?: { id: number; amount: string; created_at: string; purchase_order: { id: number; po_number: string; purchase_date: string } }[]
}

export type Payable = { id: number; po_number: string; purchase_date: string; grand_total: string; paid: string; due: string }
export type Allocation = { purchase_order_id: number; amount: string }

export type VendorBalance = {
  total_purchase_amount: string
  total_paid_amount: string
  allocated_amount: string
  outstanding_amount: string
  unallocated_credit: string
  purchase_order_count: number
  last_purchase_date: string | null
  payment_status: 'unpaid' | 'partially_paid' | 'paid' | null
}

export function usePayments(params: Record<string, string | number | undefined>) {
  return useQuery({ queryKey: ['payments', 'list', params], queryFn: () => api.get<Paginated<Payment>>('/payments', params), placeholderData: keepPreviousData })
}

export function usePayment(id: number) {
  return useQuery({ queryKey: ['payments', id], queryFn: async () => (await api.get<{ data: Payment }>(`/payments/${id}`)).data })
}

export function usePayables(vendorId: number | undefined) {
  return useQuery({ queryKey: ['vendors', vendorId, 'payables'], queryFn: async () => (await api.get<{ data: Payable[] }>(`/vendors/${vendorId}/payables`)).data, enabled: !!vendorId })
}

export function useVendorBalance(vendorId: number, enabled = true) {
  return useQuery({ queryKey: ['vendors', vendorId, 'balance'], queryFn: async () => (await api.get<{ data: VendorBalance }>(`/vendors/${vendorId}/balance`)).data, enabled })
}

function useDone() {
  const qc = useQueryClient()
  return () => ['payments', 'purchase-orders', 'vendors', 'dashboard'].forEach((k) => qc.invalidateQueries({ queryKey: [k] }))
}

export function useRecordPayment() {
  const done = useDone()
  const idem = useIdempotencyKey()
  return useMutation({
    mutationFn: async (body: { vendor_id: number; payment_date: string; amount: string; method: Method; transaction_reference?: string | null; notes?: string | null; allocations?: Allocation[] }) =>
      (await api.post<{ data: Payment }>('/payments', body, idem.headers())).data,
    onSuccess: done,
    onSettled: (_d, e) => idem.settle(e),
  })
}

export function usePaymentAction(id: number) {
  const done = useDone()
  const idem = useIdempotencyKey()
  const useAction = (path: string) => useMutation({
    mutationFn: async (body: unknown) => (await api.post<{ data: Payment }>(`/payments/${id}/${path}`, body, idem.headers())).data,
    onSuccess: done,
    onSettled: (_d, e) => idem.settle(e),
  })
  return { allocate: useAction('allocations'), reverse: useAction('reverse') }
}
