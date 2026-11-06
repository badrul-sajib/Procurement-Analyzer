import { zodResolver } from '@hookform/resolvers/zod'
import { Pencil, Plus, Trash2 } from 'lucide-react'
import { useEffect, useState, type ReactNode } from 'react'
import { useForm } from 'react-hook-form'
import { toast } from 'sonner'
import { z } from 'zod'
import { ConfirmDialog } from '@/components/confirm-dialog'
import { SwitchField, TextField } from '@/components/form-fields'
import { PageHeader } from '@/components/page-header'
import { StatusBadge } from '@/components/status-badge'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog'
import { FieldGroup } from '@/components/ui/field'
import { Skeleton } from '@/components/ui/skeleton'
import { Spinner } from '@/components/ui/spinner'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { useConversionMutations, useConversions, useLookups, useSaveLookup, type Lookup, type LookupKind } from '@/features/catalog/api'
import { ConversionDialog } from '@/features/master-data/conversion-dialog'
import { useAuth } from '@/lib/auth'
import { applyServerErrors, errorMessage } from '@/lib/forms'
import { formatQty } from '@/lib/format'

const schema = z.object({
  code: z.string().trim().min(1, 'Code is required').max(30).regex(/^[A-Za-z0-9._-]+$/, 'Letters, numbers, . _ - only'),
  name: z.string().trim().min(1, 'Name is required').max(150),
  synonyms_text: z.string().optional(),
  is_active: z.boolean(),
})
type Values = z.infer<typeof schema>

function LookupDialog({ kind, record, trigger }: { kind: LookupKind; record?: Lookup; trigger: ReactNode }) {
  const [open, setOpen] = useState(false)
  const save = useSaveLookup(kind)
  const form = useForm<Values>({ resolver: zodResolver(schema) })
  useEffect(() => {
    if (open) form.reset({ code: record?.code ?? '', name: record?.name ?? '', synonyms_text: record?.synonyms?.join(', ') ?? '', is_active: record?.is_active ?? true })
  }, [open, record, form])

  const onSubmit = form.handleSubmit(async ({ synonyms_text, ...values }) => {
    try {
      const synonyms = kind === 'units' ? (synonyms_text ?? '').split(',').map((s) => s.trim()).filter(Boolean) : undefined
      await save.mutateAsync({ ...values, id: record?.id, ...(synonyms ? { synonyms } : {}) })
      toast.success('Saved')
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
          <DialogHeader><DialogTitle>{record ? `Edit ${record.name}` : 'Add'}</DialogTitle></DialogHeader>
          <FieldGroup className="my-6">
            <div className="grid gap-4 sm:grid-cols-[10rem_1fr]">
              <TextField form={form} name="code" label="Code" />
              <TextField form={form} name="name" label="Name" />
            </div>
            {kind === 'units' && <TextField form={form} name="synonyms_text" label="Synonyms" placeholder="pcs, pieces, nos" description="Comma-separated spellings recognised in documents." />}
            <SwitchField form={form} name="is_active" label="Active" />
          </FieldGroup>
          <DialogFooter><Button type="submit" disabled={save.isPending}>{save.isPending && <Spinner />} Save</Button></DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}

function LookupTab({ kind, label }: { kind: LookupKind; label: string }) {
  const { can } = useAuth()
  const { data, isLoading } = useLookups(kind)
  const editable = can('master_data.manage')
  return (
    <div className="space-y-3">
      {editable && <LookupDialog kind={kind} trigger={<Button size="sm"><Plus /> Add {label}</Button>} />}
      <div className="overflow-hidden rounded-lg border">
        <Table>
          <TableHeader className="bg-muted/50">
            <TableRow>
              <TableHead className="w-32">Code</TableHead><TableHead>Name</TableHead>
              {kind === 'units' && <TableHead>Synonyms</TableHead>}
              <TableHead>Status</TableHead><TableHead className="w-12" />
            </TableRow>
          </TableHeader>
          <TableBody>
            {isLoading && <TableRow><TableCell colSpan={5}><Skeleton className="h-5" /></TableCell></TableRow>}
            {data?.map((r) => (
              <TableRow key={r.id}>
                <TableCell className="font-mono text-xs">{r.code}</TableCell>
                <TableCell className="font-medium">{r.name}</TableCell>
                {kind === 'units' && <TableCell className="text-muted-foreground">{r.synonyms?.join(', ') || '—'}</TableCell>}
                <TableCell><StatusBadge status={r.is_active ? 'active' : 'inactive'} /></TableCell>
                <TableCell>{editable && <LookupDialog kind={kind} record={r} trigger={<Button variant="ghost" size="icon" className="size-7" aria-label={`Edit ${r.name}`}><Pencil /></Button>} />}</TableCell>
              </TableRow>
            ))}
            {data && !data.length && <TableRow><TableCell colSpan={5} className="text-muted-foreground">Nothing added yet.</TableCell></TableRow>}
          </TableBody>
        </Table>
      </div>
    </div>
  )
}

function GlobalConversionsTab() {
  const { can } = useAuth()
  const { data, isLoading } = useConversions({ scope: 'global' })
  const { remove } = useConversionMutations()
  return (
    <div className="space-y-3">
      {can('master_data.manage') && <ConversionDialog trigger={<Button size="sm"><Plus /> Add conversion</Button>} />}
      <div className="overflow-hidden rounded-lg border">
        <Table>
          <TableHeader className="bg-muted/50"><TableRow><TableHead>Rule</TableHead><TableHead className="w-12" /></TableRow></TableHeader>
          <TableBody>
            {isLoading && <TableRow><TableCell colSpan={2}><Skeleton className="h-5" /></TableCell></TableRow>}
            {data?.map((c) => (
              <TableRow key={c.id}>
                <TableCell>1 {c.from_unit.name} = {formatQty(c.factor)} {c.to_unit.name}</TableCell>
                <TableCell>
                  {can('master_data.manage') && (
                    <ConfirmDialog trigger={<Button variant="ghost" size="icon" className="size-7" aria-label="Delete conversion"><Trash2 /></Button>}
                      title="Delete conversion?" description="Requisitions verified earlier keep the factor they were verified with." destructive confirmLabel="Delete"
                      onConfirm={() => remove.mutateAsync(c.id).catch((e) => toast.error(errorMessage(e)))} />
                  )}
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </div>
      <p className="text-sm text-muted-foreground">Item-specific rules are managed on each item's page and take precedence over these.</p>
    </div>
  )
}

export function MasterDataPage() {
  return (
    <>
      <PageHeader title="Master data" description="Units, conversions, item categories and departments used across the system." />
      <Tabs defaultValue="units">
        <TabsList>
          <TabsTrigger value="units">Units</TabsTrigger>
          <TabsTrigger value="conversions">Conversions</TabsTrigger>
          <TabsTrigger value="categories">Item categories</TabsTrigger>
          <TabsTrigger value="departments">Departments</TabsTrigger>
        </TabsList>
        <TabsContent value="units"><LookupTab kind="units" label="unit" /></TabsContent>
        <TabsContent value="conversions"><GlobalConversionsTab /></TabsContent>
        <TabsContent value="categories"><LookupTab kind="item-categories" label="category" /></TabsContent>
        <TabsContent value="departments"><LookupTab kind="departments" label="department" /></TabsContent>
      </Tabs>
    </>
  )
}
