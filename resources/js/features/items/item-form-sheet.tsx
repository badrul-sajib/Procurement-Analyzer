import { zodResolver } from '@hookform/resolvers/zod'
import { useEffect, useState, type ReactNode } from 'react'
import { useForm } from 'react-hook-form'
import { toast } from 'sonner'
import { z } from 'zod'
import { SelectField, SwitchField, TextareaField, TextField } from '@/components/form-fields'
import { Button } from '@/components/ui/button'
import { FieldGroup } from '@/components/ui/field'
import { Sheet, SheetContent, SheetDescription, SheetFooter, SheetHeader, SheetTitle, SheetTrigger } from '@/components/ui/sheet'
import { Spinner } from '@/components/ui/spinner'
import { useLookups, useSaveItem, type Item } from '@/features/catalog/api'
import { applyServerErrors } from '@/lib/forms'

const schema = z.object({
  name: z.string().trim().min(1, 'Name is required').max(190),
  code: z.string().trim().max(40).regex(/^[A-Za-z0-9._-]*$/, 'Letters, numbers, . _ - only').optional(),
  sku: z.string().trim().max(64).optional(),
  category_id: z.number().nullable(),
  default_unit_id: z.number({ error: 'Default unit is required' }),
  description: z.string().max(2000).optional(),
  is_active: z.boolean(),
  aliases_text: z.string().optional(),
})
type Values = z.infer<typeof schema>

export function ItemFormSheet({ item, trigger, onSaved, defaultName, defaultUnitId }: { item?: Item; trigger: ReactNode; onSaved?: (item: Item) => void; defaultName?: string; defaultUnitId?: number | null }) {
  const [open, setOpen] = useState(false)
  const units = useLookups('units', { is_active: 1 })
  const categories = useLookups('item-categories', { is_active: 1 })
  const save = useSaveItem()
  const form = useForm<Values>({ resolver: zodResolver(schema) })

  useEffect(() => {
    if (!open) return
    form.reset({
      name: item?.name ?? defaultName ?? '', code: item?.code ?? '', sku: item?.sku ?? '', category_id: item?.category?.id ?? null,
      default_unit_id: (item?.default_unit.id ?? defaultUnitId ?? undefined) as number, description: item?.description ?? '', is_active: item?.is_active ?? true, aliases_text: '',
    })
  }, [open, item, form, defaultName, defaultUnitId])

  const onSubmit = form.handleSubmit(async ({ aliases_text, ...values }) => {
    try {
      const res = await save.mutateAsync({
        ...values, id: item?.id, code: values.code || null, sku: values.sku || null, description: values.description || null,
        ...(item ? {} : { aliases: (aliases_text ?? '').split('\n').map((a) => a.trim()).filter(Boolean) }),
      })
      toast.success(item ? 'Item updated' : 'Item created')
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
            <SheetTitle>{item ? `Edit ${item.name}` : 'New item'}</SheetTitle>
            <SheetDescription>Items are matched to requisition lines by name and aliases.</SheetDescription>
          </SheetHeader>
          <FieldGroup className="flex-1 px-4">
            <TextField form={form} name="name" label="Name" autoFocus />
            <div className="grid gap-4 sm:grid-cols-2">
              <TextField form={form} name="code" label="Code" placeholder="Auto (ITM-00001)" />
              <TextField form={form} name="sku" label="SKU" />
            </div>
            <div className="grid gap-4 sm:grid-cols-2">
              <SelectField form={form} name="default_unit_id" label="Default unit" options={(units.data ?? []).map((u) => ({ value: u.id, label: u.name }))}
                description={item ? 'Locked once verified requisitions use this item.' : 'All quantities consolidate to this unit.'} />
              <SelectField form={form} name="category_id" label="Category" allowNone options={(categories.data ?? []).map((c) => ({ value: c.id, label: c.name }))} />
            </div>
            <TextareaField form={form} name="description" label="Description" rows={3} />
            {!item && <TextareaField form={form} name="aliases_text" label="Aliases" rows={3} placeholder={'One per line, e.g.\nA4 Size Paper\nOffset Paper A4'} />}
            <SwitchField form={form} name="is_active" label="Active" description="Inactive items can't be matched to new requisition lines." />
          </FieldGroup>
          <SheetFooter>
            <Button type="submit" disabled={save.isPending}>{save.isPending && <Spinner />} Save</Button>
          </SheetFooter>
        </form>
      </SheetContent>
    </Sheet>
  )
}
