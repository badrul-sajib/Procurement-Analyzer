import { useNavigate } from 'react-router'
import { DataTable, type DataTableColumn } from '@/components/data-table'
import { DateRangeFilter } from '@/components/date-range-filter'
import { PageHeader } from '@/components/page-header'
import { SearchInput } from '@/components/search-input'
import { StatusBadge } from '@/components/status-badge'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { useDeliveries, type Delivery } from '@/features/deliveries/api'
import { ExportButton } from '@/features/reports/export-button'
import { useListParams } from '@/hooks/use-list-params'
import { formatDate, formatQty } from '@/lib/format'

const columns: DataTableColumn<Delivery>[] = [
  { accessorKey: 'delivery_number', header: 'Delivery', meta: { sortKey: 'delivery_number', className: 'font-mono text-xs' } },
  { id: 'po', header: 'Purchase order', cell: ({ row }) => <span className="font-mono text-xs">{row.original.purchase_order.po_number}</span> },
  { id: 'vendor', header: 'Vendor', cell: ({ row }) => row.original.purchase_order.vendor?.name ?? '—' },
  { accessorKey: 'delivery_date', header: 'Date', meta: { sortKey: 'delivery_date' }, cell: ({ row }) => formatDate(row.original.delivery_date) },
  { accessorKey: 'delivery_reference', header: 'Reference', cell: ({ row }) => row.original.delivery_reference ?? '—' },
  { id: 'received_by', header: 'Received by', cell: ({ row }) => row.original.received_by?.name ?? '—' },
  { id: 'qty', header: 'Quantity', meta: { align: 'right' }, cell: ({ row }) => formatQty(row.original.total_quantity) },
  { accessorKey: 'status', header: 'Status', meta: { sortKey: 'status' }, cell: ({ row }) => <StatusBadge status={row.original.status} /> },
]

export function DeliveriesPage() {
  const navigate = useNavigate()
  const { params, set, page } = useListParams({ sort: '-delivery_number' })
  const { data, isLoading } = useDeliveries({ ...params, page })
  return (
    <>
      <PageHeader title="Deliveries" description="Goods received against purchased orders. Record deliveries from a purchase order's page."
        actions={<ExportButton report="deliveries" filters={params} />} />
      <div className="flex flex-wrap items-center gap-2">
        <SearchInput value={params.search ?? ''} onChange={(search) => set({ search })} placeholder="Delivery, challan or PO number…" />
        <Select value={params.status ?? 'all'} onValueChange={(v) => set({ status: v === 'all' ? null : v })}>
          <SelectTrigger className="w-40" aria-label="Status"><SelectValue /></SelectTrigger>
          <SelectContent>
            <SelectItem value="all">All statuses</SelectItem>
            {['pending', 'confirmed', 'reversed'].map((s) => <SelectItem key={s} value={s} className="capitalize">{s}</SelectItem>)}
          </SelectContent>
        </Select>
        <DateRangeFilter value={params} onChange={(v) => set(v)} />
      </div>
      <DataTable columns={columns} data={data?.data} meta={data?.meta} isLoading={isLoading} sort={params.sort}
        onSortChange={(sort) => set({ sort })} onPageChange={(p) => set({ page: p })} onRowClick={(d) => navigate(`/deliveries/${d.id}`)} emptyTitle="No deliveries" />
    </>
  )
}
