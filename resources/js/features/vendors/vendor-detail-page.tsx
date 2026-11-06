import { useQuery } from '@tanstack/react-query'
import { ArrowLeft, Pencil } from 'lucide-react'
import { Link, useParams, useSearchParams } from 'react-router'
import { PageHeader } from '@/components/page-header'
import { StatCard } from '@/components/stat-card'
import { StatusBadge } from '@/components/status-badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { useVendor } from '@/features/catalog/api'
import { useVendorBalance } from '@/features/payments/api'
import { PaymentsPage } from '@/features/payments/payments-page'
import { PurchaseOrdersPage } from '@/features/purchases/purchase-orders-page'
import { VendorFormSheet } from '@/features/vendors/vendor-form-sheet'
import { api, type Paginated } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import { formatDate, formatDateTime, formatInt, formatMoney, formatQty } from '@/lib/format'
import { useSettings } from '@/lib/settings'

type VendorItem = {
  item: { id: number; code: string; name: string; unit: string }
  total_purchased: string
  last_price: string
  last_purchase_date: string
  history: { purchase_order: { id: number; po_number: string }; purchase_date: string; quantity: string; unit_price: string }[]
}
type Activity = { id: number; action: string; module: string; description: string | null; user: { id: number; name: string } | null; created_at: string }

function Overview({ vendorId }: { vendorId: number }) {
  const { can } = useAuth()
  const symbol = useSettings().data?.currency_symbol
  const { data: vendor } = useVendor(vendorId)
  const balance = useVendorBalance(vendorId, can('finance.view'))
  const b = balance.data
  const rows: [string, string | null | undefined][] = [
    ['Contact person', vendor?.contact_person], ['Phone', vendor?.phone], ['Email', vendor?.email], ['Tax / VAT', vendor?.tax_number],
    ['Address', vendor?.address], ['Company information', vendor?.company_info], ['Notes', vendor?.notes],
  ]
  return (
    <div className="space-y-4">
      {can('finance.view') && (
        <div className="grid grid-cols-2 gap-3 lg:grid-cols-5">
          <StatCard label="Total purchased" loading={balance.isLoading} value={formatMoney(b?.total_purchase_amount, symbol)} />
          <StatCard label="Total paid" loading={balance.isLoading} value={formatMoney(b?.total_paid_amount, symbol)}
            hint={b && Number(b.unallocated_credit) > 0 ? `${formatMoney(b.unallocated_credit, symbol)} unallocated credit` : undefined} />
          <StatCard label="Outstanding" loading={balance.isLoading} value={formatMoney(b?.outstanding_amount, symbol)} hint={b?.payment_status ? b.payment_status.replace('_', ' ') : undefined} />
          <StatCard label="Purchase orders" loading={balance.isLoading} value={formatInt(b?.purchase_order_count)} hint="Purchased or completed" />
          <StatCard label="Last purchase" loading={balance.isLoading} value={formatDate(b?.last_purchase_date)} />
        </div>
      )}
      <Card className="max-w-3xl">
        <CardHeader><CardTitle>Vendor information</CardTitle></CardHeader>
        <CardContent>
          <dl className="grid grid-cols-[auto_1fr] gap-x-6 gap-y-2 text-sm">
            {rows.map(([label, value]) => (
              <div key={label} className="contents"><dt className="text-muted-foreground">{label}</dt><dd className="whitespace-pre-line">{value || '—'}</dd></div>
            ))}
          </dl>
        </CardContent>
      </Card>
    </div>
  )
}

