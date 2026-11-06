import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useEffect } from 'react'
import { Controller, useForm } from 'react-hook-form'
import { toast } from 'sonner'
import { z } from 'zod'
import { PageHeader } from '@/components/page-header'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card'
import { Field, FieldContent, FieldDescription, FieldError, FieldGroup, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Skeleton } from '@/components/ui/skeleton'
import { Spinner } from '@/components/ui/spinner'
import { Switch } from '@/components/ui/switch'
import { api } from '@/lib/api'
import { applyServerErrors } from '@/lib/forms'
import { useSettings, type SystemSettings } from '@/lib/settings'

const schema = z.object({
  organization_name: z.string().min(1).max(120),
  currency_symbol: z.string().min(1).max(5),
  week_starts_on: z.enum(['saturday', 'sunday', 'monday']),
  require_po_approval: z.boolean(),
  max_upload_mb: z.coerce.number().int().min(1).max(50),
  ocr_enabled: z.boolean(),
})
type Values = z.infer<typeof schema>

export function SettingsPage() {
  const { data, isLoading } = useSettings()
  const queryClient = useQueryClient()
  const form = useForm<z.input<typeof schema>, unknown, Values>({ resolver: zodResolver(schema) })

  useEffect(() => {
    if (data) form.reset(data)
  }, [data, form])

  const save = useMutation({
    mutationFn: (values: Values) => api.put<{ data: SystemSettings }>('/settings', values),
    onSuccess: (res) => {
      queryClient.setQueryData(['settings'], res.data)
      toast.success('Settings saved')
    },
    onError: (e) => applyServerErrors(form, e),
  })

  const { errors } = form.formState
  return (
    <>
      <PageHeader title="Settings" description="Organization-wide preferences. Changes are recorded in the activity log." />
      <Card className="max-w-2xl">
        <CardHeader>
          <CardTitle>General</CardTitle>
          <CardDescription>Business time zone: {data?.business_timezone ?? '…'} (set at deployment).</CardDescription>
        </CardHeader>
        <form onSubmit={form.handleSubmit((v) => save.mutate(v))}>
          <CardContent>
            {isLoading ? (
              <div className="space-y-4">{[0, 1, 2, 3].map((i) => <Skeleton key={i} className="h-9 w-full" />)}</div>
            ) : (
              <FieldGroup>
                <Field data-invalid={!!errors.organization_name}>
                  <FieldLabel htmlFor="organization_name">Organization name</FieldLabel>
                  <Input id="organization_name" {...form.register('organization_name')} />
                  <FieldError errors={[errors.organization_name]} />
                </Field>
                <div className="grid gap-4 sm:grid-cols-2">
                  <Field data-invalid={!!errors.currency_symbol}>
                    <FieldLabel htmlFor="currency_symbol">Currency symbol</FieldLabel>
                    <Input id="currency_symbol" {...form.register('currency_symbol')} />
                    <FieldError errors={[errors.currency_symbol]} />
                  </Field>
                  <Field>
                    <FieldLabel>Week starts on</FieldLabel>
                    <Controller
                      control={form.control}
                      name="week_starts_on"
                      render={({ field }) => (
                        <Select value={field.value} onValueChange={field.onChange}>
                          <SelectTrigger className="w-full"><SelectValue /></SelectTrigger>
                          <SelectContent>
                            <SelectItem value="saturday">Saturday</SelectItem>
                            <SelectItem value="sunday">Sunday</SelectItem>
                            <SelectItem value="monday">Monday</SelectItem>
                          </SelectContent>
                        </Select>
                      )}
                    />
                  </Field>
                </div>
                <Field data-invalid={!!errors.max_upload_mb}>
                  <FieldLabel htmlFor="max_upload_mb">Maximum upload size (MB)</FieldLabel>
                  <Input id="max_upload_mb" type="number" min={1} max={50} {...form.register('max_upload_mb')} />
                  <FieldError errors={[errors.max_upload_mb]} />
                </Field>
                <Controller
                  control={form.control}
                  name="require_po_approval"
                  render={({ field }) => (
                    <Field orientation="horizontal">
                      <FieldContent>
                        <FieldLabel htmlFor="require_po_approval">Require purchase order approval</FieldLabel>
                        <FieldDescription>Submitted POs wait in “Pending Approval” until approved.</FieldDescription>
                      </FieldContent>
                      <Switch id="require_po_approval" checked={field.value} onCheckedChange={field.onChange} />
                    </Field>
                  )}
                />
                <Controller
                  control={form.control}
                  name="ocr_enabled"
                  render={({ field }) => (
                    <Field orientation="horizontal">
                      <FieldContent>
                        <FieldLabel htmlFor="ocr_enabled">OCR for scanned PDFs</FieldLabel>
                        <FieldDescription>Read image-only PDFs with the configured OCR engine.</FieldDescription>
                      </FieldContent>
                      <Switch id="ocr_enabled" checked={field.value} onCheckedChange={field.onChange} />
                    </Field>
                  )}
                />
              </FieldGroup>
            )}
          </CardContent>
          <CardFooter className="mt-6 justify-end">
            <Button type="submit" disabled={save.isPending || isLoading}>
              {save.isPending && <Spinner />} Save changes
            </Button>
          </CardFooter>
        </form>
      </Card>
    </>
  )
}
