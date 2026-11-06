import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useIdempotencyKey } from '@/hooks/use-idempotency-key'
import { api, type Paginated } from '@/lib/api'

export type PoStatus = 'draft' | 'pending_approval' | 'approved' | 'purchased' | 'partially_purchased' | 'completed' | 'cancelled'
export const PURCHASED: PoStatus[] = ['purchased', 'partially_purchased', 'completed']

export type PoLine = {
  id: number
  item: { id: number; code: string; name: string; unit: string }
  ordered_quantity: string
  purchased_quantity: string | null
  unit_price: string
  discount_amount: string
  tax_amount: string
  line_total: string
  delivered_quantity: string
  remaining_delivery: string
  notes: string | null
  requisitions: { requisition_item_id: number; requisition: { id: number; reference_no: string; requisition_date: string }; ordered_quantity: string; purchased_quantity: string | null }[]
}

export type PurchaseOrder = {
  id: number
  po_number: string
  vendor: { id: number; code: string; name: string }
  order_date: string
  purchase_date: string | null
  status: PoStatus
  notes: string | null
  additional_charges: string
  subtotal: string
  grand_total: string
  ordered_quantity: string
  purchased_quantity: string
  delivered_quantity: string
  delivery_status: 'not_delivered' | 'partially_delivered' | 'fully_delivered'
  payable_amount?: string
  paid_amount?: string
  due_amount?: string
  payment_status?: 'unpaid' | 'partially_paid' | 'paid' | null
  created_by?: { id: number; name: string }
  created_at: string
  events?: { event: string; at: string; by: { id: number; name: string } | null }[]
  cancellation_reason: string | null
  lines?: PoLine[]
}

export type PoLineInput = { item_id: number; quantity: string; unit_price: string; discount_amount?: string; tax_amount?: string; notes?: string | null; requisition_item_ids?: number[] | null }
export type PoInput = { vendor_id: number; order_date: string; additional_charges?: string; notes?: string | null; lines: PoLineInput[] }

export type AvailableItem = { item: { id: number; code: string; name: string; unit: string; category: string | null }; pending: string; approved: string; on_order: string; purchased: string }

export function usePurchaseOrders(params: Record<string, string | number | undefined>) {
  return useQuery({ queryKey: ['purchase-orders', 'list', params], queryFn: () => api.get<Paginated<PurchaseOrder>>('/purchase-orders', params), placeholderData: keepPreviousData })
}

export function usePurchaseOrder(id: number) {
  return useQuery({ queryKey: ['purchase-orders', id], queryFn: async () => (await api.get<{ data: PurchaseOrder }>(`/purchase-orders/${id}`)).data, enabled: !!id })
}

export function useAvailableItems(ids?: number[]) {
  return useQuery({
    queryKey: ['purchase-orders', 'available', ids],
    queryFn: async () => (await api.get<Paginated<AvailableItem>>('/purchase-orders/available-items', { per_page: 100 })).data.filter((r) => !ids || ids.includes(r.item.id)),
  })
}

function useDone() {
  const qc = useQueryClient()
  return (po?: PurchaseOrder) => {
    if (po) qc.setQueryData(['purchase-orders', po.id], po)
    for (const key of ['purchase-orders', 'analyzer', 'dashboard', 'requisitions', 'vendors']) qc.invalidateQueries({ queryKey: [key] })
  }
}

export function useSavePurchaseOrder(id?: number) {
  const done = useDone()
  const idem = useIdempotencyKey()
  return useMutation({
    mutationFn: async (body: PoInput) =>
      (id ? await api.put<{ data: PurchaseOrder }>(`/purchase-orders/${id}`, body)
        : await api.post<{ data: PurchaseOrder }>('/purchase-orders', body, idem.headers())).data,
    onSuccess: done,
    onSettled: (_d, error) => idem.settle(error),
  })
}

export function usePurchaseOrderAction(id: number) {
  const done = useDone()
  const idem = useIdempotencyKey()
  const useAction = (name: string, idempotent = false) =>
    useMutation({
      mutationFn: async (body?: unknown) => (await api.post<{ data: PurchaseOrder }>(`/purchase-orders/${id}/${name}`, body, idempotent ? idem.headers() : undefined)).data,
      onSuccess: done,
      onSettled: (_d, error) => idempotent && idem.settle(error),
    })
  return {
    submit: useAction('submit'),
    approve: useAction('approve'),
    markPurchased: useAction('mark-purchased', true),
    complete: useAction('complete'),
    cancel: useAction('cancel'),
  }
}
