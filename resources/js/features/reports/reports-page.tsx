import { keepPreviousData, useQuery } from '@tanstack/react-query'
import { useMemo } from 'react'
import { AsyncCombobox } from '@/components/async-combobox'
import { DataTable, type DataTableColumn } from '@/components/data-table'
import { DateRangeFilter } from '@/components/date-range-filter'
import { PageHeader } from '@/components/page-header'
import { Card, CardContent } from '@/components/ui/card'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group'
import type { Vendor } from '@/features/catalog/api'
import { ExportButton } from '@/features/reports/export-button'
import { useListParams } from '@/hooks/use-list-params'
import { api, type PageMeta } from '@/lib/api'
import { formatDate, formatInt, formatMoney, formatQty, titleCase } from '@/lib/format'
import { useSettings } from '@/lib/settings'

type Column = { key: string; label: string; type: 'money' | 'qty' | 'int' | 'date' | 'text' }
type ReportPage = { data: Record<string, string | number | null>[]; meta: PageMeta; totals: Record<string, string>; report: { title: string; date_basis: string; range: { from: string; to: string } | null; columns: Column[] } }
type ReportInfo = { key: string; title: string; date_basis: string; financial: boolean }

const GROUPED = ['requested-quantity', 'purchase-amount']
const BY_VENDOR = ['purchased-quantity', 'deliveries', 'purchase-amount', 'vendor-payments']
const FILTERS = ['period', 'date_from', 'date_to', 'group_by', 'date_basis', 'vendor_id', 'sort']

export function ReportsPage() {
  const symbol = useSettings().data?.currency_symbol
  const { params, set, page } = useListParams({ report: 'items', period: 'this_month' })
  const reports = useQuery({ queryKey: ['reports'], queryFn: async () => (await api.get<{ data: ReportInfo[] }>('/reports')).data })
  const key = params.report
  const filters = Object.fromEntries(FILTERS.filter((k) => params[k]).map((k) => [k, params[k]]))
  const data = useQuery({
    queryKey: ['reports', key, filters, page],
    queryFn: () => api.get<ReportPage>(`/reports/${key}`, { ...filters, page, per_page: 50 }),
    placeholderData: keepPreviousData,
  })

  const fmt = (c: Column, v: string | number | null | undefined) =>
    v === null || v === undefined ? '—' : c.type === 'money' ? formatMoney(v, symbol) : c.type === 'qty' ? formatQty(v) : c.type === 'int' ? formatInt(Number(v))
      : c.type === 'date' ? formatDate(String(v)) : String(v).includes('_') && /status/.test(c.key) ? titleCase(String(v)) : String(v)

  const columns = useMemo<DataTableColumn<Record<string, string | number | null>>[]>(() => (data.data?.report.columns ?? []).map((c) => ({
    id: c.key, header: c.label, meta: { sortKey: c.key, align: ['money', 'qty', 'int'].includes(c.type) ? 'right' : undefined },
    cell: ({ row }) => fmt(c, row.original[c.key]),
  })), [data.data?.report.columns, symbol]) // eslint-disable-line react-hooks/exhaustive-deps

  const totals = data.data?.totals ?? {}
  return (
    <>
      <PageHeader title="Reports" description="Date-wise reports. Each report filters on its own business date."
        actions={<ExportButton report={key} filters={filters} />} />
      <div className="flex flex-wrap items-center gap-2">
        <Select value={key} onValueChange={(v) => set({ report: v, sort: null, group_by: null, date_basis: null, vendor_id: null })}>
          <SelectTrigger className="w-64" aria-label="Report"><SelectValue /></SelectTrigger>
          <SelectContent>{reports.data?.map((r) => <SelectItem key={r.key} value={r.key}>{r.title}</SelectItem>)}</SelectContent>
        </Select>
        <DateRangeFilter value={params} onChange={(v) => set(v)} />
        {GROUPED.includes(key) && (
          <ToggleGroup type="single" variant="outline" size="sm" value={params.group_by ?? 'day'} onValueChange={(v) => v && set({ group_by: v === 'day' ? null : v })}>
            <ToggleGroupItem value="day">Daily</ToggleGroupItem><ToggleGroupItem value="month">Monthly</ToggleGroupItem>
          </ToggleGroup>
        )}
        {key === 'requisitions' && (
          <ToggleGroup type="single" variant="outline" size="sm" value={params.date_basis ?? 'requisition_date'} onValueChange={(v) => v && set({ date_basis: v === 'requisition_date' ? null : v })}>
            <ToggleGroupItem value="requisition_date">Requisition date</ToggleGroupItem><ToggleGroupItem value="uploaded_at">Upload date</ToggleGroupItem>
          </ToggleGroup>
        )}
        {BY_VENDOR.includes(key) && (
          <div className="w-56">
            <AsyncCombobox<Vendor> endpoint="/vendors" toOption={(v) => ({ value: v.id, label: v.name })} placeholder="All vendors"
              value={params.vendor_id ? { value: Number(params.vendor_id), label: params.vendor_name ?? 'Vendor' } : null}
              onChange={(o) => set({ vendor_id: o?.value ?? null, vendor_name: o?.label ?? null })} />
          </div>
        )}
      </div>
      {data.data && (
        <p className="text-sm text-muted-foreground">
          Date basis: <span className="font-medium text-foreground">{data.data.report.date_basis}</span>
          {data.data.report.range ? ` · ${formatDate(data.data.report.range.from)} – ${formatDate(data.data.report.range.to)}` : ' · All dates'}
        </p>
      )}
      {Object.keys(totals).length > 0 && (
        <Card className="py-3">
          <CardContent className="flex flex-wrap gap-x-8 gap-y-2 px-4 text-sm">
            {data.data?.report.columns.filter((c) => c.key in totals).map((c) => (
              <div key={c.key}><span className="text-muted-foreground">{c.label}: </span><span className="font-semibold tabular-nums">{fmt(c, totals[c.key])}</span></div>
            ))}
          </CardContent>
        </Card>
      )}
      <DataTable columns={columns} data={data.data?.data} meta={data.data?.meta} isLoading={data.isLoading} sort={params.sort}
        onSortChange={(sort) => set({ sort })} onPageChange={(p) => set({ page: p })} emptyTitle="No rows for these filters" />
    </>
  )
}
