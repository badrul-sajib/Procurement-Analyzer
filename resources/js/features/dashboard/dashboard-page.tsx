import { useQuery } from '@tanstack/react-query'
import { Banknote, ClipboardList, FileStack, HandCoins, Layers, PackageCheck, Scale, ShoppingCart, Truck } from 'lucide-react'
import { Link } from 'react-router'
import { BarChartCard } from '@/components/chart-card'
import { DateRangeFilter } from '@/components/date-range-filter'
import { PageHeader } from '@/components/page-header'
import { StatCard } from '@/components/stat-card'
import { StatusBadge } from '@/components/status-badge'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { useListParams } from '@/hooks/use-list-params'
import { api } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import { formatDate, formatDateTime, formatInt, formatMoney, formatQty } from '@/lib/format'
import { useSettings } from '@/lib/settings'

type Summary = Record<string, string | number>
type Charts = Record<string, Record<string, string | number | null>[] | Record<string, string>>
type Recent = { id: number; reference: string; title: string; status: string; amount?: string; at: string }
type StatusUpdate = { id: number; module: string; record_id: number; description: string | null; user: string | null; at: string }

const LINKS: Record<string, string> = { requisitions: '/requisitions', purchase_orders: '/purchase-orders', deliveries: '/deliveries', payments: '/payments' }
const LABELS: Record<string, string> = { requisitions: 'Latest requisition uploads', purchase_orders: 'Latest purchase orders', deliveries: 'Latest deliveries', payments: 'Latest vendor payments' }

