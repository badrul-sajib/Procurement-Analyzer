import { FilePlus2, Upload } from 'lucide-react'
import { Link, useNavigate } from 'react-router'
import { DataTable, type DataTableColumn } from '@/components/data-table'
import { DateRangeFilter } from '@/components/date-range-filter'
import { PageHeader } from '@/components/page-header'
import { SearchInput } from '@/components/search-input'
import { StatusBadge } from '@/components/status-badge'
import { Button } from '@/components/ui/button'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group'
import { useLookups } from '@/features/catalog/api'
import { useRequisitions, type Requisition } from '@/features/requisitions/api'
import { useListParams } from '@/hooks/use-list-params'
import { useAuth } from '@/lib/auth'
import { formatDate, formatDateTime, formatInt, formatQty } from '@/lib/format'

const STATUSES = ['draft', 'uploaded', 'processing', 'extracted', 'failed', 'verified', 'approved', 'cancelled']

const columns: DataTableColumn<Requisition>[] = [
  { accessorKey: 'reference_no', header: 'Reference', meta: { sortKey: 'reference_no', className: 'font-mono text-xs whitespace-nowrap' } },
  {
    accessorKey: 'title', header: 'Title', meta: { sortKey: 'title' },
    cell: ({ row }) => (
      <div className="min-w-0">
        <div className="truncate font-medium">{row.original.title}</div>
        {row.original.external_reference && <div className="truncate text-xs text-muted-foreground">Doc ref {row.original.external_reference}</div>}
      </div>
    ),
  },
  { id: 'department', header: 'Department', cell: ({ row }) => row.original.department?.name ?? '—' },
  { accessorKey: 'requisition_date', header: 'Req. date', meta: { sortKey: 'requisition_date', className: 'whitespace-nowrap' }, cell: ({ row }) => formatDate(row.original.requisition_date) },
  { accessorKey: 'uploaded_at', header: 'Uploaded', meta: { sortKey: 'uploaded_at', className: 'whitespace-nowrap' }, cell: ({ row }) => formatDateTime(row.original.uploaded_at) },
  { id: 'lines', header: 'Lines', meta: { align: 'right' }, cell: ({ row }) => formatInt(row.original.line_count) },
  { id: 'quantity', header: 'Requested qty', meta: { align: 'right' }, cell: ({ row }) => formatQty(row.original.total_requested_quantity) },
  { accessorKey: 'status', header: 'Status', meta: { sortKey: 'status' }, cell: ({ row }) => <StatusBadge status={row.original.status} /> },
]

export function RequisitionsPage() {
  const { can } = useAuth()
  const navigate = useNavigate()
  const { params, set, page } = useListParams({ sort: '-uploaded_at' })
  const departments = useLookups('departments')
  const { data, isLoading } = useRequisitions({ ...params, page })

  return (
    <>
      <PageHeader
        title="Requisitions"
        description="Uploaded and manually entered requisitions. Only verified requisitions count in the analyzer."
        actions={can('requisitions.upload') && <>
          <Button variant="outline" asChild><Link to="/requisitions/new"><FilePlus2 /> Enter manually</Link></Button>
          <Button asChild><Link to="/requisitions/upload"><Upload /> Upload</Link></Button>
        </>}
      />
      <div className="flex flex-wrap items-center gap-2">
        <SearchInput value={params.search ?? ''} onChange={(search) => set({ search })} placeholder="Reference, title, document ref…" />
        <Select value={params.status ?? 'all'} onValueChange={(v) => set({ status: v === 'all' ? null : v })}>
          <SelectTrigger className="w-40" aria-label="Status"><SelectValue /></SelectTrigger>
          <SelectContent>
            <SelectItem value="all">All statuses</SelectItem>
            <SelectItem value="extracted,failed">Needs review</SelectItem>
            {STATUSES.map((s) => <SelectItem key={s} value={s} className="capitalize">{s}</SelectItem>)}
          </SelectContent>
        </Select>
        <Select value={params.department_id ?? 'all'} onValueChange={(v) => set({ department_id: v === 'all' ? null : v })}>
          <SelectTrigger className="w-44" aria-label="Department"><SelectValue /></SelectTrigger>
          <SelectContent>
            <SelectItem value="all">All departments</SelectItem>
            {departments.data?.map((d) => <SelectItem key={d.id} value={String(d.id)}>{d.name}</SelectItem>)}
          </SelectContent>
        </Select>
        <DateRangeFilter value={params} onChange={(v) => set(v)} />
        {params.period && (
          <ToggleGroup type="single" variant="outline" size="sm" value={params.date_basis ?? 'requisition_date'} onValueChange={(v) => v && set({ date_basis: v === 'requisition_date' ? null : v })}>
            <ToggleGroupItem value="requisition_date">Requisition date</ToggleGroupItem>
            <ToggleGroupItem value="uploaded_at">Upload date</ToggleGroupItem>
          </ToggleGroup>
        )}
      </div>
      <DataTable
        columns={columns} data={data?.data} meta={data?.meta} isLoading={isLoading}
        sort={params.sort} onSortChange={(sort) => set({ sort })} onPageChange={(p) => set({ page: p })}
        onRowClick={(r) => navigate(`/requisitions/${r.id}`)} emptyTitle="No requisitions found"
        emptyDescription="Upload a document or change the filters."
      />
    </>
  )
}
