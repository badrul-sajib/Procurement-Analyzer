import { zodResolver } from '@hookform/resolvers/zod'
import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Pencil, Plus } from 'lucide-react'
import { useEffect, useState, type ReactNode } from 'react'
import { Controller, useForm } from 'react-hook-form'
import { toast } from 'sonner'
import { z } from 'zod'
import { DataTable, type DataTableColumn } from '@/components/data-table'
import { SwitchField, TextField } from '@/components/form-fields'
import { PageHeader } from '@/components/page-header'
import { SearchInput } from '@/components/search-input'
import { StatusBadge } from '@/components/status-badge'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Field, FieldGroup, FieldLabel } from '@/components/ui/field'
import { Sheet, SheetContent, SheetDescription, SheetFooter, SheetHeader, SheetTitle, SheetTrigger } from '@/components/ui/sheet'
import { Spinner } from '@/components/ui/spinner'
import { useListParams } from '@/hooks/use-list-params'
import { api, type Paginated } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import { applyServerErrors } from '@/lib/forms'
import { formatDateTime } from '@/lib/format'

type User = { id: number; name: string; email: string; is_active: boolean; last_login_at: string | null; roles: string[] }
type Role = { id: number; name: string }

const schema = z.object({
  name: z.string().trim().min(1, 'Name is required').max(120),
  email: z.email('Enter a valid email'),
  password: z.string().max(255).optional(),
  is_active: z.boolean(),
  roles: z.array(z.string()),
})
type Values = z.infer<typeof schema>

function UserSheet({ user, trigger }: { user?: User; trigger: ReactNode }) {
  const { user: me } = useAuth()
  const qc = useQueryClient()
  const [open, setOpen] = useState(false)
  const roles = useQuery({ queryKey: ['roles'], queryFn: async () => (await api.get<{ data: Role[] }>('/roles')).data, enabled: open })
  const form = useForm<Values>({ resolver: zodResolver(schema) })
  useEffect(() => {
    if (open) form.reset({ name: user?.name ?? '', email: user?.email ?? '', password: '', is_active: user?.is_active ?? true, roles: user?.roles ?? [] })
  }, [open, user, form])
  const save = useMutation({
    mutationFn: (v: Values) => (user ? api.put(`/users/${user.id}`, { ...v, password: v.password || null }) : api.post('/users', v)),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['users'] }); toast.success('User saved'); setOpen(false) },
    onError: (e) => applyServerErrors(form, e),
  })
  const canGrantRoot = me?.roles.includes('Super Admin')

  return (
    <Sheet open={open} onOpenChange={setOpen}>
      <SheetTrigger asChild>{trigger}</SheetTrigger>
      <SheetContent className="w-full overflow-y-auto sm:max-w-md">
        <form onSubmit={form.handleSubmit((v) => save.mutate(v))} className="flex h-full flex-col">
          <SheetHeader><SheetTitle>{user ? `Edit ${user.name}` : 'New user'}</SheetTitle><SheetDescription>Access is granted through roles.</SheetDescription></SheetHeader>
          <FieldGroup className="flex-1 px-4">
            <TextField form={form} name="name" label="Name" />
            <TextField form={form} name="email" label="Email" type="email" autoComplete="off" />
            <TextField form={form} name="password" label={user ? 'New password (leave blank to keep)' : 'Password'} type="password" autoComplete="new-password"
              description="At least 12 characters with letters and numbers." />
            <Controller control={form.control} name="roles" render={({ field }) => (
              <Field>
                <FieldLabel>Roles</FieldLabel>
                <div className="grid gap-2">
                  {roles.data?.filter((r) => r.name !== 'Super Admin' || canGrantRoot).map((r) => (
                    <label key={r.id} className="flex items-center gap-2 text-sm">
                      <Checkbox checked={field.value?.includes(r.name)} onCheckedChange={(c) => field.onChange(c ? [...field.value, r.name] : field.value.filter((n) => n !== r.name))} />
                      {r.name}
                    </label>
                  ))}
                </div>
              </Field>
            )} />
            <SwitchField form={form} name="is_active" label="Active" description="Inactive users can't sign in; their API tokens are revoked." />
          </FieldGroup>
          <SheetFooter><Button type="submit" disabled={save.isPending}>{save.isPending && <Spinner />} Save</Button></SheetFooter>
        </form>
      </SheetContent>
    </Sheet>
  )
}

export function UsersPage() {
  const { params, set, page } = useListParams({ sort: 'name' })
  const { data, isLoading } = useQuery({ queryKey: ['users', params, page], queryFn: () => api.get<Paginated<User>>('/users', { ...params, page }), placeholderData: keepPreviousData })
  const columns: DataTableColumn<User>[] = [
    { accessorKey: 'name', header: 'Name', meta: { sortKey: 'name' }, cell: ({ row }) => <span className="font-medium">{row.original.name}</span> },
    { accessorKey: 'email', header: 'Email', meta: { sortKey: 'email' } },
    { id: 'roles', header: 'Roles', cell: ({ row }) => <div className="flex flex-wrap gap-1">{row.original.roles.map((r) => <Badge key={r} variant="secondary">{r}</Badge>)}</div> },
    { id: 'last_login', header: 'Last sign-in', meta: { sortKey: 'last_login_at' }, cell: ({ row }) => formatDateTime(row.original.last_login_at) },
    { id: 'status', header: 'Status', cell: ({ row }) => <StatusBadge status={row.original.is_active ? 'active' : 'inactive'} /> },
    { id: 'edit', header: '', cell: ({ row }) => <UserSheet user={row.original} trigger={<Button variant="ghost" size="icon" className="size-7" aria-label={`Edit ${row.original.name}`}><Pencil /></Button>} /> },
  ]
  return (
    <>
      <PageHeader title="Users" description="People who can sign in, and the roles that decide what they can do."
        actions={<UserSheet trigger={<Button><Plus /> New user</Button>} />} />
      <SearchInput value={params.search ?? ''} onChange={(search) => set({ search })} placeholder="Name or email…" />
      <DataTable columns={columns} data={data?.data} meta={data?.meta} isLoading={isLoading} sort={params.sort} onSortChange={(sort) => set({ sort })} onPageChange={(p) => set({ page: p })} />
    </>
  )
}
