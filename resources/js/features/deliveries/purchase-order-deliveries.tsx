import { Link } from 'react-router'
import { StatusBadge } from '@/components/status-badge'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { useDeliveries } from '@/features/deliveries/api'
import { DeliveryDialog } from '@/features/deliveries/delivery-dialog'
import type { PurchaseOrder } from '@/features/purchases/api'
import { useAuth } from '@/lib/auth'
import { formatDate, formatQty } from '@/lib/format'

export function PurchaseOrderDeliveries({ po }: { po: PurchaseOrder }) {
  const { can } = useAuth()
  const { data } = useDeliveries({ purchase_order_id: po.id, per_page: 50 })
  if (!can('deliveries.view')) return null
  return (
    <Card>
      <CardHeader className="flex-row items-center justify-between">
        <CardTitle>Deliveries</CardTitle>
        {can('deliveries.create') && ['purchased', 'partially_purchased'].includes(po.status) && po.delivery_status !== 'fully_delivered' && (
          <DeliveryDialog poId={po.id} poNumber={po.po_number} />
        )}
      </CardHeader>
      <CardContent>
        <Table>
          <TableHeader><TableRow><TableHead>Delivery</TableHead><TableHead>Date</TableHead><TableHead>Reference</TableHead><TableHead>Received by</TableHead><TableHead className="text-right">Quantity</TableHead><TableHead>Status</TableHead></TableRow></TableHeader>
          <TableBody>
            {data?.data.map((d) => (
              <TableRow key={d.id}>
                <TableCell><Link className="font-mono text-xs hover:underline" to={`/deliveries/${d.id}`}>{d.delivery_number}</Link></TableCell>
                <TableCell>{formatDate(d.delivery_date)}</TableCell>
                <TableCell>{d.delivery_reference ?? '—'}</TableCell>
                <TableCell>{d.received_by?.name ?? '—'}</TableCell>
                <TableCell className="text-right tabular-nums">{formatQty(d.total_quantity)}</TableCell>
                <TableCell><StatusBadge status={d.status} /></TableCell>
              </TableRow>
            ))}
            {data && !data.data.length && <TableRow><TableCell colSpan={6} className="text-muted-foreground">No deliveries recorded yet.</TableCell></TableRow>}
          </TableBody>
        </Table>
      </CardContent>
    </Card>
  )
}
