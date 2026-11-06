import { ArrowLeft, Pencil, Plus, Trash2, X } from 'lucide-react'
import { useState } from 'react'
import { Link, useParams } from 'react-router'
import { toast } from 'sonner'
import { ConfirmDialog } from '@/components/confirm-dialog'
import { PageHeader } from '@/components/page-header'
import { StatusBadge } from '@/components/status-badge'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Input } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/skeleton'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { useQuery } from '@tanstack/react-query'
import { useAliasMutations, useConversionMutations, useConversions, useItem } from '@/features/catalog/api'
import { api } from '@/lib/api'
import { formatDate, formatMoney } from '@/lib/format'
import { useSettings } from '@/lib/settings'
import { ItemFormSheet } from '@/features/items/item-form-sheet'
import { ConversionDialog } from '@/features/master-data/conversion-dialog'
import { useAuth } from '@/lib/auth'
import { errorMessage } from '@/lib/forms'
import { formatQty } from '@/lib/format'

type PriceRow = { purchase_order: { id: number; po_number: string }; vendor: { id: number; name: string }; purchase_date: string; quantity: string; unit_price: string }

function PurchaseHistory({ itemId, unit }: { itemId: number; unit: string }) {
  const symbol = useSettings().data?.currency_symbol
  const { data } = useQuery({ queryKey: ['items', itemId, 'purchase-history'], queryFn: async () => (await api.get<{ data: PriceRow[] }>(`/items/${itemId}/purchase-history`)).data })
  return (
    <Card>
      <CardHeader><CardTitle>Purchase history</CardTitle><CardDescription>Prices paid per vendor, latest first.</CardDescription></CardHeader>
      <CardContent>
        <Table>
          <TableHeader><TableRow><TableHead>Date</TableHead><TableHead>Vendor</TableHead><TableHead>Purchase order</TableHead><TableHead className="text-right">Quantity</TableHead><TableHead className="text-right">Unit price</TableHead></TableRow></TableHeader>
          <TableBody>
            {data?.map((r) => (
              <TableRow key={`${r.purchase_order.id}`}>
                <TableCell>{formatDate(r.purchase_date)}</TableCell>
                <TableCell><Link className="hover:underline" to={`/vendors/${r.vendor.id}`}>{r.vendor.name}</Link></TableCell>
                <TableCell><Link className="font-mono text-xs hover:underline" to={`/purchase-orders/${r.purchase_order.id}`}>{r.purchase_order.po_number}</Link></TableCell>
                <TableCell className="text-right tabular-nums">{formatQty(r.quantity)} {unit}</TableCell>
                <TableCell className="text-right tabular-nums">{formatMoney(r.unit_price, symbol)}</TableCell>
              </TableRow>
            ))}
            {data && !data.length && <TableRow><TableCell colSpan={5} className="text-muted-foreground">Not purchased yet.</TableCell></TableRow>}
          </TableBody>
        </Table>
      </CardContent>
    </Card>
  )
}

