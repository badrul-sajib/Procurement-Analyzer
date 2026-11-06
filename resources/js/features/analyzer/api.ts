import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { api, type Paginated } from '@/lib/api'

export type AnalyzerSummary = {
  total_requisitions: number
  unique_items: number
  requisition_lines: number
  requested_quantity: string
  approved_quantity: string
  on_order_quantity: string
  purchased_quantity: string
  pending_quantity: string
  delivered_quantity: string
  remaining_delivery_quantity: string
}

export type AnalyzerRow = {
  item: { id: number; code: string; name: string; unit: string; category: string | null }
  requisition_count: number
  line_count: number
  requested: string
  approved: string
  on_order: string
  purchased: string
  pending: string
  delivered: string
  remaining_delivery: string
  purchase_status: 'not_approved' | 'not_purchased' | 'partially_purchased' | 'purchased'
  delivery_status: 'not_delivered' | 'partially_delivered' | 'fully_delivered'
}

export type SourceLine = {
  line_id: number
  requisition: { id: number; reference_no: string; title: string; requisition_date: string; status: string }
  original_name: string
  quantity: string
  unit: string | null
  department: string | null
  requested: string
  approved: string | null
  on_order: string
  purchased: string
  pending: string | null
  delivered: string
  remaining_delivery: string
}

type Params = Record<string, string | number | undefined>

export function useAnalyzerSummary(params: Params) {
  return useQuery({ queryKey: ['analyzer', 'summary', params], queryFn: async () => (await api.get<{ data: AnalyzerSummary }>('/analyzer/summary', params)).data })
}

export function useAnalyzerItems(params: Params) {
  return useQuery({ queryKey: ['analyzer', 'items', params], queryFn: () => api.get<Paginated<AnalyzerRow>>('/analyzer/items', params), placeholderData: keepPreviousData })
}

export function useAnalyzerSources(itemId: number, params: Params) {
  return useQuery({
    queryKey: ['analyzer', 'sources', itemId, params],
    queryFn: async () => (await api.get<{ data: SourceLine[] }>(`/analyzer/items/${itemId}/requisitions`, params)).data,
  })
}