function Items({ vendorId }: { vendorId: number }) {
  const symbol = useSettings().data?.currency_symbol
  const { data, isLoading } = useQuery({ queryKey: ['vendors', vendorId, 'items'], queryFn: async () => (await api.get<{ data: VendorItem[] }>(`/vendors/${vendorId}/items`)).data })
  if (isLoading) return <Skeleton className="h-32" />
  return (
    <Card>
      <CardContent>
        <Table>
          <TableHeader><TableRow><TableHead>Item</TableHead><TableHead className="text-right">Total purchased</TableHead><TableHead className="text-right">Last price</TableHead><TableHead>Last purchase</TableHead><TableHead>Price history</TableHead></TableRow></TableHeader>
          <TableBody>
            {data?.map((r) => (
              <TableRow key={r.item.id} className="align-top">
                <TableCell><Link className="font-medium hover:underline" to={`/items/${r.item.id}`}>{r.item.name}</Link><div className="text-xs text-muted-foreground">{r.item.code}</div></TableCell>
                <TableCell className="text-right tabular-nums">{formatQty(r.total_purchased)} {r.item.unit}</TableCell>
                <TableCell className="text-right tabular-nums">{formatMoney(r.last_price, symbol)}</TableCell>
                <TableCell>{formatDate(r.last_purchase_date)}</TableCell>
                <TableCell>
                  <ul className="space-y-0.5 text-xs">
                    {r.history.map((h) => (
                      <li key={h.purchase_order.id}>{formatDate(h.purchase_date)} · <Link className="font-mono hover:underline" to={`/purchase-orders/${h.purchase_order.id}`}>{h.purchase_order.po_number}</Link> · {formatQty(h.quantity)} @ {formatMoney(h.unit_price, symbol)}</li>
                    ))}
                  </ul>
                </TableCell>
              </TableRow>
            ))}
            {data && !data.length && <TableRow><TableCell colSpan={5} className="text-muted-foreground">Nothing purchased from this vendor yet.</TableCell></TableRow>}
          </TableBody>
        </Table>
      </CardContent>
    </Card>
  )
}

function ActivityTab({ vendorId }: { vendorId: number }) {
  const { data, isLoading } = useQuery({ queryKey: ['vendors', vendorId, 'activities'], queryFn: () => api.get<Paginated<Activity>>(`/vendors/${vendorId}/activities`, { per_page: 50 }) })
  if (isLoading) return <Skeleton className="h-32" />
  return (
    <Card>
      <CardContent>
        <ol className="space-y-3 text-sm">
          {data?.data.map((a) => (
            <li key={a.id} className="flex flex-col gap-0.5 sm:flex-row sm:gap-4">
              <span className="w-40 shrink-0 text-muted-foreground">{formatDateTime(a.created_at)}</span>
              <span>{a.description ?? a.action}{a.user && <span className="text-muted-foreground"> — {a.user.name}</span>}</span>
            </li>
          ))}
          {data && !data.data.length && <li className="text-muted-foreground">No activity yet.</li>}
        </ol>
      </CardContent>
    </Card>
  )
}

export function VendorDetailPage() {
  const id = Number(useParams().id)
  const { can } = useAuth()
  const [search, setSearch] = useSearchParams()
  const { data: vendor, isLoading } = useVendor(id)
  if (isLoading || !vendor) return <Skeleton className="h-64 w-full" />
  const tab = search.get('tab') ?? 'overview'

  return (
    <>
      <Button variant="ghost" size="sm" className="-ml-2 w-fit" asChild><Link to="/vendors"><ArrowLeft /> Vendors</Link></Button>
      <PageHeader title={vendor.name} description={vendor.code}
        actions={<>
          <StatusBadge status={vendor.is_active ? 'active' : 'inactive'} />
          {can('vendors.manage') && <VendorFormSheet vendor={vendor} trigger={<Button variant="outline"><Pencil /> Edit</Button>} />}
        </>} />
      {/* Switching tabs resets list filters: each tab's list has its own sort keys. */}
      <Tabs value={tab} onValueChange={(t) => setSearch({ tab: t }, { replace: true })}>
        <TabsList>
          <TabsTrigger value="overview">Overview</TabsTrigger>
          <TabsTrigger value="items">Items</TabsTrigger>
          {can('purchases.view') && <TabsTrigger value="purchases">Purchases</TabsTrigger>}
          {can('payments.view') && <TabsTrigger value="payments">Payments</TabsTrigger>}
          <TabsTrigger value="activity">Activity history</TabsTrigger>
        </TabsList>
        <TabsContent value="overview"><Overview vendorId={id} /></TabsContent>
        <TabsContent value="items"><Items vendorId={id} /></TabsContent>
        <TabsContent value="purchases" className="space-y-4">{tab === 'purchases' && <PurchaseOrdersPage vendorId={id} />}</TabsContent>
        <TabsContent value="payments" className="space-y-4">{tab === 'payments' && <PaymentsPage vendorId={id} />}</TabsContent>
        <TabsContent value="activity"><ActivityTab vendorId={id} /></TabsContent>
      </Tabs>
    </>
  )
}
