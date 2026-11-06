import { PackagePlus } from 'lucide-react'
import { useEffect, useState } from 'react'
import { toast } from 'sonner'
import { DatePicker } from '@/components/date-range-filter'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog'
import { Field, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Spinner } from '@/components/ui/spinner'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { useCreateDelivery, useDeliverable } from '@/features/deliveries/api'
import { useAuth } from '@/lib/auth'
import { compareQty } from '@/lib/decimal'
import { errorMessage } from '@/lib/forms'
import { formatQty } from '@/lib/format'

/** Record goods received against a purchased PO. Partial deliveries are normal. */
export function DeliveryDialog({ poId, poNumber }: { poId: number; poNumber: string }) {
  const { can } = useAuth()
  const [open, setOpen] = useState(false)
  const deliverable = useDeliverable(poId, open)
  const create = useCreateDelivery()
  const [date, setDate] = useState<string | null>(null)
  const [reference, setReference] = useState('')
  const [qty, setQty] = useState<Record<number, string>>({})
  const [confirmNow, setConfirmNow] = useState(can('deliveries.confirm'))

  useEffect(() => {
    if (!open || !deliverable.data) return
    setDate(new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Dhaka' }).format(new Date()))
    setQty(Object.fromEntries(deliverable.data.map((l) => [l.purchase_order_item_id, String(Number(l.remaining))])))
  }, [open, deliverable.data])

  const lines = (deliverable.data ?? []).filter((l) => Number(l.remaining) > 0)
  const entered = lines.filter((l) => Number(qty[l.purchase_order_item_id] || 0) > 0)
  const invalid = lines.some((l) => {
    const v = qty[l.purchase_order_item_id] ?? ''
    return v !== '' && (!/^\d+(\.\d{1,3})?$/.test(v) || compareQty(v, l.remaining) > 0)
  })

  const submit = async () => {
    try {
      const d = await create.mutateAsync({
        purchase_order_id: poId, delivery_date: date!, delivery_reference: reference || null, confirm: confirmNow,
        lines: entered.map((l) => ({ purchase_order_item_id: l.purchase_order_item_id, quantity: qty[l.purchase_order_item_id] })),
      })
      toast.success(`${d.delivery_number} ${d.status === 'confirmed' ? 'confirmed' : 'recorded, awaiting confirmation'}`)
      setOpen(false)
    } catch (e) {
      toast.error(errorMessage(e))
    }
  }

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogTrigger asChild><Button size="sm"><PackagePlus /> Record delivery</Button></DialogTrigger>
      <DialogContent className="sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle>Record delivery for {poNumber}</DialogTitle>
          <DialogDescription>Enter what physically arrived. Quantities can't exceed what is left to deliver.</DialogDescription>
        </DialogHeader>
        <div className="grid gap-4 sm:grid-cols-2">
          <Field><FieldLabel>Delivery date</FieldLabel><DatePicker value={date} onChange={setDate} /></Field>
          <Field><FieldLabel htmlFor="dlv-ref">Delivery reference / challan no.</FieldLabel><Input id="dlv-ref" value={reference} onChange={(e) => setReference(e.target.value)} maxLength={100} /></Field>
        </div>
        <Table>
          <TableHeader><TableRow><TableHead>Item</TableHead><TableHead className="text-right">Purchased</TableHead><TableHead className="text-right">Left to deliver</TableHead><TableHead className="w-32">Received now</TableHead></TableRow></TableHeader>
          <TableBody>
            {lines.map((l) => (
              <TableRow key={l.purchase_order_item_id}>
                <TableCell>{l.item.name}</TableCell>
                <TableCell className="text-right tabular-nums">{formatQty(l.purchased_quantity)} {l.item.unit}</TableCell>
                <TableCell className="text-right tabular-nums">{formatQty(l.remaining)}</TableCell>
                <TableCell><Input inputMode="decimal" className="text-right tabular-nums" aria-label={`Received quantity of ${l.item.name}`}
                  value={qty[l.purchase_order_item_id] ?? ''} aria-invalid={compareQty(qty[l.purchase_order_item_id] || '0', l.remaining) > 0}
                  onChange={(e) => setQty({ ...qty, [l.purchase_order_item_id]: e.target.value })} /></TableCell>
              </TableRow>
            ))}
            {!deliverable.isLoading && !lines.length && <TableRow><TableCell colSpan={4} className="text-muted-foreground">Everything purchased has been delivered or is awaiting confirmation.</TableCell></TableRow>}
          </TableBody>
        </Table>
        {can('deliveries.confirm') && (
          <label className="flex items-center gap-2 text-sm"><Checkbox checked={confirmNow} onCheckedChange={(c) => setConfirmNow(c === true)} /> Confirm received quantities now</label>
        )}
        <DialogFooter><Button onClick={submit} disabled={!date || !entered.length || invalid || create.isPending}>{create.isPending && <Spinner />} Save delivery</Button></DialogFooter>
      </DialogContent>
    </Dialog>
  )
}