export function ItemDetailPage() {
  const id = Number(useParams().id)
  const { can } = useAuth()
  const { data: item, isLoading } = useItem(id)
  const globals = useConversions({ scope: 'global' })
  const aliases = useAliasMutations(id)
  const conversions = useConversionMutations()
  const [alias, setAlias] = useState('')

  if (isLoading || !item) return <Skeleton className="h-64 w-full" />

  // Rules that can convert into this item's default unit: its own, plus global ones it doesn't override.
  const own = item.conversions ?? []
  const applicableGlobals = (globals.data ?? []).filter(
    (g) => g.to_unit.id === item.default_unit.id && !own.some((o) => o.from_unit.id === g.from_unit.id && o.to_unit.id === g.to_unit.id),
  )

  const addAlias = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!alias.trim()) return
    try {
      await aliases.add.mutateAsync(alias.trim())
      setAlias('')
      toast.success('Alias added')
    } catch (err) {
      toast.error(errorMessage(err))
    }
  }

  return (
    <>
      <Button variant="ghost" size="sm" className="-ml-2 w-fit" asChild>
        <Link to="/items"><ArrowLeft /> Items</Link>
      </Button>
      <PageHeader
        title={item.name}
        description={`${item.code}${item.sku ? ` · SKU ${item.sku}` : ''}`}
        actions={<>
          <StatusBadge status={item.is_active ? 'active' : 'inactive'} />
          {can('items.manage') && <ItemFormSheet item={item} trigger={<Button variant="outline"><Pencil /> Edit</Button>} />}
        </>}
      />
      <div className="grid gap-6 lg:grid-cols-3">
        <Card>
          <CardHeader><CardTitle>Details</CardTitle></CardHeader>
          <CardContent>
            <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-sm">
              <dt className="text-muted-foreground">Category</dt><dd>{item.category?.name ?? '—'}</dd>
              <dt className="text-muted-foreground">Default unit</dt><dd>{item.default_unit.name}</dd>
              <dt className="text-muted-foreground">Description</dt><dd className="whitespace-pre-line">{item.description || '—'}</dd>
            </dl>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Aliases</CardTitle>
            <CardDescription>Other names that should match this item automatically.</CardDescription>
          </CardHeader>
          <CardContent className="space-y-4">
            <div className="flex flex-wrap gap-2">
              {item.aliases?.length ? item.aliases.map((a) => (
                <Badge key={a.id} variant="secondary" className="gap-1 pr-1">
                  {a.alias}
                  {can('items.manage') && (
                    <button type="button" className="rounded-sm p-0.5 hover:bg-background" aria-label={`Remove alias ${a.alias}`}
                      onClick={() => aliases.remove.mutate(a.id, { onError: (e) => toast.error(errorMessage(e)) })}>
                      <X className="size-3" />
                    </button>
                  )}
                </Badge>
              )) : <p className="text-sm text-muted-foreground">No aliases yet.</p>}
            </div>
            {can('items.manage') && (
              <form onSubmit={addAlias} className="flex gap-2">
                <Input value={alias} onChange={(e) => setAlias(e.target.value)} placeholder="Add alias" maxLength={190} aria-label="New alias" />
                <Button type="submit" variant="outline" disabled={aliases.add.isPending}>Add</Button>
              </form>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="flex-row items-start justify-between">
            <div className="space-y-1.5">
              <CardTitle>Unit conversions</CardTitle>
              <CardDescription>Into {item.default_unit.name}. Lines in other units can't be verified without one.</CardDescription>
            </div>
            {can('master_data.manage') && (
              <ConversionDialog itemId={item.id} itemName={item.name} toUnitId={item.default_unit.id}
                trigger={<Button variant="outline" size="icon" aria-label="Add conversion"><Plus /></Button>} />
            )}
          </CardHeader>
          <CardContent>
            <Table>
              <TableHeader><TableRow><TableHead>Rule</TableHead><TableHead>Scope</TableHead><TableHead /></TableRow></TableHeader>
              <TableBody>
                {[...own.map((c) => ({ c, scope: 'Item' })), ...applicableGlobals.map((c) => ({ c, scope: 'Global' }))].map(({ c, scope }) => (
                  <TableRow key={c.id}>
                    <TableCell>1 {c.from_unit.name} = {formatQty(c.factor)} {c.to_unit.name}</TableCell>
                    <TableCell><Badge variant="outline">{scope}</Badge></TableCell>
                    <TableCell className="w-10">
                      {scope === 'Item' && can('master_data.manage') && (
                        <ConfirmDialog
                          trigger={<Button variant="ghost" size="icon" className="size-7" aria-label="Delete conversion"><Trash2 /></Button>}
                          title="Delete conversion?"
                          description="Requisitions verified earlier keep the factor they were verified with."
                          destructive
                          confirmLabel="Delete"
                          onConfirm={() => conversions.remove.mutateAsync(c.id).catch((e) => toast.error(errorMessage(e)))}
                        />
                      )}
                    </TableCell>
                  </TableRow>
                ))}
                {!own.length && !applicableGlobals.length && (
                  <TableRow><TableCell colSpan={3} className="text-muted-foreground">Only {item.default_unit.name} is accepted.</TableCell></TableRow>
                )}
              </TableBody>
            </Table>
          </CardContent>
        </Card>
      </div>
      {can('purchases.view') && <PurchaseHistory itemId={item.id} unit={item.default_unit.name} />}
    </>
  )
}
