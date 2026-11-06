import type { ComponentProps, ReactNode } from 'react'
import { Controller, type Control, type FieldPath, type FieldValues, type UseFormReturn } from 'react-hook-form'
import { Field, FieldContent, FieldDescription, FieldError, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Switch } from '@/components/ui/switch'
import { Textarea } from '@/components/ui/textarea'

type Base<T extends FieldValues> = { form: UseFormReturn<T, any, any>; name: FieldPath<T>; label: string; description?: ReactNode }

function errorOf<T extends FieldValues>(form: UseFormReturn<T, any, any>, name: FieldPath<T>) {
  return form.getFieldState(name, form.formState).error
}

export function TextField<T extends FieldValues>({ form, name, label, description, ...input }: Base<T> & Omit<ComponentProps<typeof Input>, 'form' | 'name'>) {
  const error = errorOf(form, name)
  return (
    <Field data-invalid={!!error}>
      <FieldLabel htmlFor={name}>{label}</FieldLabel>
      <Input id={name} aria-invalid={!!error} {...input} {...form.register(name)} />
      {description && <FieldDescription>{description}</FieldDescription>}
      <FieldError errors={[error]} />
    </Field>
  )
}

export function TextareaField<T extends FieldValues>({ form, name, label, description, ...input }: Base<T> & Omit<ComponentProps<typeof Textarea>, 'form' | 'name'>) {
  const error = errorOf(form, name)
  return (
    <Field data-invalid={!!error}>
      <FieldLabel htmlFor={name}>{label}</FieldLabel>
      <Textarea id={name} aria-invalid={!!error} {...input} {...form.register(name)} />
      {description && <FieldDescription>{description}</FieldDescription>}
      <FieldError errors={[error]} />
    </Field>
  )
}

/** Select over numeric ids; '' means none. Values are stored as numbers or null. */
export function SelectField<T extends FieldValues>({ form, name, label, description, options, placeholder = 'Select…', allowNone }: Base<T> & {
  options: { value: number | string; label: string }[]
  placeholder?: string
  allowNone?: boolean
}) {
  const error = errorOf(form, name)
  return (
    <Controller
      control={form.control as Control<T>}
      name={name}
      render={({ field }) => (
        <Field data-invalid={!!error}>
          <FieldLabel htmlFor={name}>{label}</FieldLabel>
          <Select
            value={field.value === null || field.value === undefined ? '' : String(field.value)}
            onValueChange={(v) => field.onChange(v === '__none' ? null : typeof options[0]?.value === 'number' ? Number(v) : v)}
          >
            <SelectTrigger id={name} className="w-full" aria-invalid={!!error}><SelectValue placeholder={placeholder} /></SelectTrigger>
            <SelectContent>
              {allowNone && <SelectItem value="__none">None</SelectItem>}
              {options.map((o) => <SelectItem key={o.value} value={String(o.value)}>{o.label}</SelectItem>)}
            </SelectContent>
          </Select>
          {description && <FieldDescription>{description}</FieldDescription>}
          <FieldError errors={[error]} />
        </Field>
      )}
    />
  )
}

export function SwitchField<T extends FieldValues>({ form, name, label, description }: Base<T>) {
  return (
    <Controller
      control={form.control as Control<T>}
      name={name}
      render={({ field }) => (
        <Field orientation="horizontal">
          <FieldContent>
            <FieldLabel htmlFor={name}>{label}</FieldLabel>
            {description && <FieldDescription>{description}</FieldDescription>}
          </FieldContent>
          <Switch id={name} checked={!!field.value} onCheckedChange={field.onChange} />
        </Field>
      )}
    />
  )
}
