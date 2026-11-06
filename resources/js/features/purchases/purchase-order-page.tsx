import { ArrowLeft, CheckCheck, CircleCheckBig, Pencil, Send, ShoppingBag, XCircle } from 'lucide-react'
import { useState } from 'react'
import { Link, useParams } from 'react-router'
import { toast } from 'sonner'
import { ConfirmDialog } from '@/components/confirm-dialog'
import { DatePicker } from '@/components/date-range-filter'
import { PageHeader } from '@/components/page-header'
import { StatusBadge } from '@/components/status-badge'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog'
import { Field, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Skeleton } from '@/components/ui/skeleton'
import { Spinner } from '@/components/ui/spinner'
import { Table, TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { PurchaseOrderDeliveries } from '@/features/deliveries/purchase-order-deliveries'
import { PURCHASED, usePurchaseOrder, usePurchaseOrderAction, type PurchaseOrder } from '@/features/purchases/api'
import { useAuth } from '@/lib/auth'
import { compareQty, lineTotal, sumMoney } from '@/lib/decimal'
import { errorMessage } from '@/lib/forms'
import { formatDate, formatDateTime, formatMoney, formatQty, titleCase } from '@/lib/format'
import { useSettings } from '@/lib/settings'

export function PurchaseOrderPage() {
  const id = Number(useParams().id)
  const { data: po, isLoading } = usePurchaseOrder(id)
  if (isLoading || !po) return <Skeleton className="h-96 w-full" />
  return <PurchaseOrderView po={po} />
}

function PurchaseOrderView({ po }: { po: PurchaseOrder }) {
  const { can } = useAuth()
  const symbol = useSettings().data?.currency_symbol
  const actions = usePurchaseOrderAction(po.id)
  const run = (m: { mutateAsync: (b?: unknown) => Promise<unknown> }, message: string, body?: unknown) =>
    m.mutateAsync(body).then(() => toast.success(message)).catch((e) => toast.error(errorMessage(e)))
  const purchased = PURCHASED.includes(po.status)
  const cancellable = ['draft', 'pending_approval', 'approved', 'purchased', 'partially_purchased'].includes(po.status)

  return (
    <>
      <Button variant="ghost" size="sm" className="-ml-2 w-fit" asChild><Link to="/purchase-orders"><ArrowLeft /> Purchase orders</Link></Button>
      <PageHeader
        title={po.po_number}
        description={`${po.vendor.name} · ordered ${formatDate(po.order_date)}${po.purchase_date ? ` · purchased ${formatDate(po.purchase_date)}` : ''}`}
        actions={<>
          <StatusBadge status={po.status} />
          {po.status === 'draft' && can('purchases.update') && <>
            <Button variant="outline" asChild><Link to={`/purchase-orders/${po.id}/edit`}><Pencil /> Edit</Link></Button>
            <Button onClick={() => run(actions.submit, 'Submitted')} disabled={actions.submit.isPending}>{actions.submit.isPending ? <Spinner /> : <Send />} Submit</Button>
          </>}
          {po.status === 'pending_approval' && can('purchases.approve') && (
            <Button onClick={() => run(actions.approve, 'Purchase order approved')} disabled={actions.approve.isPending}><CheckCheck /> Approve</Button>
          )}
          {po.status === 'approved' && can('purchases.update') && <MarkPurchasedDialog po={po} />}
          {purchased && po.status !== 'completed' && can('purchases.update') && (
            <ConfirmDialog trigger={<Button variant="outline" disabled={po.delivery_status !== 'fully_delivered'}><CircleCheckBig /> Complete</Button>}
              title="Complete purchase order?" description="Closes the purchase cycle. Everything purchased has been delivered." confirmLabel="Complete"
              onConfirm={() => run(actions.complete, 'Purchase order completed')} />
          )}
          {cancellable && can('purchases.cancel') && (
            <ConfirmDialog trigger={<Button variant="ghost" className="text-destructive"><XCircle /> Cancel</Button>} title={`Cancel ${po.po_number}?`}
              description="Its quantities return to pending and it stops counting toward purchases and vendor payables. Deliveries and payments must be reversed first."
              destructive requireReason confirmLabel="Cancel PO" onConfirm={(reason) => run(actions.cancel, 'Purchase order cancelled', { reason })} />
          )}
        </>}
      />

      {po.status === 'cancelled' && <Alert><XCircle /><AlertTitle>Cancelled</AlertTitle><AlertDescription>{po.cancellation_reason}</AlertDescription></Alert>}

      <div className="grid gap-4 md:grid-cols-4">
        <Card className="gap-1 py-4"><CardContent className="px-4"><div className="text-sm text-muted-foreground">Grand total</div><div className="text-xl font-semibold tabular-nums">{formatMoney(po.grand_total, symbol)}</div>
          <div className="text-xs text-muted-foreground">incl. {formatMoney(po.additional_charges, symbol)} charges</div></CardContent></Card>
        {po.payable_amount !== undefined && <>
          <Card className="gap-1 py-4"><CardContent className="px-4"><div className="text-sm text-muted-foreground">Paid</div><div className="text-xl font-semibold tabular-nums">{formatMoney(po.paid_amount, symbol)}</div>
            {po.payment_status && <StatusBadge status={po.payment_status} />}</CardContent></Card>
          <Card className="gap-1 py-4"><CardContent className="px-4"><div className="text-sm text-muted-foreground">Due</div><div className="text-xl font-semibold tabular-nums">{formatMoney(po.due_amount, symbol)}</div>
            <div className="text-xs text-muted-foreground">{purchased ? 'Owed to the vendor' : 'Nothing owed until purchased'}</div></CardContent></Card>
        </>}
        <Card className="gap-1 py-4"><CardContent className="px-4"><div className="text-sm text-muted-foreground">Delivered</div>
          <div className="text-xl font-semibold tabular-nums">{formatQty(po.delivered_quantity)} / {formatQty(purchased ? po.purchased_quantity : po.ordered_quantity)}</div>
          <StatusBadge status={po.delivery_status} /></CardContent></Card>
      </div>

      <Card>
        <CardHeader><CardTitle>Lines</CardTitle></CardHeader>
        <CardContent className="overflow-x-auto">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Item</TableHead><TableHead className="text-right">Ordered</TableHead><TableHead className="text-right">Purchased</TableHead>
                <TableHead className="text-right">Unit price</TableHead><TableHead className="text-right">Discount</TableHead><TableHead className="text-right">Tax</TableHead>
                <TableHead className="text-right">Line total</TableHead><TableHead className="text-right">Delivered</TableHead><TableHead>For requisitions</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {po.lines?.map((l) => (
                <TableRow key={l.id} className="align-top">
                  <TableCell><Link className="font-medium hover:underline" to={`/items/${l.item.id}`}>{l.item.name}</Link><div className="text-xs text-muted-foreground">{l.item.code} · {l.item.unit}</div></TableCell>
                  <TableCell className="text-right tabular-nums">{formatQty(l.ordered_quantity)}</TableCell>
                  <TableCell className="text-right tabular-nums">{formatQty(l.purchased_quantity)}</TableCell>
                  <TableCell className="text-right tabular-nums">{formatMoney(l.unit_price, symbol)}</TableCell>
                  <TableCell className="text-right tabular-nums">{formatMoney(l.discount_amount, symbol)}</TableCell>
                  <TableCell className="text-right tabular-nums">{formatMoney(l.tax_amount, symbol)}</TableCell>
                  <TableCell className="text-right font-medium tabular-nums">{formatMoney(l.line_total, symbol)}</TableCell>
                  <TableCell className="text-right tabular-nums">{purchased ? formatQty(l.delivered_quantity) : '—'}</TableCell>
                  <TableCell>
                    <ul className="space-y-0.5 text-xs">
                      {l.requisitions.map((r) => (
                        <li key={r.requisition_item_id}>
                          <Link className="font-mono hover:underline" to={`/requisitions/${r.requisition.id}`}>{r.requisition.reference_no}</Link>{' '}
                          <span className="text-muted-foreground">{formatQty(r.purchased_quantity ?? r.ordered_quantity)}{r.purchased_quantity !== null && compareQty(r.purchased_quantity, r.ordered_quantity) < 0 && ` of ${formatQty(r.ordered_quantity)}`}</span>
                        </li>
                      ))}
                    </ul>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
            <TableFooter>
              <TableRow><TableCell colSpan={6} className="text-right">Subtotal</TableCell><TableCell className="text-right tabular-nums">{formatMoney(po.subtotal, symbol)}</TableCell><TableCell colSpan={2} /></TableRow>
              <TableRow><TableCell colSpan={6} className="text-right">Additional charges</TableCell><TableCell className="text-right tabular-nums">{formatMoney(po.additional_charges, symbol)}</TableCell><TableCell colSpan={2} /></TableRow>
              <TableRow><TableCell colSpan={6} className="text-right font-semibold">Grand total</TableCell><TableCell className="text-right font-semibold tabular-nums">{formatMoney(po.grand_total, symbol)}</TableCell><TableCell colSpan={2} /></TableRow>
            </TableFooter>
          </Table>
          {po.notes && <p className="mt-4 text-sm whitespace-pre-line text-muted-foreground">{po.notes}</p>}
        </CardContent>
      </Card>

      {purchased && <PurchaseOrderDeliveries po={po} />}

      <Card>
        <CardHeader><CardTitle>History</CardTitle></CardHeader>
        <CardContent>
          <ol className="space-y-2 text-sm">
            <li className="flex gap-3"><span className="w-40 text-muted-foreground">{formatDateTime(po.created_at)}</span>Created by {po.created_by?.name}</li>
            {po.events?.map((e) => (
              <li key={e.event} className="flex gap-3"><span className="w-40 text-muted-foreground">{formatDateTime(e.at)}</span>{titleCase(e.event)}{e.by && ` by ${e.by.name}`}</li>
            ))}
          </ol>
        </CardContent>
      </Card>
    </>
  )
}

function MarkPurchasedDialog({ po }: { po: PurchaseOrder }) {
  const symbol = useSettings().data?.currency_symbol
  const { markPurchased } = usePurchaseOrderAction(po.id)
  const [open, setOpen] = useState(false)
  const [date, setDate] = useState<string | null>(new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Dhaka' }).format(new Date()))
  const [charges, setCharges] = useState(String(Number(po.additional_charges)))
  const initial = () => Object.fromEntries((po.lines ?? []).map((l) => [l.id, {
    qty: String(Number(l.ordered_quantity)), price: String(Number(l.unit_price)), discount: String(Number(l.discount_amount)), tax: String(Number(l.tax_amount)),
  }]))
  const [rows, setRows] = useState(initial)

  const lines = po.lines ?? []
  const invalid = lines.some((l) => {
    const r = rows[l.id]
    return !r || !/^\d+(\.\d{1,3})?$/.test(r.qty) || compareQty(r.qty, l.ordered_quantity) > 0 || !/^\d+(\.\d{1,4})?$/.test(r.price)
  }) || lines.every((l) => Number(rows[l.id]?.qty) === 0)
  const total = sumMoney([...lines.map((l) => (rows[l.id] && !invalid ? lineTotal(rows[l.id].qty, rows[l.id].price, rows[l.id].discount || '0', rows[l.id].tax || '0') : '0')), /^\d+(\.\d{1,2})?$/.test(charges) ? charges : '0'])
  const short = lines.some((l) => rows[l.id] && compareQty(rows[l.id].qty, l.ordered_quantity) < 0)

  const submit = async () => {
    try {
      await markPurchased.mutateAsync({
        purchase_date: date, additional_charges: charges,
        lines: lines.map((l) => ({ id: l.id, purchased_quantity: rows[l.id].qty, unit_price: rows[l.id].price, discount_amount: rows[l.id].discount || '0', tax_amount: rows[l.id].tax || '0' })),
      })
      toast.success('Purchase recorded')
      setOpen(false)
    } catch (e) {
      toast.error(errorMessage(e))
    }
  }
  const set = (id: number, key: 'qty' | 'price' | 'discount' | 'tax', value: string) => setRows({ ...rows, [id]: { ...rows[id], [key]: value } })

  return (
    <Dialog open={open} onOpenChange={(o) => { setOpen(o); if (o) setRows(initial()) }}>
      <DialogTrigger asChild><Button><ShoppingBag /> Mark purchased</Button></DialogTrigger>
      <DialogContent className="sm:max-w-3xl">
        <DialogHeader>
          <DialogTitle>Record purchase of {po.po_number}</DialogTitle>
          <DialogDescription>Enter what was actually bought, as on the vendor's bill. Anything bought short goes back to pending.</DialogDescription>
        </DialogHeader>
        <div className="grid gap-4 sm:grid-cols-2">
          <Field><FieldLabel>Purchase date</FieldLabel><DatePicker value={date} onChange={setDate} /></Field>
          <Field><FieldLabel htmlFor="mp-charges">Additional charges</FieldLabel><Input id="mp-charges" inputMode="decimal" value={charges} onChange={(e) => setCharges(e.target.value)} /></Field>
        </div>
        <div className="max-h-[45vh] overflow-auto">
          <Table>
            <TableHeader><TableRow><TableHead>Item</TableHead><TableHead className="text-right">Ordered</TableHead><TableHead className="w-28">Bought</TableHead><TableHead className="w-28">Unit price</TableHead><TableHead className="w-24">Discount</TableHead><TableHead className="w-24">Tax</TableHead></TableRow></TableHeader>
            <TableBody>
              {lines.map((l) => (
                <TableRow key={l.id}>
                  <TableCell>{l.item.name}</TableCell>
                  <TableCell className="text-right tabular-nums">{formatQty(l.ordered_quantity)}</TableCell>
                  {(['qty', 'price', 'discount', 'tax'] as const).map((k) => (
                    <TableCell key={k}><Input inputMode="decimal" className="text-right tabular-nums" aria-label={`${k} for ${l.item.name}`} value={rows[l.id]?.[k] ?? ''}
                      aria-invalid={k === 'qty' && rows[l.id] && compareQty(rows[l.id].qty || '0', l.ordered_quantity) > 0} onChange={(e) => set(l.id, k, e.target.value)} /></TableCell>
                  ))}
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </div>
        <div className="flex items-center justify-between text-sm">
          <span className="text-muted-foreground">{short ? 'Partially purchased: the shortfall returns to pending.' : 'All ordered quantities purchased.'}</span>
          <span>Total <span className="font-semibold tabular-nums">{formatMoney(total, symbol)}</span></span>
        </div>
        <DialogFooter><Button onClick={submit} disabled={invalid || !date || markPurchased.isPending}>{markPurchased.isPending && <Spinner />} Record purchase</Button></DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
