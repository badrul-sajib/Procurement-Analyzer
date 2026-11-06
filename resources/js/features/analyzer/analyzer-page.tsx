import { ChevronDown, ChevronRight, ClipboardList, FileStack, Layers, PackageCheck, ShoppingCart, Truck } from 'lucide-react'
import { useState } from 'react'
import { Link, useNavigate } from 'react-router'
import { AsyncCombobox } from '@/components/async-combobox'
import { DataTable, type DataTableColumn } from '@/components/data-table'
import { DateRangeFilter } from '@/components/date-range-filter'
import { PageHeader } from '@/components/page-header'
import { SearchInput } from '@/components/search-input'
import { StatCard } from '@/components/stat-card'
import { StatusBadge } from '@/components/status-badge'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Skeleton } from '@/components/ui/skeleton'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { ExportButton } from '@/features/reports/export-button'
import { useAnalyzerItems, useAnalyzerSources, useAnalyzerSummary, type AnalyzerRow } from '@/features/analyzer/api'
import { useLookups } from '@/features/catalog/api'
import type { Requisition } from '@/features/requisitions/api'
import { useListParams } from '@/hooks/use-list-params'
import { useAuth } from '@/lib/auth'
import { formatDate, formatInt, formatQty } from '@/lib/format'

const FILTER_KEYS = ['period', 'date_from', 'date_to', 'requisition_id', 'category_id', 'department_id', 'status', 'search', 'purchase_status', 'delivery_status'] as const

