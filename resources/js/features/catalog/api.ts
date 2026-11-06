import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, type Paginated } from '@/lib/api'

export type Lookup = { id: number; code: string; name: string; is_active: boolean; synonyms?: string[] }
export type LookupKind = 'departments' | 'units' | 'item-categories'
export type UnitRef = { id: number; code: string; name: string }

export type Conversion = {
  id: number
  item_id: number | null
  item?: { id: number; name: string } | null
  from_unit: UnitRef
  to_unit: UnitRef
  factor: string
}

export type Item = {
  id: number
  code: string
  name: string
  sku: string | null
  description: string | null
  is_active: boolean
  category: { id: number; name: string } | null
  default_unit: UnitRef
  aliases?: { id: number; alias: string }[]
  conversions?: Conversion[]
  created_at: string
  updated_at: string
}

export type Vendor = {
  id: number
  code: string
  name: string
  contact_person: string | null
  phone: string | null
  email: string | null
  address: string | null
  company_info: string | null
  tax_number: string | null
  is_active: boolean
  notes: string | null
  created_at: string
}

type ListParams = Record<string, string | number | boolean | undefined>

// --- lookups ---------------------------------------------------------------------------------
export function useLookups(kind: LookupKind, params: ListParams = {}) {
  return useQuery({
    queryKey: [kind, 'all', params],
    queryFn: async () => (await api.get<{ data: Lookup[] }>(`/${kind}`, { all: 1, ...params })).data,
    staleTime: 5 * 60_000,
  })
}

export function useSaveLookup(kind: LookupKind) {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: ({ id, ...body }: Partial<Lookup>) =>
      id ? api.put<{ data: Lookup }>(`/${kind}/${id}`, body) : api.post<{ data: Lookup }>(`/${kind}`, body),
    onSuccess: () => qc.invalidateQueries({ queryKey: [kind] }),
  })
}

// --- conversions -----------------------------------------------------------------------------
export function useConversions(params: ListParams = {}) {
  return useQuery({
    queryKey: ['unit-conversions', params],
    queryFn: async () => (await api.get<{ data: Conversion[] }>('/unit-conversions', params)).data,
  })
}

export function useConversionMutations() {
  const qc = useQueryClient()
  const done = () => {
    qc.invalidateQueries({ queryKey: ['unit-conversions'] })
    qc.invalidateQueries({ queryKey: ['items'] })
  }
  return {
    create: useMutation({ mutationFn: (body: { item_id?: number | null; from_unit_id: number; to_unit_id: number; factor: string }) => api.post('/unit-conversions', body), onSuccess: done }),
    update: useMutation({ mutationFn: ({ id, factor }: { id: number; factor: string }) => api.put(`/unit-conversions/${id}`, { factor }), onSuccess: done }),
    remove: useMutation({ mutationFn: (id: number) => api.delete(`/unit-conversions/${id}`), onSuccess: done }),
  }
}

// --- items -----------------------------------------------------------------------------------
export function useItems(params: ListParams) {
  return useQuery({
    queryKey: ['items', 'list', params],
    queryFn: () => api.get<Paginated<Item>>('/items', params),
    placeholderData: keepPreviousData,
  })
}

export function useItem(id: number) {
  return useQuery({ queryKey: ['items', id], queryFn: async () => (await api.get<{ data: Item }>(`/items/${id}`)).data })
}

export type ItemInput = {
  name: string
  code?: string | null
  sku?: string | null
  category_id?: number | null
  default_unit_id: number
  description?: string | null
  is_active?: boolean
  aliases?: string[]
}

export function useSaveItem() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: ({ id, ...body }: ItemInput & { id?: number }) =>
      id ? api.put<{ data: Item }>(`/items/${id}`, body) : api.post<{ data: Item }>('/items', body),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['items'] }),
  })
}

export function useAliasMutations(itemId: number) {
  const qc = useQueryClient()
  const done = () => qc.invalidateQueries({ queryKey: ['items'] })
  return {
    add: useMutation({ mutationFn: (alias: string) => api.post(`/items/${itemId}/aliases`, { alias }), onSuccess: done }),
    remove: useMutation({ mutationFn: (aliasId: number) => api.delete(`/items/${itemId}/aliases/${aliasId}`), onSuccess: done }),
  }
}

// --- vendors ---------------------------------------------------------------------------------
export function useVendors(params: ListParams) {
  return useQuery({
    queryKey: ['vendors', 'list', params],
    queryFn: () => api.get<Paginated<Vendor>>('/vendors', params),
    placeholderData: keepPreviousData,
  })
}

export function useVendor(id: number) {
  return useQuery({ queryKey: ['vendors', id], queryFn: async () => (await api.get<{ data: Vendor }>(`/vendors/${id}`)).data })
}

export type VendorInput = Partial<Omit<Vendor, 'id' | 'created_at'>> & { name: string }

export function useSaveVendor() {
  const qc = useQueryClient()
  return useMutation({
    mutationFn: ({ id, ...body }: VendorInput & { id?: number }) =>
      id ? api.put<{ data: Vendor }>(`/vendors/${id}`, body) : api.post<{ data: Vendor }>('/vendors', body),
    onSuccess: () => qc.invalidateQueries({ queryKey: ['vendors'] }),
  })
}
