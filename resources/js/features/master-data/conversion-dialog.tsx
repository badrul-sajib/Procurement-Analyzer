import { zodResolver } from '@hookform/resolvers/zod'
import { useEffect, useState, type ReactNode } from 'react'
import { useForm } from 'react-hook-form'
import { toast } from 'sonner'
import { z } from 'zod'
import { SelectField, TextField } from '@/components/form-fields'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog'
import { FieldGroup } from '@/components/ui/field'
import { Spinner } from '@/components/ui/spinner'
import { useConversionMutations, useLookups } from '@/features/catalog/api'
import { applyServerErrors } from '@/lib/forms'

const schema = z.object({
  from_unit_id: z.number({ error: 'Required' }),
  to_unit_id: z.number({ error: 'Required' }),
  factor: z.string().regex(/^\d+(\.\d{1,6})?$/, 'Positive number, up to 6 decimals').refine((v) => Number(v) > 0, 'Must be greater than 0'),
}).refine((v) => v.from_unit_id !== v.to_unit_id, { path: ['to_unit_id'], message: 'Choose two different units' })
type Values = z.infer<typeof schema>

/** "1 {from} = {factor} {to}". With itemId the rule applies to that item only. */
export function ConversionDialog({ itemId, itemName, toUnitId, trigger }: { itemId?: number; itemName?: string; toUnitId?: number; trigger: ReactNode }) {
  const [open, setOpen] = useState(false)
  const units = useLookups('units', { is_active: 1 })
  const { create } = useConversionMutations()
  const form = useForm<Values>({ resolver: zodResolver(schema) })
  const unitOptions = (units.data ?? []).map((u) => ({ value: u.id, label: u.name }))

  useEffect(() => {
    if (open) form.reset({ from_unit_id: undefined, to_unit_id: toUnitId, factor: '' })
  }, [open, toUnitId, form])

  const onSubmit = form.handleSubmit(async (values) => {
    try {
      await create.mutateAsync({ ...values, item_id: itemId ?? null })
      toast.success('Conversion added')
      setOpen(false)
    } catch (e) {
      applyServerErrors(form, e)
    }
  })

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogTrigger asChild>{trigger}</DialogTrigger>
      <DialogContent>
        <form onSubmit={onSubmit}>
          <DialogHeader>
            <DialogTitle>Add unit conversion</DialogTitle>
            <DialogDescription>
              {itemName ? `Applies to ${itemName} only and overrides global rules.` : 'Global rule, used for any item without its own rule.'}
            </DialogDescription>
          </DialogHeader>
          <FieldGroup className="my-6">
            <div className="grid grid-cols-[1fr_auto_1fr] items-start gap-3">
              <SelectField form={form} name="from_unit_id" label="1 ×" options={unitOptions} />
              <span className="pt-8 text-muted-foreground">=</span>
              <TextField form={form} name="factor" label="Factor" inputMode="decimal" placeholder="12" />
            </div>
            <SelectField form={form} name="to_unit_id" label="Of unit" options={unitOptions} description={toUnitId ? "Usually the item's default unit." : undefined} />
          </FieldGroup>
          <DialogFooter>
            <Button type="submit" disabled={create.isPending}>{create.isPending && <Spinner />} Add conversion</Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}
