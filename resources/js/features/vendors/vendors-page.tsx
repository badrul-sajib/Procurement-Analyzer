import { Plus } from 'lucide-react'
import { useNavigate } from 'react-router'
import { DataTable, type DataTableColumn } from '@/components/data-table'
import { PageHeader } from '@/components/page-header'
import { SearchInput } from '@/components/search-input'
import { StatusBadge } from '@/components/status-badge'
import { Button } from '@/components/ui/button'
import { useVendors, type Vendor } from '@/features/catalog/api'
import { VendorFormSheet } from '@/features/vendors/vendor-form-sheet'
import { useListParams } from '@/hooks/use-list-params'
import { useAuth } from '@/lib/auth'

const columns: DataTableColumn<Vendor>[] = [
  { accessorKey: 'code', header: 'Code', meta: { sortKey: 'code', className: 'w-28 font-mono text-xs' } },
  { accessorKey: 'name', header: 'Vendor', meta: { sortKey: 'name' }, cell: ({ row }) => <span className="font-medium">{row.original.name}</span> },
  { accessorKey: 'contact_person', header: 'Contact', cell: ({ row }) => row.original.contact_person ?? '—' },
  { accessorKey: 'phone', header: 'Phone', cell: ({ row }) => row.original.phone ?? '—' },
  { id: 'status', header: 'Status', cell: ({ row }) => <StatusBadge status={row.original.is_active ? 'active' : 'inactive'} /> },
]

export function VendorsPage() {
  const { can } = useAuth()
  const navigate = useNavigate()
  const { params, set, page } = useListParams({ sort: 'name' })
  const { data, isLoading } = useVendors({ ...params, page })

  return (
    <>
      <PageHeader
        title="Vendors"
        description="Suppliers, their purchases and payment balances."
        actions={can('vendors.manage') && <VendorFormSheet trigger={<Button><Plus /> New vendor</Button>} onSaved={(v) => navigate(`/vendors/${v.id}`)} />}
      />
      <SearchInput value={params.search ?? ''} onChange={(search) => set({ search })} placeholder="Search name, code, phone, email…" />
      <DataTable columns={columns} data={data?.data} meta={data?.meta} isLoading={isLoading} sort={params.sort}
        onSortChange={(sort) => set({ sort })} onPageChange={(p) => set({ page: p })} onRowClick={(v) => navigate(`/vendors/${v.id}`)}
        emptyTitle="No vendors found" />
    </>
  )
}
