import { ArrowLeft, CheckCheck, Undo2 } from 'lucide-react'
import { Link, useParams } from 'react-router'
import { toast } from 'sonner'
import { ConfirmDialog } from '@/components/confirm-dialog'
import { PageHeader } from '@/components/page-header'
import { StatusBadge } from '@/components/status-badge'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { useDelivery, useDeliveryAction } from '@/features/deliveries/api'
import { useAuth } from '@/lib/auth'
import { errorMessage } from '@/lib/forms'
import { formatDate, formatDateTime, formatQty } from '@/lib/format'

export function DeliveryPage() {
  const id = Number(useParams().id)
  const { can } = useAuth()
  const { data: d, isLoading } = useDelivery(id)
  const actions = useDeliveryAction(id)
  if (isLoading || !d) return <Skeleton className="h-64" />

  return (
    <>
      <Button variant="ghost" size="sm" className="-ml-2 w-fit" asChild><Link to="/deliveries"><ArrowLeft /> Deliveries</Link></Button>
      <PageHeader title={d.delivery_number}
        description={`${d.purchase_order.vendor?.name ?? ''} · delivered ${formatDate(d.delivery_date)}${d.delivery_reference ? ` · ref ${d.delivery_reference}` : ''}`}
        actions={<>
          <StatusBadge status={d.status} />
          {d.status === 'pending' && can('deliveries.confirm') && (
            <Button onClick={() => actions.confirm.mutateAsync(undefined).then(() => toast.success('Delivery confirmed')).catch((e) => toast.error(errorMessage(e)))} disabled={actions.confirm.isPending}>
              <CheckCheck /> Confirm received
            </Button>
          )}
          {d.status !== 'reversed' && can('deliveries.reverse') && (
            <ConfirmDialog trigger={<Button variant="ghost" className="text-destructive"><Undo2 /> Reverse</Button>} title={`Reverse ${d.delivery_number}?`}
              description="The delivery stays on record as reversed and its quantities stop counting as delivered." destructive requireReason confirmLabel="Reverse delivery"
              onConfirm={(reason) => actions.reverse.mutateAsync({ reason }).then(() => toast.success('Delivery reversed')).catch((e) => toast.error(errorMessage(e)))} />
          )}
        </>} />
      {d.status === 'reversed' && <Alert><Undo2 /><AlertTitle>Reversed {formatDateTime(d.reversed_at)}</AlertTitle><AlertDescription>{d.reversal_reason}</AlertDescription></Alert>}
      <Card>
        <CardHeader><CardTitle>Received items</CardTitle></CardHeader>
        <CardContent>
          <dl className="mb-4 grid gap-3 text-sm sm:grid-cols-4">
            <div><dt className="text-muted-foreground">Purchase order</dt><dd><Link className="font-mono hover:underline" to={`/purchase-orders/${d.purchase_order.id}`}>{d.purchase_order.po_number}</Link></dd></div>
            <div><dt className="text-muted-foreground">Received by</dt><dd>{d.received_by?.name ?? '—'}</dd></div>
            <div><dt className="text-muted-foreground">Recorded</dt><dd>{formatDateTime(d.created_at)} · {d.created_by?.name}</dd></div>
            <div><dt className="text-muted-foreground">Confirmed</dt><dd>{formatDateTime(d.confirmed_at)}</dd></div>
          </dl>
          <Table>
            <TableHeader><TableRow><TableHead>Item</TableHead><TableHead className="text-right">Purchased on PO</TableHead><TableHead className="text-right">Delivered here</TableHead></TableRow></TableHeader>
            <TableBody>
              {d.lines?.map((l) => (
                <TableRow key={l.id}>
                  <TableCell>{l.item?.name}</TableCell>
                  <TableCell className="text-right tabular-nums">{formatQty(l.purchased_quantity)} {l.item?.unit}</TableCell>
                  <TableCell className="text-right font-medium tabular-nums">{formatQty(l.quantity)} {l.item?.unit}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
          {d.notes && <p className="mt-4 text-sm text-muted-foreground">{d.notes}</p>}
        </CardContent>
      </Card>
    </>
  )
}
