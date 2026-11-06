import { Input } from '@/components/ui/input'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import type { Payable } from '@/features/payments/api'
import { formatDate, formatMoney } from '@/lib/format'

/** Amount to apply per payable PO; oldest first. Server re-checks every limit under lock. */
export function AllocationTable({ payables, value, onChange, symbol }: { payables: Payable[]; value: Record<number, string>; onChange: (v: Record<number, string>) => void; symbol?: string }) {
  if (!payables.length) return <p className="text-sm text-muted-foreground">No purchased orders are due for this vendor. The payment will be kept as vendor credit.</p>
  return (
    <Table>
      <TableHeader><TableRow><TableHead>Purchase order</TableHead><TableHead>Purchased</TableHead><TableHead className="text-right">Total</TableHead><TableHead className="text-right">Due</TableHead><TableHead className="w-36">Apply</TableHead></TableRow></TableHeader>
      <TableBody>
        {payables.map((p) => (
          <TableRow key={p.id}>
            <TableCell className="font-mono text-xs">{p.po_number}</TableCell>
            <TableCell>{formatDate(p.purchase_date)}</TableCell>
            <TableCell className="text-right tabular-nums">{formatMoney(p.grand_total, symbol)}</TableCell>
            <TableCell className="text-right tabular-nums">{formatMoney(p.due, symbol)}</TableCell>
            <TableCell>
              <Input inputMode="decimal" className="text-right tabular-nums" aria-label={`Amount for ${p.po_number}`} value={value[p.id] ?? ''}
                aria-invalid={!!value[p.id] && (!/^\d+(\.\d{1,2})?$/.test(value[p.id]) || Number(value[p.id]) > Number(p.due))}
                onChange={(e) => onChange({ ...value, [p.id]: e.target.value })} placeholder="0.00" />
            </TableCell>
          </TableRow>
        ))}
      </TableBody>
    </Table>
  )
}