export function DashboardPage() {
  const { user, can } = useAuth()
  const symbol = useSettings().data?.currency_symbol
  const { params, set } = useListParams({ period: 'this_month' })
  const filters = { period: params.period, date_from: params.date_from, date_to: params.date_to }
  const summary = useQuery({ queryKey: ['dashboard', 'summary', filters], queryFn: async () => (await api.get<{ data: Summary }>('/dashboard/summary', filters)).data })
  const charts = useQuery({ queryKey: ['dashboard', 'charts', filters], queryFn: async () => (await api.get<{ data: Charts }>('/dashboard/charts', filters)).data })
  const recent = useQuery({ queryKey: ['dashboard', 'recent'], queryFn: async () => (await api.get<{ data: Record<string, Recent[] | StatusUpdate[]> }>('/dashboard/recent-activities')).data })
  const s = summary.data
  const c = charts.data
  const money = (v: number) => formatMoney(v, symbol)
  const rows = (key: string) => c?.[key] as Record<string, string | number | null>[] | undefined
  const fulfilment = c?.fulfilment as Record<string, string> | undefined

  return (
    <>
      <PageHeader title={`Welcome, ${user?.name ?? ''}`}
        description="Requisition figures use the requisition date, purchases the purchase date, deliveries the delivery date and payments the payment date."
        actions={<DateRangeFilter value={params} onChange={(v) => set(v)} />} />

      <div className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
        <StatCard label="Requisitions" icon={FileStack} loading={summary.isLoading} value={formatInt(s?.total_requisitions as number)} hint={`${formatInt(s?.requisition_lines as number)} lines`} />
        <StatCard label="Unique items" icon={Layers} loading={summary.isLoading} value={formatInt(s?.unique_items as number)} />
        <StatCard label="Requested qty" icon={ClipboardList} loading={summary.isLoading} value={formatQty(s?.requested_quantity)} />
        <StatCard label="Pending purchase" icon={ShoppingCart} loading={summary.isLoading} value={formatQty(s?.pending_purchase_quantity)} />
        <StatCard label="Purchased qty" icon={PackageCheck} loading={summary.isLoading} value={formatQty(s?.purchased_quantity)} />
        <StatCard label="Delivered qty" icon={Truck} loading={summary.isLoading} value={formatQty(s?.delivered_quantity)} />
      </div>
      {can('finance.view') && (
        <div className="grid gap-3 sm:grid-cols-3">
          <StatCard label="Total purchased amount" icon={Banknote} loading={summary.isLoading} value={formatMoney(s?.total_purchased_amount, symbol)} />
          <StatCard label="Total paid amount" icon={HandCoins} loading={summary.isLoading} value={formatMoney(s?.total_paid_amount, symbol)} />
          <StatCard label="Total outstanding" icon={Scale} loading={summary.isLoading} value={formatMoney(s?.total_outstanding_amount, symbol)} hint="As of the end of the period" />
        </div>
      )}

      <div className="grid gap-4 lg:grid-cols-2">
        <BarChartCard title="Daily requisitions" description="Confirmed requisitions by requisition date" loading={charts.isLoading}
          data={rows('daily_requisitions')?.map((r) => ({ ...r, period: formatDate(String(r.period)).slice(0, 6) }))} category="period" value="requisitions" valueLabel="Requisitions" format={(v) => formatInt(v)} />
        <BarChartCard title="Monthly requisitions" loading={charts.isLoading} data={rows('monthly_requisitions')} category="period" value="requisitions" valueLabel="Requisitions" format={(v) => formatInt(v)} />
        <BarChartCard title="Top items by requested quantity" description="In each item's default unit" horizontal loading={charts.isLoading}
          data={rows('item_quantities')} category="name" value="requested" valueLabel="Requested" format={(v) => formatQty(v)} />
        <BarChartCard title="Pending vs purchased vs delivered" description="Approved quantity: pending + on order + purchased; delivered is part of purchased" horizontal loading={charts.isLoading}
          data={fulfilment ? [
            { stage: 'Pending', quantity: fulfilment.pending }, { stage: 'On order', quantity: fulfilment.on_order },
            { stage: 'Purchased', quantity: fulfilment.purchased }, { stage: 'Delivered', quantity: fulfilment.delivered },
          ] : undefined} category="stage" value="quantity" valueLabel="Quantity" format={(v) => formatQty(v)} height={200} />
        {can('finance.view') && <>
          <BarChartCard title="Monthly purchase amount" description="By purchase date" loading={charts.isLoading} data={rows('monthly_purchase_amount')} category="period" value="amount" valueLabel="Purchase amount" format={money} />
          <BarChartCard title="Payments by month" description="By payment date" loading={charts.isLoading} data={rows('monthly_payments')} category="period" value="amount" valueLabel="Paid" format={money} />
          <BarChartCard title="Purchase amount by vendor" horizontal loading={charts.isLoading} data={rows('vendor_purchase_amount')} category="vendor" value="amount" valueLabel="Purchase amount" format={money} />
          <BarChartCard title="Outstanding by vendor" description="As of the end of the period" horizontal loading={charts.isLoading} color="var(--chart-2)"
            data={rows('vendor_outstanding')} category="vendor" value="amount" valueLabel="Outstanding" format={money} />
        </>}
      </div>

      <div className="grid gap-4 lg:grid-cols-3">
        {Object.entries(LABELS).filter(([k]) => recent.data?.[k]).map(([key, label]) => (
          <Card key={key}>
            <CardHeader><CardTitle className="text-base">{label}</CardTitle></CardHeader>
            <CardContent>
              <ul className="space-y-2 text-sm">
                {(recent.data?.[key] as Recent[]).map((r) => (
                  <li key={r.id} className="flex items-center gap-2">
                    <Link className="font-mono text-xs hover:underline" to={`${LINKS[key]}/${r.id}`}>{r.reference}</Link>
                    <span className="min-w-0 flex-1 truncate text-muted-foreground">{r.amount ? formatMoney(r.amount, symbol) : r.title}</span>
                    <StatusBadge status={r.status} />
                  </li>
                ))}
                {!(recent.data?.[key] as Recent[]).length && <li className="text-muted-foreground">Nothing yet.</li>}
              </ul>
            </CardContent>
          </Card>
        ))}
        <Card>
          <CardHeader><CardTitle className="text-base">Recent status updates</CardTitle></CardHeader>
          <CardContent>
            <ul className="space-y-2 text-sm">
              {(recent.data?.status_updates as StatusUpdate[] | undefined)?.map((u) => (
                <li key={u.id}><div>{u.description}</div><div className="text-xs text-muted-foreground">{formatDateTime(u.at)}{u.user && ` · ${u.user}`}</div></li>
              ))}
              {recent.data && !(recent.data.status_updates as StatusUpdate[]).length && <li className="text-muted-foreground">Nothing yet.</li>}
            </ul>
          </CardContent>
        </Card>
      </div>
    </>
  )
}