function Sources({ itemId, filters }: { itemId: number; filters: Record<string, string> }) {
  const { data, isLoading } = useAnalyzerSources(itemId, filters)
  if (isLoading) return <div className="p-4"><Skeleton className="h-16" /></div>
  return (
    <div className="px-4 py-3">
      <Table>
        <TableHeader>
          <TableRow className="hover:bg-transparent">
            <TableHead>Requisition</TableHead><TableHead>Date</TableHead><TableHead>As written</TableHead><TableHead>Destination</TableHead>
            <TableHead className="text-right">Requested</TableHead><TableHead className="text-right">Approved</TableHead>
            <TableHead className="text-right">On order</TableHead><TableHead className="text-right">Purchased</TableHead>
            <TableHead className="text-right">Pending</TableHead><TableHead className="text-right">Delivered</TableHead>
          </TableRow>
        </TableHeader>
        <TableBody>
          {data?.map((l) => (
            <TableRow key={l.line_id} className="hover:bg-transparent">
              <TableCell><Link className="font-mono text-xs hover:underline" to={`/requisitions/${l.requisition.id}`}>{l.requisition.reference_no}</Link>
                {l.requisition.status !== 'approved' && <StatusBadge className="ml-2" status={l.requisition.status} />}</TableCell>
              <TableCell>{formatDate(l.requisition.requisition_date)}</TableCell>
              <TableCell>{l.original_name} <span className="text-muted-foreground">({formatQty(l.quantity)} {l.unit})</span></TableCell>
              <TableCell>{l.department ?? '—'}</TableCell>
              <TableCell className="text-right tabular-nums">{formatQty(l.requested)}</TableCell>
              <TableCell className="text-right tabular-nums">{formatQty(l.approved)}</TableCell>
              <TableCell className="text-right tabular-nums">{formatQty(l.on_order)}</TableCell>
              <TableCell className="text-right tabular-nums">{formatQty(l.purchased)}</TableCell>
              <TableCell className="text-right tabular-nums">{formatQty(l.pending)}</TableCell>
              <TableCell className="text-right tabular-nums">{formatQty(l.delivered)}</TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </div>
  )
}

export function AnalyzerPage() {
  const { can } = useAuth()
  const navigate = useNavigate()
  const { params, set, page } = useListParams({ sort: 'name' })
  const [expanded, setExpanded] = useState<Set<number>>(new Set())
  const [selected, setSelected] = useState<Set<number>>(new Set())
  const [requisition, setRequisition] = useState<{ value: number; label: string } | null>(null)
  const categories = useLookups('item-categories')
  const departments = useLookups('departments')

  const filters = Object.fromEntries(FILTER_KEYS.filter((k) => params[k]).map((k) => [k, params[k]])) as Record<string, string>
  const summary = useAnalyzerSummary(filters)
  const items = useAnalyzerItems({ ...filters, sort: params.sort, page })
  const s = summary.data

  const toggle = (set: Set<number>, id: number) => {
    const next = new Set(set)
    next.has(id) ? next.delete(id) : next.add(id)
    return next
  }

  const columns: DataTableColumn<AnalyzerRow>[] = [
    {
      id: 'expand', header: '', meta: { className: 'w-16' },
      cell: ({ row }) => (
        <div className="flex items-center gap-1" onClick={(e) => e.stopPropagation()}>
          <Button variant="ghost" size="icon" className="size-7" aria-label={`Show requisitions for ${row.original.item.name}`}
            onClick={() => setExpanded(toggle(expanded, row.original.item.id))}>
            {expanded.has(row.original.item.id) ? <ChevronDown /> : <ChevronRight />}
          </Button>
          {can('purchases.create') && (
            <Checkbox aria-label={`Select ${row.original.item.name}`} disabled={Number(row.original.pending) <= 0}
              checked={selected.has(row.original.item.id)} onCheckedChange={() => setSelected(toggle(selected, row.original.item.id))} />
          )}
        </div>
      ),
    },
    {
      id: 'name', header: 'Item', meta: { sortKey: 'name' },
      cell: ({ row }) => <div><div className="font-medium">{row.original.item.name}</div><div className="text-xs text-muted-foreground">{row.original.item.code} · {row.original.item.unit}</div></div>,
    },
    { id: 'requisition_count', header: 'Requisitions', meta: { sortKey: 'requisition_count', align: 'right' }, cell: ({ row }) => formatInt(row.original.requisition_count) },
    { id: 'requested', header: 'Requested', meta: { sortKey: 'requested', align: 'right' }, cell: ({ row }) => formatQty(row.original.requested) },
    { id: 'approved', header: 'Approved', meta: { sortKey: 'approved', align: 'right' }, cell: ({ row }) => formatQty(row.original.approved) },
    { id: 'on_order', header: 'On order', meta: { sortKey: 'on_order', align: 'right' }, cell: ({ row }) => formatQty(row.original.on_order) },
    { id: 'purchased', header: 'Purchased', meta: { sortKey: 'purchased', align: 'right' }, cell: ({ row }) => formatQty(row.original.purchased) },
    { id: 'pending', header: 'Pending', meta: { sortKey: 'pending', align: 'right' }, cell: ({ row }) => <span className="font-medium">{formatQty(row.original.pending)}</span> },
    { id: 'delivered', header: 'Delivered', meta: { sortKey: 'delivered', align: 'right' }, cell: ({ row }) => formatQty(row.original.delivered) },
    { id: 'remaining_delivery', header: 'To deliver', meta: { sortKey: 'remaining_delivery', align: 'right' }, cell: ({ row }) => formatQty(row.original.remaining_delivery) },
    { id: 'purchase_status', header: 'Purchase', cell: ({ row }) => <StatusBadge status={row.original.purchase_status} /> },
    { id: 'delivery_status', header: 'Delivery', cell: ({ row }) => <StatusBadge status={row.original.delivery_status} /> },
  ]

  return (
    <>
      <PageHeader
        title="Requisition analyzer"
        description="Verified and approved requisitions consolidated per item. Quantities are in each item's default unit."
        actions={<>
          <DateRangeFilter value={params} onChange={(v) => set(v)} />
          <div className="w-60">
            <AsyncCombobox<Requisition> endpoint="/requisitions" params={{ status: 'verified,approved' }}
              toOption={(r) => ({ value: r.id, label: r.reference_no, hint: r.title })}
              value={requisition ?? (params.requisition_id ? { value: Number(params.requisition_id), label: `Requisition #${params.requisition_id}` } : null)}
              onChange={(o) => { setRequisition(o); set({ requisition_id: o?.value ?? null }) }} placeholder="All requisitions" />
          </div>
          {params.requisition_id && <Button variant="ghost" size="sm" onClick={() => { setRequisition(null); set({ requisition_id: null }) }}>Clear</Button>}
          <ExportButton report="items" filters={filters} />
        </>}
      />

      <div className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
        <StatCard label="Requisitions" icon={FileStack} loading={summary.isLoading} value={formatInt(s?.total_requisitions)} hint={`${formatInt(s?.requisition_lines)} lines`} />
        <StatCard label="Unique items" icon={Layers} loading={summary.isLoading} value={formatInt(s?.unique_items)} />
        <StatCard label="Requested qty" icon={ClipboardList} loading={summary.isLoading} value={formatQty(s?.requested_quantity)} hint={`${formatQty(s?.approved_quantity)} approved`} />
        <StatCard label="Pending purchase" icon={ShoppingCart} loading={summary.isLoading} value={formatQty(s?.pending_quantity)} hint={`${formatQty(s?.on_order_quantity)} on open POs`} />
        <StatCard label="Purchased qty" icon={PackageCheck} loading={summary.isLoading} value={formatQty(s?.purchased_quantity)} />
        <StatCard label="Delivered qty" icon={Truck} loading={summary.isLoading} value={formatQty(s?.delivered_quantity)} hint={`${formatQty(s?.remaining_delivery_quantity)} still to deliver`} />
      </div>

      <div className="flex flex-wrap items-center gap-2">
        <SearchInput value={params.search ?? ''} onChange={(search) => set({ search })} placeholder="Search item name or code…" />
        <Select value={params.category_id ?? 'all'} onValueChange={(v) => set({ category_id: v === 'all' ? null : v })}>
          <SelectTrigger className="w-44" aria-label="Category"><SelectValue /></SelectTrigger>
          <SelectContent><SelectItem value="all">All categories</SelectItem>{categories.data?.map((c) => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}</SelectContent>
        </Select>
        <Select value={params.department_id ?? 'all'} onValueChange={(v) => set({ department_id: v === 'all' ? null : v })}>
          <SelectTrigger className="w-44" aria-label="Destination"><SelectValue /></SelectTrigger>
          <SelectContent><SelectItem value="all">All destinations</SelectItem>{departments.data?.map((d) => <SelectItem key={d.id} value={String(d.id)}>{d.name}</SelectItem>)}</SelectContent>
        </Select>
        <Select value={params.status ?? 'all'} onValueChange={(v) => set({ status: v === 'all' ? null : v })}>
          <SelectTrigger className="w-44" aria-label="Requisition status"><SelectValue /></SelectTrigger>
          <SelectContent><SelectItem value="all">Verified & approved</SelectItem><SelectItem value="verified">Verified only</SelectItem><SelectItem value="approved">Approved only</SelectItem></SelectContent>
        </Select>
        <Select value={params.purchase_status ?? 'all'} onValueChange={(v) => set({ purchase_status: v === 'all' ? null : v })}>
          <SelectTrigger className="w-44" aria-label="Purchase status"><SelectValue /></SelectTrigger>
          <SelectContent>
            <SelectItem value="all">Any purchase status</SelectItem>
            {['not_approved', 'not_purchased', 'partially_purchased', 'purchased'].map((s) => <SelectItem key={s} value={s}>{s.replace(/_/g, ' ')}</SelectItem>)}
          </SelectContent>
        </Select>
        <Select value={params.delivery_status ?? 'all'} onValueChange={(v) => set({ delivery_status: v === 'all' ? null : v })}>
          <SelectTrigger className="w-44" aria-label="Delivery status"><SelectValue /></SelectTrigger>
          <SelectContent>
            <SelectItem value="all">Any delivery status</SelectItem>
            {['not_delivered', 'partially_delivered', 'fully_delivered'].map((s) => <SelectItem key={s} value={s}>{s.replace(/_/g, ' ')}</SelectItem>)}
          </SelectContent>
        </Select>
        {selected.size > 0 && (
          <Button className="ml-auto" onClick={() => navigate(`/purchase-orders/new?items=${[...selected].join(',')}`)}>
            <ShoppingCart /> Create PO for {selected.size} item{selected.size > 1 ? 's' : ''}
          </Button>
        )}
      </div>

      <DataTable
        columns={columns} data={items.data?.data} meta={items.data?.meta} isLoading={items.isLoading}
        sort={params.sort} onSortChange={(sort) => set({ sort })} onPageChange={(p) => set({ page: p })}
        isExpanded={(r) => expanded.has(r.item.id)} renderExpanded={(row) => <Sources itemId={row.original.item.id} filters={filters} />}
        emptyTitle="No confirmed requisition lines" emptyDescription="Verify requisitions, or widen the date range."
      />
    </>
  )
}
