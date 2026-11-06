import { useQuery } from '@tanstack/react-query'
import { ArrowLeft, ListFilter, Plus, Save, Trash2 } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router'
import { toast } from 'sonner'
import { AsyncCombobox, type Option } from '@/components/async-combobox'
import { DatePicker } from '@/components/date-range-filter'
import { PageHeader } from '@/components/page-header'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Checkbox } from '@/components/ui/checkbox'
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from '@/components/ui/command'
import { Field, FieldGroup, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover'
import { Skeleton } from '@/components/ui/skeleton'
import { Spinner } from '@/components/ui/spinner'
import { Table, TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Textarea } from '@/components/ui/textarea'
import type { SourceLine } from '@/features/analyzer/api'
import type { Vendor } from '@/features/catalog/api'
import { useAvailableItems, usePurchaseOrder, useSavePurchaseOrder, type AvailableItem, type PurchaseOrder } from '@/features/purchases/api'
import { api, ApiError } from '@/lib/api'
import { errorMessage } from '@/lib/forms'
import { addQty, compareQty, lineTotal, sumMoney } from '@/lib/decimal'
import { formatDate, formatMoney, formatQty } from '@/lib/format'
import { useSettings } from '@/lib/settings'

type Line = {
  item: AvailableItem['item']
  available: string // pending + what this draft already holds
  quantity: string
  unit_price: string
  discount_amount: string
  tax_amount: string
  requisition_item_ids: number[] | null
}

const today = () => new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Dhaka' }).format(new Date())
const money = /^\d+(\.\d{1,2})?$/

function RequisitionPicker({ itemId, value, onChange }: { itemId: number; value: number[] | null; onChange: (ids: number[] | null) => void }) {
  const { data } = useQuery({
    queryKey: ['purchase-orders', 'available', 'lines', itemId],
    queryFn: async () => (await api.get<{ data: SourceLine[] }>(`/purchase-orders/available-items/${itemId}`)).data,
  })
  const label = value ? `${value.length} requisition${value.length === 1 ? '' : 's'}` : 'Oldest first'
  return (
    <Popover>
      <PopoverTrigger asChild><Button type="button" variant="ghost" size="sm" className="h-7 px-2 text-xs"><ListFilter /> {label}</Button></PopoverTrigger>
      <PopoverContent className="w-96" align="start">
        <p className="mb-2 text-sm text-muted-foreground">Fill from specific requisitions, or leave all unticked to fill the oldest requests first.</p>
        <div className="max-h-64 space-y-1 overflow-y-auto">
          {data?.map((l) => {
            const checked = value?.includes(l.line_id) ?? false
            return (
              <label key={l.line_id} className="flex items-center gap-2 rounded-md p-1.5 text-sm hover:bg-muted">
                <Checkbox checked={checked} onCheckedChange={(c) => {
                  const next = c ? [...(value ?? []), l.line_id] : (value ?? []).filter((id) => id !== l.line_id)
                  onChange(next.length ? next : null)
                }} />
                <span className="font-mono text-xs">{l.requisition.reference_no}</span>
                <span className="text-muted-foreground">{formatDate(l.requisition.requisition_date)}</span>
                <span className="ml-auto tabular-nums">{formatQty(l.pending)} pending</span>
              </label>
            )
          })}
        </div>
      </PopoverContent>
    </Popover>
  )
}

export function PurchaseOrderFormPage() {
  const id = Number(useParams().id) || undefined
  const existing = usePurchaseOrder(id ?? 0)
  if (id && !existing.data) return <Skeleton className="h-96" />
  return <PurchaseOrderForm existing={existing.data} />
}

function PurchaseOrderForm({ existing }: { existing?: PurchaseOrder }) {
  const navigate = useNavigate()
  const [search] = useSearchParams()
  const symbol = useSettings().data?.currency_symbol
  const preselect = useMemo(() => search.get('items')?.split(',').map(Number).filter(Boolean) ?? [], [search])
  const available = useAvailableItems()
  const save = useSavePurchaseOrder(existing?.id)

  const [vendor, setVendor] = useState<Option | null>(existing ? { value: existing.vendor.id, label: existing.vendor.name } : null)
  const [orderDate, setOrderDate] = useState<string | null>(existing?.order_date ?? today())
  const [charges, setCharges] = useState(existing ? String(Number(existing.additional_charges)) : '0')
  const [notes, setNotes] = useState(existing?.notes ?? '')
  const [lines, setLines] = useState<Line[]>([])
  const [errors, setErrors] = useState<Record<string, string[]>>({})
  const [pickerOpen, setPickerOpen] = useState(false)

  // Initialise once the pending list is known: from an existing draft, or from analyzer selections.
  useEffect(() => {
    if (!available.data || lines.length) return
    const pending = new Map(available.data.map((a) => [a.item.id, a]))
    if (existing?.lines) {
      setLines(existing.lines.map((l) => ({
        item: { ...l.item, category: null },
        available: addQty(pending.get(l.item.id)?.pending ?? '0', l.ordered_quantity),
        quantity: String(Number(l.ordered_quantity)), unit_price: String(Number(l.unit_price)),
        discount_amount: String(Number(l.discount_amount)), tax_amount: String(Number(l.tax_amount)), requisition_item_ids: null,
      })))
    } else if (preselect.length) {
      setLines(available.data.filter((a) => preselect.includes(a.item.id)).map((a) => ({
        item: a.item, available: a.pending, quantity: String(Number(a.pending)), unit_price: '', discount_amount: '0', tax_amount: '0', requisition_item_ids: null,
      })))
    }
  }, [available.data, existing, preselect, lines.length])

  const update = (i: number, patch: Partial<Line>) => setLines(lines.map((l, j) => (j === i ? { ...l, ...patch } : l)))
  const totals = lines.map((l) => (money.test(l.unit_price) && /^\d+(\.\d{1,3})?$/.test(l.quantity) ? lineTotal(l.quantity, l.unit_price, l.discount_amount || '0', l.tax_amount || '0') : '0.00'))
  const subtotal = sumMoney(totals)
  const grand = sumMoney([subtotal, money.test(charges) ? charges : '0'])
  const remaining = (available.data ?? []).filter((a) => !lines.some((l) => l.item.id === a.item.id))

  const problems = lines.map((l) =>
    !/^\d+(\.\d{1,3})?$/.test(l.quantity) || Number(l.quantity) <= 0 ? 'Enter a quantity'
      : compareQty(l.quantity, l.available) > 0 ? `Only ${formatQty(l.available)} pending`
        : !/^\d+(\.\d{1,4})?$/.test(l.unit_price) ? 'Enter a unit price' : null)

  const submit = async () => {
    setErrors({})
    try {
      const po = await save.mutateAsync({
        vendor_id: vendor!.value, order_date: orderDate!, additional_charges: charges || '0', notes: notes || null,
        lines: lines.map((l) => ({ item_id: l.item.id, quantity: l.quantity, unit_price: l.unit_price, discount_amount: l.discount_amount || '0', tax_amount: l.tax_amount || '0', requisition_item_ids: l.requisition_item_ids })),
      })
      toast.success(`${po.po_number} saved as draft`)
      navigate(`/purchase-orders/${po.id}`)
    } catch (e) {
      if (e instanceof ApiError) setErrors(e.errors)
      toast.error(errorMessage(e))
    }
  }

  const lineError = (i: number) => Object.entries(errors).filter(([k]) => k.startsWith(`lines.${i}.`)).flatMap(([, v]) => v)[0]

  return (
    <>
      <Button variant="ghost" size="sm" className="-ml-2 w-fit" asChild>
        <Link to={existing ? `/purchase-orders/${existing.id}` : '/purchase-orders'}><ArrowLeft /> {existing ? existing.po_number : 'Purchase orders'}</Link>
      </Button>
      <PageHeader title={existing ? `Edit ${existing.po_number}` : 'New purchase order'}
        description="Quantities are taken from approved requisitions that are still pending, in each item's default unit."
        actions={<Button onClick={submit} disabled={!vendor || !orderDate || !lines.length || problems.some(Boolean) || save.isPending}>
          {save.isPending ? <Spinner /> : <Save />} Save draft</Button>} />

      <Card>
        <CardContent>
          <FieldGroup className="grid gap-4 md:grid-cols-3">
            <Field data-invalid={!!errors.vendor_id}>
              <FieldLabel>Vendor</FieldLabel>
              <AsyncCombobox<Vendor> endpoint="/vendors" params={{ is_active: 1 }} toOption={(v) => ({ value: v.id, label: v.name, hint: v.code })}
                value={vendor} onChange={setVendor} placeholder="Choose vendor" invalid={!!errors.vendor_id} />
            </Field>
            <Field><FieldLabel>Order date</FieldLabel><DatePicker value={orderDate} onChange={setOrderDate} /></Field>
            <Field>
              <FieldLabel htmlFor="charges">Additional charges ({symbol})</FieldLabel>
              <Input id="charges" inputMode="decimal" value={charges} onChange={(e) => setCharges(e.target.value)} aria-invalid={!money.test(charges)} placeholder="Transport, labour…" />
            </Field>
          </FieldGroup>
        </CardContent>
      </Card>

      <Card>
        <CardHeader className="flex-row items-center justify-between">
          <CardTitle>Items</CardTitle>
          <Popover open={pickerOpen} onOpenChange={setPickerOpen}>
            <PopoverTrigger asChild><Button variant="outline" size="sm" disabled={!remaining.length}><Plus /> Add item</Button></PopoverTrigger>
            <PopoverContent className="w-96 p-0" align="end">
              <Command>
                <CommandInput placeholder="Search pending items…" />
                <CommandList>
                  <CommandEmpty>No pending items.</CommandEmpty>
                  <CommandGroup>
                    {remaining.map((a) => (
                      <CommandItem key={a.item.id} value={`${a.item.name} ${a.item.code}`} onSelect={() => {
                        setLines([...lines, { item: a.item, available: a.pending, quantity: String(Number(a.pending)), unit_price: '', discount_amount: '0', tax_amount: '0', requisition_item_ids: null }])
                        setPickerOpen(false)
                      }}>
                        <span className="flex-1">{a.item.name}</span>
                        <span className="text-xs text-muted-foreground">{formatQty(a.pending)} {a.item.unit} pending</span>
                      </CommandItem>
                    ))}
                  </CommandGroup>
                </CommandList>
              </Command>
            </PopoverContent>
          </Popover>
        </CardHeader>
        <CardContent className="overflow-x-auto">
          {available.isLoading ? <Skeleton className="h-24" /> : (
            <Table>
              <TableHeader>
                <TableRow>
                  <TableHead className="min-w-48">Item</TableHead><TableHead className="w-28 text-right">Pending</TableHead>
                  <TableHead className="w-28">Quantity</TableHead><TableHead className="w-32">Unit price</TableHead>
                  <TableHead className="w-28">Discount</TableHead><TableHead className="w-28">Tax</TableHead>
                  <TableHead className="w-32 text-right">Line total</TableHead><TableHead className="w-10" />
                </TableRow>
              </TableHeader>
              <TableBody>
                {lines.map((l, i) => (
                  <TableRow key={l.item.id} className="align-top">
                    <TableCell>
                      <div className="font-medium">{l.item.name}</div>
                      <RequisitionPicker itemId={l.item.id} value={l.requisition_item_ids} onChange={(ids) => update(i, { requisition_item_ids: ids })} />
                      {(problems[i] || lineError(i)) && <p className="text-xs text-destructive">{lineError(i) ?? problems[i]}</p>}
                    </TableCell>
                    <TableCell className="pt-4 text-right tabular-nums">{formatQty(l.available)} {l.item.unit}</TableCell>
                    <TableCell><Input inputMode="decimal" className="text-right tabular-nums" aria-label={`Quantity of ${l.item.name}`} value={l.quantity} onChange={(e) => update(i, { quantity: e.target.value })} /></TableCell>
                    <TableCell><Input inputMode="decimal" className="text-right tabular-nums" aria-label={`Unit price of ${l.item.name}`} value={l.unit_price} onChange={(e) => update(i, { unit_price: e.target.value })} /></TableCell>
                    <TableCell><Input inputMode="decimal" className="text-right tabular-nums" aria-label={`Discount on ${l.item.name}`} value={l.discount_amount} onChange={(e) => update(i, { discount_amount: e.target.value })} /></TableCell>
                    <TableCell><Input inputMode="decimal" className="text-right tabular-nums" aria-label={`Tax on ${l.item.name}`} value={l.tax_amount} onChange={(e) => update(i, { tax_amount: e.target.value })} /></TableCell>
                    <TableCell className="pt-4 text-right font-medium tabular-nums">{formatMoney(totals[i], symbol)}</TableCell>
                    <TableCell><Button variant="ghost" size="icon" className="size-8" aria-label={`Remove ${l.item.name}`} onClick={() => setLines(lines.filter((_, j) => j !== i))}><Trash2 /></Button></TableCell>
                  </TableRow>
                ))}
                {!lines.length && <TableRow><TableCell colSpan={8} className="py-8 text-center text-muted-foreground">Add items with pending approved quantity.</TableCell></TableRow>}
              </TableBody>
              {lines.length > 0 && (
                <TableFooter>
                  <TableRow><TableCell colSpan={6} className="text-right">Subtotal</TableCell><TableCell className="text-right tabular-nums">{formatMoney(subtotal, symbol)}</TableCell><TableCell /></TableRow>
                  <TableRow><TableCell colSpan={6} className="text-right">Grand total</TableCell><TableCell className="text-right font-semibold tabular-nums">{formatMoney(grand, symbol)}</TableCell><TableCell /></TableRow>
                </TableFooter>
              )}
            </Table>
          )}
          {!available.isLoading && !available.data?.length && !existing && (
            <Alert className="mt-4"><AlertDescription>Nothing is pending purchase. Approve requisitions first.</AlertDescription></Alert>
          )}
        </CardContent>
      </Card>

      <Card>
        <CardContent><Field><FieldLabel htmlFor="po-notes">Notes</FieldLabel><Textarea id="po-notes" rows={2} value={notes} onChange={(e) => setNotes(e.target.value)} maxLength={2000} /></Field></CardContent>
      </Card>
    </>
  )
}
