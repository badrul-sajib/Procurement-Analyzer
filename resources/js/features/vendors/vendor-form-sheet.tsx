import { zodResolver } from '@hookform/resolvers/zod'
import { useEffect, useState, type ReactNode } from 'react'
import { useForm } from 'react-hook-form'
import { toast } from 'sonner'
import { z } from 'zod'
import { SwitchField, TextareaField, TextField } from '@/components/form-fields'
import { Button } from '@/components/ui/button'
import { FieldGroup } from '@/components/ui/field'
import { Sheet, SheetContent, SheetDescription, SheetFooter, SheetHeader, SheetTitle, SheetTrigger } from '@/components/ui/sheet'
import { Spinner } from '@/components/ui/spinner'
import { useSaveVendor, type Vendor } from '@/features/catalog/api'
import { applyServerErrors } from '@/lib/forms'

const optional = (max: number) => z.string().trim().max(max).optional()
const schema = z.object({
  name: z.string().trim().min(1, 'Name is required').max(190),
  code: z.string().trim().max(30).regex(/^[A-Za-z0-9._-]*$/, 'Letters, numbers, . _ - only').optional(),
  contact_person: optional(120),
  phone: z.string().trim().max(40).regex(/^[0-9+\-\s()]*$/, 'Digits, spaces, + - ( ) only').optional(),
  email: z.union([z.literal(''), z.email('Enter a valid email')]).optional(),
  address: optional(1000),
  company_info: optional(2000),
  tax_number: optional(60),
  notes: optional(2000),
  is_active: z.boolean(),
})
type Values = z.infer<typeof schema>

export function VendorFormSheet({ vendor, trigger, onSaved }: { vendor?: Vendor; trigger: ReactNode; onSaved?: (v: Vendor) => void }) {
  const [open, setOpen] = useState(false)
  const save = useSaveVendor()
  const form = useForm<Values>({ resolver: zodResolver(schema) })

  useEffect(() => {
    if (!open) return
    const v = vendor
    form.reset({
      name: v?.name ?? '', code: v?.code ?? '', contact_person: v?.contact_person ?? '', phone: v?.phone ?? '', email: v?.email ?? '',
      address: v?.address ?? '', company_info: v?.company_info ?? '', tax_number: v?.tax_number ?? '', notes: v?.notes ?? '', is_active: v?.is_active ?? true,
    })
  }, [open, vendor, form])

  const onSubmit = form.handleSubmit(async (values) => {
    // Empty strings become null so cleared fields are actually cleared server-side.
    const body = Object.fromEntries(Object.entries(values).map(([k, v]) => [k, v === '' ? null : v])) as Values
    try {
      const res = await save.mutateAsync({ ...body, id: vendor?.id })
      toast.success(vendor ? 'Vendor updated' : 'Vendor created')
      setOpen(false)
      onSaved?.(res.data)
    } catch (e) {
      applyServerErrors(form, e)
    }
  })

  return (
    <Sheet open={open} onOpenChange={setOpen}>
      <SheetTrigger asChild>{trigger}</SheetTrigger>
      <SheetContent className="w-full overflow-y-auto sm:max-w-lg">
        <form onSubmit={onSubmit} className="flex h-full flex-col">
          <SheetHeader>
            <SheetTitle>{vendor ? `Edit ${vendor.name}` : 'New vendor'}</SheetTitle>
            <SheetDescription>Supplier contact and tax details.</SheetDescription>
          </SheetHeader>
          <FieldGroup className="flex-1 px-4">
            <TextField form={form} name="name" label="Vendor name" autoFocus />
            <div className="grid gap-4 sm:grid-cols-2">
              <TextField form={form} name="code" label="Code" placeholder="Auto (VEN-0001)" />
              <TextField form={form} name="tax_number" label="Tax / VAT (BIN)" />
            </div>
            <div className="grid gap-4 sm:grid-cols-2">
              <TextField form={form} name="contact_person" label="Contact person" />
              <TextField form={form} name="phone" label="Phone" inputMode="tel" />
            </div>
            <TextField form={form} name="email" label="Email" type="email" />
            <TextareaField form={form} name="address" label="Address" rows={2} />
            <TextareaField form={form} name="company_info" label="Company information" rows={2} placeholder="Trade license, website, bank details…" />
            <TextareaField form={form} name="notes" label="Notes" rows={2} />
            <SwitchField form={form} name="is_active" label="Active" description="Inactive vendors can't receive new purchase orders." />
          </FieldGroup>
          <SheetFooter>
            <Button type="submit" disabled={save.isPending}>{save.isPending && <Spinner />} Save</Button>
          </SheetFooter>
        </form>
      </SheetContent>
    </Sheet>
  )
}
