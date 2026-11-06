import { useQuery } from '@tanstack/react-query'
import { api } from '@/lib/api'

export type SystemSettings = {
  organization_name: string
  currency_code: string
  currency_symbol: string
  week_starts_on: 'saturday' | 'sunday' | 'monday'
  require_po_approval: boolean
  max_upload_mb: number
  ocr_enabled: boolean
  business_timezone: string
}

export function useSettings() {
  return useQuery({
    queryKey: ['settings'],
    queryFn: async () => (await api.get<{ data: SystemSettings }>('/settings')).data,
    staleTime: 10 * 60_000,
  })
}
