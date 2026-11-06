import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, type Paginated } from '@/lib/api'
import type { Item, UnitRef } from '@/features/catalog/api'

export type RequisitionStatus = 'draft' | 'uploaded' | 'processing' | 'extracted' | 'failed' | 'verified' | 'approved' | 'cancelled'
export const EDITABLE: RequisitionStatus[] = ['draft', 'extracted', 'failed']
export const IN_PROGRESS: RequisitionStatus[] = ['uploaded', 'processing']

type Person = { id: number; name: string } | null

export type Fulfilment = { approved: string | null; on_order: string; purchased: string; pending: string | null; delivered: string; remaining_delivery: string }

export type RequisitionLine = {
  id: number
  line_no: number
  original_name: string
  original_unit: string | null
  description: string | null
  quantity: string | null
  unit: UnitRef | null
  item: { id: number; code: string; name: string; is_active: boolean; default_unit: UnitRef | null } | null
  match_type: 'exact' | 'alias' | 'manual' | null
  conversion_factor: string | null
  base_quantity: string | null
  approved_quantity: string | null
  requested_date: string | null
  department: { id: number; name: string } | null
  notes: string | null
  fulfilment?: Fulfilment | null
}

export type ProcessingLog = {
  id: number; attempt: number; status: 'started' | 'succeeded' | 'failed'; extractor: string | null; message: string | null
  context: { lines?: number; matched?: number; unmatched?: number; skipped?: number; skipped_lines?: string[] } | null
  started_at: string; finished_at: string | null
}

export type Requisition = {
  id: number
  reference_no: string
  external_reference: string | null
  title: string
  requisition_date: string | null
  status: RequisitionStatus
  notes: string | null
  department: { id: number; name: string } | null
  uploaded_at: string
  created_by: Person
  verified_by?: Person
  verified_at: string | null
  approved_by?: Person
  approved_at: string | null
  cancelled_by?: Person
  cancelled_at: string | null
  cancellation_reason: string | null
  file?: { original_name: string; size_bytes: number; mime_type: string } | null
  line_count?: number
  item_count?: number
  total_requested_quantity?: string | null
  lines?: RequisitionLine[]
  processing_logs?: ProcessingLog[]
  possible_duplicates?: { id: number; reference_no: string; title: string; status: RequisitionStatus }[]
}

export type LineInput = {
  id?: number
  original_name?: string
  original_unit?: string | null
  description?: string | null
  quantity?: string | null
  unit_id?: number | null
  item_id?: number | null
  requested_date?: string | null
  department_id?: number | null
  notes?: string | null
  save_alias?: boolean
}

export type RequisitionInput = {
  title?: string
  external_reference?: string | null
  requisition_date?: string | null
  department_id?: number | null
  notes?: string | null
  lines?: LineInput[]
}

export function useRequisitions(params: Record<string, string | number | undefined>) {
  return useQuery({
    queryKey: ['requisitions', 'list', params],
    queryFn: () => api.get<Paginated<Requisition>>('/requisitions', params),
    placeholderData: keepPreviousData,
  })
}

export function useRequisition(id: number) {
  return useQuery({
    queryKey: ['requisitions', id],
    queryFn: async () => (await api.get<{ data: Requisition }>(`/requisitions/${id}`)).data,
    // Poll while the background job is working on the document.
    refetchInterval: (query) => (query.state.data && IN_PROGRESS.includes(query.state.data.status) ? 2000 : false),
  })
}

function useInvalidate() {
  const qc = useQueryClient()
  return (r?: Requisition) => {
    if (r) qc.setQueryData(['requisitions', r.id], r)
    qc.invalidateQueries({ queryKey: ['requisitions', 'list'] })
    qc.invalidateQueries({ queryKey: ['analyzer'] })
    qc.invalidateQueries({ queryKey: ['dashboard'] })
  }
}

export function useSaveRequisition(id?: number) {
  const done = useInvalidate()
  return useMutation({
    mutationFn: async (body: RequisitionInput) =>
      (id ? await api.put<{ data: Requisition }>(`/requisitions/${id}`, body) : await api.post<{ data: Requisition }>('/requisitions', body)).data,
    onSuccess: done,
  })
}

export function useRequisitionAction(id: number) {
  const done = useInvalidate()
  const useAction = (name: string) =>
    useMutation({ mutationFn: async (body?: unknown) => (await api.post<{ data: Requisition }>(`/requisitions/${id}/${name}`, body)).data, onSuccess: done })
  return {
    verify: useAction('verify'),
    reopen: useAction('reopen'),
    approve: useAction('approve'),
    cancel: useAction('cancel'),
    process: useAction('process'),
  }
}

export type UploadResult = {
  created: Requisition[]
  duplicates: { file: string; requisition: { id: number; reference_no: string; status: RequisitionStatus } | null }[]
}

export function useUploadRequisitions() {
  const done = useInvalidate()
  return useMutation({
    mutationFn: async ({ files, ...defaults }: { files: File[]; department_id?: number | null; requisition_date?: string | null }) => {
      const body = new FormData()
      files.forEach((f) => body.append('files[]', f))
      Object.entries(defaults).forEach(([k, v]) => v !== null && v !== undefined && v !== '' && body.append(k, String(v)))
      return (await api.post<{ data: UploadResult }>('/requisitions/uploads', body)).data
    },
    onSuccess: () => done(),
  })
}

export function useMatchSuggestions(lineId: number | undefined, enabled: boolean) {
  return useQuery({
    queryKey: ['match-suggestions', lineId],
    queryFn: async () => (await api.get<{ data: { score: number; item: Item }[] }>(`/requisition-items/${lineId}/match-suggestions`)).data,
    enabled: enabled && !!lineId,
    staleTime: 60_000,
  })
}
