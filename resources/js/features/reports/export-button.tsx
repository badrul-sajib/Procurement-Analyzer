import { useMutation, useQuery } from '@tanstack/react-query'
import { Download, FileSpreadsheet, FileText, Sheet } from 'lucide-react'
import { useEffect, useState } from 'react'
import { toast } from 'sonner'
import { Button } from '@/components/ui/button'
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu'
import { Spinner } from '@/components/ui/spinner'
import { api, downloadUrl } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import { errorMessage } from '@/lib/forms'

type ExportJob = { id: number; status: 'queued' | 'processing' | 'completed' | 'failed'; error: string | null; row_count: number | null }

/** Exports run as background jobs with the current filters; the file downloads when ready. */
export function ExportButton({ report, filters }: { report: string; filters: Record<string, string | number | undefined> }) {
  const { can } = useAuth()
  const [jobId, setJobId] = useState<number | null>(null)
  const start = useMutation({
    mutationFn: async (format: 'xlsx' | 'csv' | 'pdf') => (await api.post<{ data: ExportJob }>(`/reports/${report}/exports`, { format, filters })).data,
    onSuccess: (job) => setJobId(job.id),
    onError: (e) => toast.error(errorMessage(e)),
  })
  const job = useQuery({
    queryKey: ['exports', jobId],
    queryFn: async () => (await api.get<{ data: ExportJob }>(`/exports/${jobId}`)).data,
    enabled: jobId !== null,
    refetchInterval: (q) => (q.state.data && ['completed', 'failed'].includes(q.state.data.status) ? false : 1500),
  })

  useEffect(() => {
    if (job.data?.status === 'completed') {
      window.location.assign(downloadUrl(`/exports/${job.data.id}/download`))
      toast.success(`Export ready (${job.data.row_count ?? 0} rows)`)
      setJobId(null)
    } else if (job.data?.status === 'failed') {
      toast.error(job.data.error ?? 'Export failed')
      setJobId(null)
    }
  }, [job.data])

  if (!can('reports.export')) return null
  const busy = start.isPending || jobId !== null
  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <Button variant="outline" disabled={busy}>{busy ? <Spinner /> : <Download />} Export</Button>
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end">
        <DropdownMenuItem onClick={() => start.mutate('xlsx')}><FileSpreadsheet /> Excel (.xlsx)</DropdownMenuItem>
        <DropdownMenuItem onClick={() => start.mutate('csv')}><Sheet /> CSV</DropdownMenuItem>
        <DropdownMenuItem onClick={() => start.mutate('pdf')}><FileText /> PDF</DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  )
}
