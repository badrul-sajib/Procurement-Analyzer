import { Plus } from 'lucide-react'
import { useNavigate } from 'react-router'
import { DataTable, type DataTableColumn } from '@/components/data-table'
import { PageHeader } from '@/components/page-header'
import { SearchInput } from '@/components/search-input'
import { StatusBadge } from '@/components/status-badge'
import { Button } from '@/components/ui/button'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { useItems, useLookups, type Item } from '@/features/catalog/api'
import { ItemFormSheet } from '@/features/items/item-form-sheet'
import { useListParams } from '@/hooks/use-list-params'
import { useAuth } from '@/lib/auth'

const columns: DataTableColumn<Item>[] = [
  { accessorKey: 'code', header: 'Code', meta: { sortKey: 'code', className: 'w-32 font-mono text-xs' } },
  { accessorKey: 'name', header: 'Name', meta: { sortKey: 'name' }, cell: ({ row }) => <span className="font-medium">{row.original.name}</span> },
  { id: 'category', header: 'Category', cell: ({ row }) => row.original.category?.name ?? '—' },
  { id: 'unit', header: 'Default unit', cell: ({ row }) => row.original.default_unit.name },
  { id: 'status', header: 'Status', cell: ({ row }) => <StatusBadge status={row.original.is_active ? 'active' : 'inactive'} /> },
]

export function ItemsPage() {
  const { can } = useAuth()
  const navigate = useNavigate()
  const { params, set, page } = useListParams({ sort: 'name' })
  const categories = useLookups('item-categories')
  const { data, isLoading } = useItems({ ...params, page })

  return (
    <>
      <PageHeader
        title="Items"
        description="Central catalog. Requisition lines are consolidated per item."
        actions={can('items.manage') && (
          <ItemFormSheet trigger={<Button><Plus /> New item</Button>} onSaved={(item) => navigate(`/items/${item.id}`)} />
        )}
      />
      <div className="flex flex-wrap gap-2">
        <SearchInput value={params.search ?? ''} onChange={(search) => set({ search })} placeholder="Search name, code, SKU, alias…" />
        <Select value={params.category_id ?? 'all'} onValueChange={(v) => set({ category_id: v === 'all' ? null : v })}>
          <SelectTrigger className="w-48"><SelectValue placeholder="Category" /></SelectTrigger>
          <SelectContent>
            <SelectItem value="all">All categories</SelectItem>
            {categories.data?.map((c) => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}
          </SelectContent>
        </Select>
        <Select value={params.is_active ?? 'all'} onValueChange={(v) => set({ is_active: v === 'all' ? null : v })}>
          <SelectTrigger className="w-36"><SelectValue /></SelectTrigger>
          <SelectContent>
            <SelectItem value="all">Any status</SelectItem>
            <SelectItem value="1">Active</SelectItem>
            <SelectItem value="0">Inactive</SelectItem>
          </SelectContent>
        </Select>
      </div>
      <DataTable
        columns={columns}
        data={data?.data}
        meta={data?.meta}
        isLoading={isLoading}
        sort={params.sort}
        onSortChange={(sort) => set({ sort })}
        onPageChange={(p) => set({ page: p })}
        onRowClick={(item) => navigate(`/items/${item.id}`)}
        emptyTitle="No items found"
      />
    </>
  )
}
