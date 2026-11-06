import { Bar, BarChart, CartesianGrid, XAxis, YAxis } from 'recharts'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { ChartContainer, ChartTooltip, ChartTooltipContent, type ChartConfig } from '@/components/ui/chart'
import { Skeleton } from '@/components/ui/skeleton'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'

type Row = Record<string, string | number | null>

const compact = new Intl.NumberFormat('en-IN', { notation: 'compact', maximumFractionDigits: 1 })

/**
 * Single-series bar chart with a table view (identity never relies on color; the title names
 * the series, so no legend). Horizontal for categories, vertical for time.
 */
export function BarChartCard({ title, description, data, category, value, valueLabel, color = 'var(--chart-1)', horizontal, loading, format = (v) => String(v), height = 260 }: {
  title: string
  description?: string
  data: Row[] | undefined
  category: string
  value: string
  valueLabel: string
  color?: string
  horizontal?: boolean
  loading?: boolean
  format?: (v: number) => string
  height?: number
}) {
  const config = { [value]: { label: valueLabel, color } } satisfies ChartConfig
  const rows = (data ?? []).map((r) => ({ ...r, [value]: Number(r[value] ?? 0) }))

  return (
    <Card>
      <Tabs defaultValue="chart">
        <CardHeader className="flex-row items-start justify-between gap-2">
          <div className="space-y-1.5">
            <CardTitle>{title}</CardTitle>
            {description && <CardDescription>{description}</CardDescription>}
          </div>
          <TabsList className="h-8">
            <TabsTrigger value="chart" className="text-xs">Chart</TabsTrigger>
            <TabsTrigger value="table" className="text-xs">Table</TabsTrigger>
          </TabsList>
        </CardHeader>
        <CardContent>
          {loading ? <Skeleton style={{ height }} /> : !rows.length ? (
            <div className="flex items-center justify-center text-sm text-muted-foreground" style={{ height }}>No data for this period.</div>
          ) : (
            <>
              <TabsContent value="chart">
                <ChartContainer config={config} className="w-full" style={{ height }}>
                  <BarChart data={rows} layout={horizontal ? 'vertical' : 'horizontal'} margin={{ left: 4, right: 12, top: 4, bottom: 4 }} barCategoryGap={horizontal ? 6 : '20%'}>
                    <CartesianGrid vertical={horizontal} horizontal={!horizontal} strokeDasharray="3 3" strokeOpacity={0.5} />
                    {horizontal ? (
                      <>
                        <XAxis type="number" tickLine={false} axisLine={false} tickFormatter={(v) => compact.format(v)} fontSize={11} />
                        <YAxis type="category" dataKey={category} tickLine={false} axisLine={false} width={120} fontSize={11}
                          tickFormatter={(v: string) => (v.length > 18 ? `${v.slice(0, 17)}…` : v)} />
                      </>
                    ) : (
                      <>
                        <XAxis dataKey={category} tickLine={false} axisLine={false} fontSize={11} minTickGap={16} />
                        <YAxis tickLine={false} axisLine={false} tickFormatter={(v) => compact.format(v)} width={48} fontSize={11} />
                      </>
                    )}
                    <ChartTooltip cursor={{ fillOpacity: 0.3 }} content={<ChartTooltipContent formatter={(v) => (
                      <div className="flex w-full justify-between gap-4"><span className="text-muted-foreground">{valueLabel}</span><span className="font-mono font-medium tabular-nums">{format(Number(v))}</span></div>
                    )} />} />
                    <Bar dataKey={value} fill={`var(--color-${value})`} radius={horizontal ? [0, 4, 4, 0] : [4, 4, 0, 0]} maxBarSize={horizontal ? 22 : 36} />
                  </BarChart>
                </ChartContainer>
              </TabsContent>
              <TabsContent value="table">
                <div className="overflow-y-auto" style={{ maxHeight: height }}>
                  <Table>
                    <TableHeader><TableRow><TableHead>{horizontal ? 'Name' : 'Period'}</TableHead><TableHead className="text-right">{valueLabel}</TableHead></TableRow></TableHeader>
                    <TableBody>
                      {rows.map((r, i) => (
                        <TableRow key={i}><TableCell>{r[category]}</TableCell><TableCell className="text-right tabular-nums">{format(r[value] as number)}</TableCell></TableRow>
                      ))}
                    </TableBody>
                  </Table>
                </div>
              </TabsContent>
            </>
          )}
        </CardContent>
      </Tabs>
    </Card>
  )
}
