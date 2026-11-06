import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { ChevronDown, ChevronRight } from 'lucide-react'
import { useState } from 'react'
import { DataTable, type DataTableColumn } from '@/components/data-table'
import { DateRangeFilter } from '@/components/date-range-filter'
import { PageHeader } from '@/components/page-header'
import { SearchInput } from '@/components/search-input'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { useListParams } from '@/hooks/use-list-params'
import { api, type Paginated } from '@/lib/api'
import { formatDateTime, titleCase } from '@/lib/format'

type Log = { id: number; action: string; module: string; record_id: number | null; description: string | null; old_values: unknown; new_values: unknown; ip_address: string | null; user: { id: number; name: string } | null; created_at: string }

function Values({ log }: { log: Log }) {
  // Rendered as text inside <pre>: never interpreted as HTML.
  return (
    <div className="grid gap-4 p-4 md:grid-cols-2">
      {(['old_values', 'new_values'] as const).map((k) => (
        <div key={k}>
          <div className="mb-1 text-xs font-medium text-muted-foreground">{k === 'old_values' ? 'Before' : 'After'}</div>
          <pre className="max-h-64 overflow-auto rounded-md bg-muted p-3 text-xs">{log[k] ? JSON.stringify(log[k], null, 2) : '—'}</pre>
        </div>
      ))}
      <div className="text-xs text-muted-foreground md:col-span-2">IP {log.ip_address ?? '—'} · record #{log.record_id ?? '—'}</div>
    </div>
  )
}

export function ActivityLogsPage() {
  const { params, set, page } = useListParams()
  const [open, setOpen] = useState<number | null>(null)
  const { data, isLoading } = useQuery({
    queryKey: ['activity-logs', params, page],
    queryFn: () => api.get<Paginated<Log> & { modules: string[] }>('/activity-logs', { ...params, page }),
    placeholderData: keepPreviousData,
  })
  const columns: DataTableColumn<Log>[] = [
    { id: 'toggle', header: '', meta: { className: 'w-10' }, cell: ({ row }) => (
      <Button variant="ghost" size="icon" className="size-7" aria-label="Show details" onClick={() => setOpen(open === row.original.id ? null : row.original.id)}>
        {open === row.original.id ? <ChevronDown /> : <ChevronRight />}
      </Button>
    ) },
    { id: 'at', header: 'When', meta: { className: 'whitespace-nowrap' }, cell: ({ row }) => formatDateTime(row.original.created_at) },
    { id: 'user', header: 'User', cell: ({ row }) => row.original.user?.name ?? 'System' },
    { id: 'module', header: 'Module', cell: ({ row }) => <Badge variant="outline">{titleCase(row.original.module)}</Badge> },
    { id: 'action', header: 'Action', cell: ({ row }) => <span className="font-mono text-xs">{row.original.action}</span> },
    { id: 'description', header: 'Description', cell: ({ row }) => row.original.description ?? '—' },
  ]
  return (
    <>
      <PageHeader title="Activity log" description="Append-only audit trail of every important change. Entries can't be edited or deleted." />
      <div className="flex flex-wrap items-center gap-2">
        <SearchInput value={params.search ?? ''} onChange={(search) => set({ search })} placeholder="Search descriptions…" />
        <Select value={params.module ?? 'all'} onValueChange={(v) => set({ module: v === 'all' ? null : v })}>
          <SelectTrigger className="w-48" aria-label="Module"><SelectValue /></SelectTrigger>
          <SelectContent><SelectItem value="all">All modules</SelectItem>{data?.modules.map((m) => <SelectItem key={m} value={m}>{titleCase(m)}</SelectItem>)}</SelectContent>
        </Select>
        <DateRangeFilter value={params} onChange={(v) => set(v)} />
      </div>
      <DataTable columns={columns} data={data?.data} meta={data?.meta} isLoading={isLoading} onPageChange={(p) => set({ page: p })}
        isExpanded={(l) => l.id === open} renderExpanded={(row) => <Values log={row.original} />} emptyTitle="No activity" />
    </>
  )
}
