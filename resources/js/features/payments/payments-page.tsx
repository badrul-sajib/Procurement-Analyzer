import { Plus } from 'lucide-react'
import { Link, useNavigate } from 'react-router'
import { DataTable, type DataTableColumn } from '@/components/data-table'
import { DateRangeFilter } from '@/components/date-range-filter'
import { PageHeader } from '@/components/page-header'
import { SearchInput } from '@/components/search-input'
import { StatusBadge } from '@/components/status-badge'
import { Button } from '@/components/ui/button'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { METHODS, usePayments, type Payment } from '@/features/payments/api'
import { ExportButton } from '@/features/reports/export-button'
import { useListParams } from '@/hooks/use-list-params'
import { useAuth } from '@/lib/auth'
import { formatDate, formatMoney } from '@/lib/format'
import { useSettings } from '@/lib/settings'

export function PaymentsPage({ vendorId }: { vendorId?: number }) {
  const { can } = useAuth()
  const navigate = useNavigate()
  const symbol = useSettings().data?.currency_symbol
  const { params, set, page } = useListParams({ sort: '-payment_date' })
  const { data, isLoading } = usePayments({ ...params, page, vendor_id: vendorId ?? params.vendor_id })
  const methodLabel = Object.fromEntries(METHODS)

  const columns: DataTableColumn<Payment>[] = [
    { accessorKey: 'payment_reference', header: 'Reference', meta: { sortKey: 'payment_reference', className: 'font-mono text-xs' } },
    ...(vendorId ? [] : [{ id: 'vendor', header: 'Vendor', cell: ({ row }) => <span className="font-medium">{row.original.vendor.name}</span> } as DataTableColumn<Payment>]),
    { accessorKey: 'payment_date', header: 'Date', meta: { sortKey: 'payment_date' }, cell: ({ row }) => formatDate(row.original.payment_date) },
    { id: 'method', header: 'Method', cell: ({ row }) => methodLabel[row.original.method] },
    { accessorKey: 'transaction_reference', header: 'Transaction ref', cell: ({ row }) => row.original.transaction_reference ?? '—' },
    { id: 'amount', header: 'Amount', meta: { sortKey: 'amount', align: 'right' }, cell: ({ row }) => formatMoney(row.original.amount, symbol) },
    { id: 'unallocated', header: 'Unallocated', meta: { align: 'right' }, cell: ({ row }) => row.original.status === 'active' ? formatMoney(row.original.unallocated_amount, symbol) : '—' },
    { accessorKey: 'status', header: 'Status', cell: ({ row }) => <StatusBadge status={row.original.status} /> },
  ]

  return (
    <>
      {!vendorId && (
        <PageHeader title="Payments" description="Vendor payments and how they are allocated to purchase orders."
          actions={<>
            <ExportButton report="vendor-payments" filters={params} />
            {can('payments.create') && <Button asChild><Link to="/payments/new"><Plus /> Record payment</Link></Button>}
          </>} />
      )}
      <div className="flex flex-wrap items-center gap-2">
        <SearchInput value={params.search ?? ''} onChange={(search) => set({ search })} placeholder="Reference, transaction or vendor…" />
        <Select value={params.method ?? 'all'} onValueChange={(v) => set({ method: v === 'all' ? null : v })}>
          <SelectTrigger className="w-48" aria-label="Method"><SelectValue /></SelectTrigger>
          <SelectContent><SelectItem value="all">All methods</SelectItem>{METHODS.map(([k, l]) => <SelectItem key={k} value={k}>{l}</SelectItem>)}</SelectContent>
        </Select>
        <Select value={params.status ?? 'all'} onValueChange={(v) => set({ status: v === 'all' ? null : v })}>
          <SelectTrigger className="w-36" aria-label="Status"><SelectValue /></SelectTrigger>
          <SelectContent><SelectItem value="all">Any status</SelectItem><SelectItem value="active">Active</SelectItem><SelectItem value="reversed">Reversed</SelectItem></SelectContent>
        </Select>
        <DateRangeFilter value={params} onChange={(v) => set(v)} />
      </div>
      <DataTable columns={columns} data={data?.data} meta={data?.meta} isLoading={isLoading} sort={params.sort}
        onSortChange={(sort) => set({ sort })} onPageChange={(p) => set({ page: p })} onRowClick={(p) => navigate(`/payments/${p.id}`)} emptyTitle="No payments" />
    </>
  )
}
