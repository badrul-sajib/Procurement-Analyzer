import { ArrowLeft, Split, Undo2 } from 'lucide-react'
import { useState } from 'react'
import { Link, useParams } from 'react-router'
import { toast } from 'sonner'
import { ConfirmDialog } from '@/components/confirm-dialog'
import { PageHeader } from '@/components/page-header'
import { StatusBadge } from '@/components/status-badge'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog'
import { Skeleton } from '@/components/ui/skeleton'
import { Spinner } from '@/components/ui/spinner'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { AllocationTable } from '@/features/payments/allocation-table'
import { METHODS, usePayables, usePayment, usePaymentAction, type Payment } from '@/features/payments/api'
import { useAuth } from '@/lib/auth'
import { sumMoney } from '@/lib/decimal'
import { errorMessage } from '@/lib/forms'
import { formatDate, formatDateTime, formatMoney } from '@/lib/format'
import { useSettings } from '@/lib/settings'

function AllocateDialog({ payment }: { payment: Payment }) {
  const symbol = useSettings().data?.currency_symbol
  const [open, setOpen] = useState(false)
  const [value, setValue] = useState<Record<number, string>>({})
  const payables = usePayables(open ? payment.vendor.id : undefined)
  const { allocate } = usePaymentAction(payment.id)
  const entered = Object.entries(value).filter(([, v]) => v && Number(v) > 0)
  const total = sumMoney(entered.map(([, v]) => (/^\d+(\.\d{1,2})?$/.test(v) ? v : '0')))
  const over = Number(total) > Number(payment.unallocated_amount)

  const submit = async () => {
    try {
      await allocate.mutateAsync({ allocations: entered.map(([id, amount]) => ({ purchase_order_id: Number(id), amount })) })
      toast.success('Payment allocated')
      setOpen(false)
    } catch (e) {
      toast.error(errorMessage(e))
    }
  }
  return (
    <Dialog open={open} onOpenChange={(o) => { setOpen(o); setValue({}) }}>
      <DialogTrigger asChild><Button><Split /> Allocate</Button></DialogTrigger>
      <DialogContent className="sm:max-w-2xl">
        <DialogHeader>
          <DialogTitle>Allocate {payment.payment_reference}</DialogTitle>
          <DialogDescription>{formatMoney(payment.unallocated_amount, symbol)} unallocated. Applying {formatMoney(total, symbol)}{over && ' — too much'}.</DialogDescription>
        </DialogHeader>
        <AllocationTable payables={payables.data ?? []} value={value} onChange={setValue} symbol={symbol} />
        <DialogFooter><Button onClick={submit} disabled={!entered.length || over || allocate.isPending}>{allocate.isPending && <Spinner />} Allocate</Button></DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

export function PaymentPage() {
  const id = Number(useParams().id)
  const { can } = useAuth()
  const symbol = useSettings().data?.currency_symbol
  const { data: p, isLoading } = usePayment(id)
  const { reverse } = usePaymentAction(id)
  if (isLoading || !p) return <Skeleton className="h-64" />
  const methodLabel = Object.fromEntries(METHODS)

  return (
    <>
      <Button variant="ghost" size="sm" className="-ml-2 w-fit" asChild><Link to="/payments"><ArrowLeft /> Payments</Link></Button>
      <PageHeader title={p.payment_reference} description={`${p.vendor.name} · ${formatDate(p.payment_date)} · ${methodLabel[p.method]}${p.transaction_reference ? ` · ${p.transaction_reference}` : ''}`}
        actions={<>
          <StatusBadge status={p.status} />
          {p.status === 'active' && Number(p.unallocated_amount) > 0 && can('payments.allocate') && <AllocateDialog payment={p} />}
          {p.status === 'active' && can('payments.reverse') && (
            <ConfirmDialog trigger={<Button variant="ghost" className="text-destructive"><Undo2 /> Reverse</Button>} title={`Reverse ${p.payment_reference}?`}
              description="The payment and its allocations stop counting toward paid amounts. The record is kept for audit." destructive requireReason confirmLabel="Reverse payment"
              onConfirm={(reason) => reverse.mutateAsync({ reason }).then(() => toast.success('Payment reversed')).catch((e) => toast.error(errorMessage(e)))} />
          )}
        </>} />
      {p.status === 'reversed' && <Alert><Undo2 /><AlertTitle>Reversed {formatDateTime(p.reversed_at)}</AlertTitle><AlertDescription>{p.reversal_reason}</AlertDescription></Alert>}
      <div className="grid gap-4 sm:grid-cols-3">
        {([['Amount', p.amount], ['Allocated', p.allocated_amount], ['Unallocated (credit)', p.unallocated_amount]] as const).map(([label, value]) => (
          <Card key={label} className="gap-1 py-4"><CardContent className="px-4"><div className="text-sm text-muted-foreground">{label}</div><div className="text-xl font-semibold tabular-nums">{formatMoney(value, symbol)}</div></CardContent></Card>
        ))}
      </div>
      <Card>
        <CardHeader><CardTitle>Allocations</CardTitle></CardHeader>
        <CardContent>
          <Table>
            <TableHeader><TableRow><TableHead>Purchase order</TableHead><TableHead>Purchased</TableHead><TableHead>Allocated on</TableHead><TableHead className="text-right">Amount</TableHead></TableRow></TableHeader>
            <TableBody>
              {p.allocations?.map((a) => (
                <TableRow key={a.id}>
                  <TableCell><Link className="font-mono text-xs hover:underline" to={`/purchase-orders/${a.purchase_order.id}`}>{a.purchase_order.po_number}</Link></TableCell>
                  <TableCell>{formatDate(a.purchase_order.purchase_date)}</TableCell>
                  <TableCell>{formatDateTime(a.created_at)}</TableCell>
                  <TableCell className="text-right tabular-nums">{formatMoney(a.amount, symbol)}</TableCell>
                </TableRow>
              ))}
              {!p.allocations?.length && <TableRow><TableCell colSpan={4} className="text-muted-foreground">Not allocated yet.</TableCell></TableRow>}
            </TableBody>
          </Table>
          {p.notes && <p className="mt-4 text-sm text-muted-foreground">{p.notes}</p>}
        </CardContent>
      </Card>
    </>
  )
}
