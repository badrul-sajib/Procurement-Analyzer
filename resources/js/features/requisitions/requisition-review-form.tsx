import { zodResolver } from '@hookform/resolvers/zod'
import { Plus, Trash2 } from 'lucide-react'
import { forwardRef, useEffect, useImperativeHandle } from 'react'
import { Controller, useFieldArray, useForm } from 'react-hook-form'
import { z } from 'zod'
import { DatePicker } from '@/components/date-range-filter'
import { TextareaField, TextField } from '@/components/form-fields'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Checkbox } from '@/components/ui/checkbox'
import { Field, FieldError, FieldGroup, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { useLookups } from '@/features/catalog/api'
import type { Requisition, RequisitionInput } from '@/features/requisitions/api'
import { LineItemPicker, type PickedItem } from '@/features/requisitions/line-item-picker'
import { applyServerErrors } from '@/lib/forms'

const qty = z.string().trim().regex(/^(\d+(\.\d{1,3})?)?$/, 'Up to 3 decimals')
const lineSchema = z.object({
  id: z.number().optional(),
  original_name: z.string().trim().min(1, 'Required').max(255),
  original_unit: z.string().nullable().optional(),
  item: z.custom<PickedItem | null>(),
  auto_item_id: z.number().nullable().optional(),
  quantity: qty,
  unit_id: z.number().nullable(),
  department_id: z.number().nullable(),
  requested_date: z.string().nullable(),
  notes: z.string().max(1000).nullable().optional(),
  save_alias: z.boolean().optional(),
})
const schema = z.object({
  title: z.string().trim().min(1, 'Title is required').max(190),
  external_reference: z.string().trim().max(100).optional(),
  requisition_date: z.string().nullable(),
  department_id: z.number().nullable(),
  notes: z.string().max(5000).optional(),
  lines: z.array(lineSchema),
})
export type ReviewValues = z.infer<typeof schema>

export type ReviewFormHandle = {
  isDirty: () => boolean
  /** Validate and return the API payload, or null if client validation failed. */
  payload: () => Promise<RequisitionInput | null>
  applyErrors: (error: unknown) => void
}

function toValues(r?: Requisition): ReviewValues {
  return {
    title: r?.title ?? '',
    external_reference: r?.external_reference ?? '',
    requisition_date: r?.requisition_date ?? null,
    department_id: r?.department?.id ?? null,
    notes: r?.notes ?? '',
    lines: (r?.lines ?? []).map((l) => ({
      id: l.id,
      original_name: l.original_name,
      original_unit: l.original_unit,
      item: l.item ? { id: l.item.id, name: l.item.name, default_unit_id: l.item.default_unit?.id ?? null } : null,
      auto_item_id: l.match_type && l.match_type !== 'manual' ? l.item?.id ?? null : null,
      quantity: l.quantity ? String(Number(l.quantity)) : '',
      unit_id: l.unit?.id ?? null,
      department_id: l.department?.id ?? null,
      requested_date: l.requested_date,
      notes: l.notes ?? '',
      save_alias: false,
    })),
  }
}

/** Editable header + lines for draft / extracted / failed requisitions. */
export const RequisitionReviewForm = forwardRef<ReviewFormHandle, { requisition?: Requisition }>(function RequisitionReviewForm({ requisition }, ref) {
  const units = useLookups('units', { is_active: 1 })
  const departments = useLookups('departments', { is_active: 1 })
  const form = useForm<ReviewValues>({ resolver: zodResolver(schema), defaultValues: toValues(requisition) })
  const lines = useFieldArray({ control: form.control, name: 'lines', keyName: 'key' })

  useEffect(() => {
    form.reset(toValues(requisition))
  }, [requisition, form])

  useImperativeHandle(ref, () => ({
    isDirty: () => form.formState.isDirty,
    applyErrors: (e) => applyServerErrors(form, e),
    payload: async () => {
      if (!(await form.trigger())) return null
      const v = form.getValues()
      return {
        title: v.title,
        external_reference: v.external_reference || null,
        requisition_date: v.requisition_date,
        department_id: v.department_id,
        notes: v.notes || null,
        lines: v.lines.map((l) => ({
          id: l.id,
          original_name: l.original_name,
          original_unit: l.original_unit ?? null,
          item_id: l.item?.id ?? null,
          quantity: l.quantity === '' ? null : l.quantity,
          unit_id: l.unit_id,
          department_id: l.department_id,
          requested_date: l.requested_date,
          notes: l.notes || null,
          save_alias: !!l.save_alias && !!l.item && l.item.id !== l.auto_item_id,
        })),
      }
    },
  }), [form])

  const unitOptions = units.data ?? []
  const errors = form.formState.errors

  return (
    <div className="space-y-6">
      <Card>
        <CardHeader><CardTitle>Requisition details</CardTitle></CardHeader>
        <CardContent>
          <FieldGroup className="grid gap-4 md:grid-cols-2">
            <TextField form={form} name="title" label="Title" />
            <TextField form={form} name="external_reference" label="Document reference" placeholder="Reference printed on the document" />
            <Controller control={form.control} name="requisition_date" render={({ field, fieldState }) => (
              <Field data-invalid={!!fieldState.error}>
                <FieldLabel htmlFor="requisition_date">Requisition date</FieldLabel>
                <DatePicker id="requisition_date" value={field.value} onChange={field.onChange} invalid={!!fieldState.error} />
                <FieldError errors={[fieldState.error]} />
              </Field>
            )} />
            <Controller control={form.control} name="department_id" render={({ field }) => (
              <Field>
                <FieldLabel>Requesting department</FieldLabel>
                <Select value={field.value ? String(field.value) : 'none'} onValueChange={(v) => field.onChange(v === 'none' ? null : Number(v))}>
                  <SelectTrigger className="w-full"><SelectValue /></SelectTrigger>
                  <SelectContent>
                    <SelectItem value="none">Not specified</SelectItem>
                    {departments.data?.map((d) => <SelectItem key={d.id} value={String(d.id)}>{d.name}</SelectItem>)}
                  </SelectContent>
                </Select>
              </Field>
            )} />
            <div className="md:col-span-2"><TextareaField form={form} name="notes" label="Notes" rows={2} /></div>
          </FieldGroup>
        </CardContent>
      </Card>

      <Card>
        <CardHeader className="flex-row items-start justify-between">
          <div className="space-y-1.5">
            <CardTitle>Lines</CardTitle>
            <CardDescription>Check every line against the document. Matched items consolidate in the analyzer after verification.</CardDescription>
          </div>
          <Button type="button" variant="outline" size="sm" onClick={() => lines.append({
            original_name: '', original_unit: null, item: null, auto_item_id: null, quantity: '', unit_id: null,
            department_id: form.getValues('department_id'), requested_date: null, notes: '', save_alias: false,
          })}><Plus /> Add line</Button>
        </CardHeader>
        <CardContent className="overflow-x-auto">
          {typeof errors.lines?.message === 'string' && <p className="mb-2 text-sm text-destructive">{errors.lines.message}</p>}
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead className="w-8">#</TableHead>
                <TableHead className="min-w-44">As written</TableHead>
                <TableHead className="min-w-56">Catalog item</TableHead>
                <TableHead className="w-28">Quantity</TableHead>
                <TableHead className="w-32">Unit</TableHead>
                <TableHead className="w-40">Destination</TableHead>
                <TableHead className="min-w-36">Notes</TableHead>
                <TableHead className="w-10" />
              </TableRow>
            </TableHeader>
            <TableBody>
              {lines.fields.map((field, i) => {
                const lineErrors = errors.lines?.[i]
                const item = form.watch(`lines.${i}.item`)
                const autoId = form.watch(`lines.${i}.auto_item_id`)
                const isManual = !!item && item.id !== autoId
                return (
                  <TableRow key={field.key} className="align-top">
                    <TableCell className="pt-4 text-muted-foreground">{i + 1}</TableCell>
                    <TableCell>
                      {field.id ? (
                        <div className="pt-2">
                          <div className="font-medium">{field.original_name}</div>
                          {field.original_unit && <div className="text-xs text-muted-foreground">Unit in document: {field.original_unit}</div>}
                        </div>
                      ) : (
                        <Input aria-label={`Line ${i + 1} name`} aria-invalid={!!lineErrors?.original_name} {...form.register(`lines.${i}.original_name`)} placeholder="Item as requested" />
                      )}
                    </TableCell>
                    <TableCell>
                      <Controller control={form.control} name={`lines.${i}.item`} render={({ field: f }) => (
                        <LineItemPicker
                          lineId={field.id}
                          originalName={form.getValues(`lines.${i}.original_name`)}
                          originalUnitId={form.getValues(`lines.${i}.unit_id`)}
                          value={f.value}
                          invalid={!!(lineErrors as Record<string, unknown> | undefined)?.item_id}
                          onChange={(picked) => {
                            f.onChange(picked)
                            // A line with no unit in the document takes the item's default unit.
                            if (picked?.default_unit_id && !form.getValues(`lines.${i}.unit_id`)) {
                              form.setValue(`lines.${i}.unit_id`, picked.default_unit_id, { shouldDirty: true })
                            }
                          }}
                        />
                      )} />
                      {item && !isManual && <Badge variant="secondary" className="mt-1.5">Auto-matched</Badge>}
                      {isManual && field.id && (
                        <Controller control={form.control} name={`lines.${i}.save_alias`} render={({ field: f }) => (
                          <label className="mt-1.5 flex items-center gap-2 text-xs text-muted-foreground">
                            <Checkbox checked={!!f.value} onCheckedChange={(c) => f.onChange(c === true)} />
                            Remember “{field.original_name}” as an alias
                          </label>
                        )} />
                      )}
                      <FieldError className="mt-1 text-xs" errors={[(lineErrors as Record<string, { message?: string }> | undefined)?.item_id]} />
                    </TableCell>
                    <TableCell>
                      <Input inputMode="decimal" aria-label={`Line ${i + 1} quantity`} aria-invalid={!!lineErrors?.quantity} {...form.register(`lines.${i}.quantity`)} className="text-right tabular-nums" />
                      <FieldError className="mt-1 text-xs" errors={[lineErrors?.quantity]} />
                    </TableCell>
                    <TableCell>
                      <Controller control={form.control} name={`lines.${i}.unit_id`} render={({ field: f, fieldState }) => (
                        <>
                          <Select value={f.value ? String(f.value) : ''} onValueChange={(v) => f.onChange(Number(v))}>
                            <SelectTrigger className="w-full" aria-label={`Line ${i + 1} unit`} aria-invalid={!!fieldState.error}><SelectValue placeholder="Unit" /></SelectTrigger>
                            <SelectContent>{unitOptions.map((u) => <SelectItem key={u.id} value={String(u.id)}>{u.name}</SelectItem>)}</SelectContent>
                          </Select>
                          <FieldError className="mt-1 text-xs" errors={[fieldState.error]} />
                        </>
                      )} />
                    </TableCell>
                    <TableCell>
                      <Controller control={form.control} name={`lines.${i}.department_id`} render={({ field: f }) => (
                        <Select value={f.value ? String(f.value) : 'none'} onValueChange={(v) => f.onChange(v === 'none' ? null : Number(v))}>
                          <SelectTrigger className="w-full" aria-label={`Line ${i + 1} destination`}><SelectValue /></SelectTrigger>
                          <SelectContent>
                            <SelectItem value="none">—</SelectItem>
                            {departments.data?.map((d) => <SelectItem key={d.id} value={String(d.id)}>{d.name}</SelectItem>)}
                          </SelectContent>
                        </Select>
                      )} />
                    </TableCell>
                    <TableCell><Input aria-label={`Line ${i + 1} notes`} {...form.register(`lines.${i}.notes`)} /></TableCell>
                    <TableCell>
                      <Button type="button" variant="ghost" size="icon" className="mt-0.5 size-8" aria-label={`Remove line ${i + 1}`} onClick={() => lines.remove(i)}><Trash2 /></Button>
                    </TableCell>
                  </TableRow>
                )
              })}
              {!lines.fields.length && (
                <TableRow><TableCell colSpan={8} className="py-8 text-center text-muted-foreground">No lines. Add the requested items manually.</TableCell></TableRow>
              )}
            </TableBody>
          </Table>
        </CardContent>
      </Card>
    </div>
  )
})
