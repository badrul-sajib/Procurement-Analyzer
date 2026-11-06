import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useIdempotencyKey } from '@/hooks/use-idempotency-key'
import { api, type Paginated } from '@/lib/api'

export type Delivery = {
  id: number
  delivery_number: string
  purchase_order: { id: number; po_number: string; vendor: { id: number; name: string } | null }
  delivery_date: string
  delivery_reference: string | null
  status: 'pending' | 'confirmed' | 'reversed'
  notes: string | null
  total_quantity?: string
  received_by?: { id: number; name: string } | null
  created_by?: { id: number; name: string } | null
  created_at: string
  confirmed_at: string | null
  reversed_at: string | null
  reversal_reason: string | null
  lines?: { id: number; purchase_order_item_id: number; item: { id: number; name: string; unit: string } | null; purchased_quantity: string | null; quantity: string; notes: string | null }[]
}

export type DeliverableLine = { purchase_order_item_id: number; item: { id: number; name: string; unit: string }; purchased_quantity: string; remaining: string }

export function useDeliveries(params: Record<string, string | number | undefined>) {
  return useQuery({ queryKey: ['deliveries', 'list', params], queryFn: () => api.get<Paginated<Delivery>>('/deliveries', params), placeholderData: keepPreviousData })
}

export function useDelivery(id: number) {
  return useQuery({ queryKey: ['deliveries', id], queryFn: async () => (await api.get<{ data: Delivery }>(`/deliveries/${id}`)).data })
}

export function useDeliverable(poId: number, enabled: boolean) {
  return useQuery({ queryKey: ['deliveries', 'deliverable', poId], queryFn: async () => (await api.get<{ data: DeliverableLine[] }>(`/purchase-orders/${poId}/deliverable`)).data, enabled })
}

function useDone() {
  const qc = useQueryClient()
  return () => ['deliveries', 'purchase-orders', 'analyzer', 'dashboard', 'requisitions'].forEach((k) => qc.invalidateQueries({ queryKey: [k] }))
}

export function useCreateDelivery() {
  const done = useDone()
  const idem = useIdempotencyKey()
  return useMutation({
    mutationFn: async (body: { purchase_order_id: number; delivery_date: string; delivery_reference?: string | null; notes?: string | null; confirm: boolean; lines: { purchase_order_item_id: number; quantity: string }[] }) =>
      (await api.post<{ data: Delivery }>('/deliveries', body, idem.headers())).data,
    onSuccess: done,
    onSettled: (_d, e) => idem.settle(e),
  })
}

export function useDeliveryAction(id: number) {
  const done = useDone()
  const idem = useIdempotencyKey()
  const useAction = (name: string) => useMutation({
    mutationFn: async (body?: unknown) => (await api.post<{ data: Delivery }>(`/deliveries/${id}/${name}`, body, idem.headers())).data,
    onSuccess: done,
    onSettled: (_d, e) => idem.settle(e),
  })
  return { confirm: useAction('confirm'), reverse: useAction('reverse') }
}
