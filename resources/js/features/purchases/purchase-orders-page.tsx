import { Plus } from 'lucide-react'
import { Link, useNavigate } from 'react-router'
import { AsyncCombobox } from '@/components/async-combobox'
import { DataTable, type DataTableColumn } from '@/components/data-table'
import { DateRangeFilter } from '@/components/date-range-filter'
import { PageHeader } from '@/components/page-header'
import { SearchInput } from '@/components/search-input'
import { StatusBadge } from '@/components/status-badge'
import { Button } from '@/components/ui/button'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group'
import type { Vendor } from '@/features/catalog/api'
import { usePurchaseOrders, type PurchaseOrder } from '@/features/purchases/api'
import { useListParams } from '@/hooks/use-list-params'
import { useAuth } from '@/lib/auth'
import { formatDate, formatMoney } from '@/lib/format'
import { useSettings } from '@/lib/settings'

const STATUSES = ['draft', 'pending_approval', 'approved', 'purchased', 'partially_purchased', 'completed', 'cancelled']

export function PurchaseOrdersPage({ vendorId }: { vendorId?: number }) {
  const { can } = useAuth()
  const navigate = useNavigate()
  const symbol = useSettings().data?.currency_symbol
  const { params, set, page } = useListParams({ sort: '-po_number' })
  const { data, isLoading } = usePurchaseOrders({ ...params, page, vendor_id: vendorId ?? params.vendor_id })
  const finance = can('finance.view')

  const columns: DataTableColumn<PurchaseOrder>[] = [
    { accessorKey: 'po_number', header: 'PO number', meta: { sortKey: 'po_number', className: 'font-mono text-xs whitespace-nowrap' } },
    ...(vendorId ? [] : [{ id: 'vendor', header: 'Vendor', cell: ({ row }) => <span className="font-medium">{row.original.vendor.name}</span> } as DataTableColumn<PurchaseOrder>]),
    { accessorKey: 'order_date', header: 'Ordered', meta: { sortKey: 'order_date', className: 'whitespace-nowrap' }, cell: ({ row }) => formatDate(row.original.order_date) },
    { accessorKey: 'purchase_date', header: 'Purchased', meta: { sortKey: 'purchase_date', className: 'whitespace-nowrap' }, cell: ({ row }) => formatDate(row.original.purchase_date) },
    { id: 'total', header: 'Total', meta: { sortKey: 'grand_total', align: 'right' }, cell: ({ row }) => formatMoney(row.original.grand_total, symbol) },
    ...(finance ? [{ id: 'due', header: 'Due', meta: { align: 'right' }, cell: ({ row }) => row.original.payment_status ? formatMoney(row.original.due_amount, symbol) : '—' } as DataTableColumn<PurchaseOrder>] : []),
    { accessorKey: 'status', header: 'Status', meta: { sortKey: 'status' }, cell: ({ row }) => <StatusBadge status={row.original.status} /> },
    { id: 'delivery', header: 'Delivery', cell: ({ row }) => <StatusBadge status={row.original.delivery_status} /> },
    ...(finance ? [{ id: 'payment', header: 'Payment', cell: ({ row }) => row.original.payment_status ? <StatusBadge status={row.original.payment_status} /> : '—' } as DataTableColumn<PurchaseOrder>] : []),
  ]

  return (
    <>
      {!vendorId && (
        <PageHeader title="Purchase orders" description="Orders placed against approved requisition quantities."
          actions={can('purchases.create') && <Button asChild><Link to="/purchase-orders/new"><Plus /> New purchase order</Link></Button>} />
      )}
      <div className="flex flex-wrap items-center gap-2">
        <SearchInput value={params.search ?? ''} onChange={(search) => set({ search })} placeholder="PO number or vendor…" />
        <Select value={params.status ?? 'all'} onValueChange={(v) => set({ status: v === 'all' ? null : v })}>
          <SelectTrigger className="w-44" aria-label="Status"><SelectValue /></SelectTrigger>
          <SelectContent>
            <SelectItem value="all">All statuses</SelectItem>
            <SelectItem value="draft,pending_approval,approved">Open</SelectItem>
            {STATUSES.map((s) => <SelectItem key={s} value={s}>{s.replace(/_/g, ' ')}</SelectItem>)}
          </SelectContent>
        </Select>
        {!vendorId && (
          <div className="w-56">
            <AsyncCombobox<Vendor> endpoint="/vendors" toOption={(v) => ({ value: v.id, label: v.name, hint: v.code })}
              value={params.vendor_id ? { value: Number(params.vendor_id), label: params.vendor_name ?? 'Vendor' } : null}
              onChange={(o) => set({ vendor_id: o?.value ?? null, vendor_name: o?.label ?? null })} placeholder="All vendors" />
          </div>
        )}
        <DateRangeFilter value={params} onChange={(v) => set(v)} />
        {params.period && (
          <ToggleGroup type="single" variant="outline" size="sm" value={params.date_basis ?? 'order_date'} onValueChange={(v) => v && set({ date_basis: v === 'order_date' ? null : v })}>
            <ToggleGroupItem value="order_date">Order date</ToggleGroupItem>
            <ToggleGroupItem value="purchase_date">Purchase date</ToggleGroupItem>
          </ToggleGroup>
        )}
      </div>
      <DataTable columns={columns} data={data?.data} meta={data?.meta} isLoading={isLoading} sort={params.sort}
        onSortChange={(sort) => set({ sort })} onPageChange={(p) => set({ page: p })} onRowClick={(po) => navigate(`/purchase-orders/${po.id}`)}
        emptyTitle="No purchase orders" emptyDescription="Create one from the analyzer or with the New purchase order button." />
    </>
  )
}
