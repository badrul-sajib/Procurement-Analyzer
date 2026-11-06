import { ArrowLeft, Save } from 'lucide-react'
import { useState } from 'react'
import { Link, useNavigate } from 'react-router'
import { toast } from 'sonner'
import { AsyncCombobox, type Option } from '@/components/async-combobox'
import { DatePicker } from '@/components/date-range-filter'
import { PageHeader } from '@/components/page-header'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Field, FieldGroup, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Spinner } from '@/components/ui/spinner'
import { Textarea } from '@/components/ui/textarea'
import type { Vendor } from '@/features/catalog/api'
import { AllocationTable } from '@/features/payments/allocation-table'
import { METHODS, usePayables, useRecordPayment, type Method } from '@/features/payments/api'
import { useAuth } from '@/lib/auth'
import { sumMoney } from '@/lib/decimal'
import { errorMessage } from '@/lib/forms'
import { formatMoney } from '@/lib/format'
import { useSettings } from '@/lib/settings'

const money = /^\d+(\.\d{1,2})?$/

export function PaymentFormPage() {
  const navigate = useNavigate()
  const { can } = useAuth()
  const symbol = useSettings().data?.currency_symbol
  const record = useRecordPayment()
  const [vendor, setVendor] = useState<Option | null>(null)
  const [date, setDate] = useState<string | null>(new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Dhaka' }).format(new Date()))
  const [amount, setAmount] = useState('')
  const [method, setMethod] = useState<Method>('bank_transfer')
  const [reference, setReference] = useState('')
  const [notes, setNotes] = useState('')
  const [allocations, setAllocations] = useState<Record<number, string>>({})
  const payables = usePayables(can('payments.allocate') ? vendor?.value : undefined)

  const entered = Object.entries(allocations).filter(([, v]) => v && Number(v) > 0)
  const allocated = sumMoney(entered.map(([, v]) => (money.test(v) ? v : '0')))
  const over = money.test(amount) && Number(allocated) > Number(amount)
  const invalid = !vendor || !date || !money.test(amount) || Number(amount) <= 0 || over || entered.some(([, v]) => !money.test(v))

  const submit = async () => {
    try {
      const p = await record.mutateAsync({
        vendor_id: vendor!.value, payment_date: date!, amount, method, transaction_reference: reference || null, notes: notes || null,
        allocations: entered.map(([id, v]) => ({ purchase_order_id: Number(id), amount: v })),
      })
      toast.success(`${p.payment_reference} recorded`)
      navigate(`/payments/${p.id}`)
    } catch (e) {
      toast.error(errorMessage(e))
    }
  }

  return (
    <>
      <Button variant="ghost" size="sm" className="-ml-2 w-fit" asChild><Link to="/payments"><ArrowLeft /> Payments</Link></Button>
      <PageHeader title="Record payment" description="Money paid to a vendor. Allocate it to purchase orders now or later; unallocated money stays as vendor credit."
        actions={<Button onClick={submit} disabled={invalid || record.isPending}>{record.isPending ? <Spinner /> : <Save />} Save payment</Button>} />
      <Card>
        <CardContent>
          <FieldGroup className="grid gap-4 md:grid-cols-3">
            <Field><FieldLabel>Vendor</FieldLabel>
              <AsyncCombobox<Vendor> endpoint="/vendors" toOption={(v) => ({ value: v.id, label: v.name, hint: v.code })} value={vendor}
                onChange={(o) => { setVendor(o); setAllocations({}) }} placeholder="Choose vendor" /></Field>
            <Field><FieldLabel>Payment date</FieldLabel><DatePicker value={date} onChange={setDate} /></Field>
            <Field><FieldLabel htmlFor="amount">Amount ({symbol})</FieldLabel>
              <Input id="amount" inputMode="decimal" value={amount} onChange={(e) => setAmount(e.target.value)} aria-invalid={!!amount && !money.test(amount)} /></Field>
            <Field><FieldLabel>Method</FieldLabel>
              <Select value={method} onValueChange={(v) => setMethod(v as Method)}>
                <SelectTrigger className="w-full"><SelectValue /></SelectTrigger>
                <SelectContent>{METHODS.map(([k, l]) => <SelectItem key={k} value={k}>{l}</SelectItem>)}</SelectContent>
              </Select></Field>
            <Field><FieldLabel htmlFor="trx">Transaction reference</FieldLabel><Input id="trx" value={reference} onChange={(e) => setReference(e.target.value)} placeholder="Cheque no., bKash TrxID…" maxLength={120} /></Field>
            <Field className="md:col-span-3"><FieldLabel htmlFor="pay-notes">Notes</FieldLabel><Textarea id="pay-notes" rows={2} value={notes} onChange={(e) => setNotes(e.target.value)} /></Field>
          </FieldGroup>
        </CardContent>
      </Card>
      {can('payments.allocate') && vendor && (
        <Card>
          <CardHeader>
            <CardTitle>Allocate to purchase orders</CardTitle>
            <CardDescription>
              Allocated {formatMoney(allocated, symbol)} of {formatMoney(money.test(amount) ? amount : '0', symbol)}
              {over && <span className="text-destructive"> — more than the payment amount</span>}
            </CardDescription>
          </CardHeader>
          <CardContent><AllocationTable payables={payables.data ?? []} value={allocations} onChange={setAllocations} symbol={symbol} /></CardContent>
        </Card>
      )}
    </>
  )
}
