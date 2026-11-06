import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Lock, Save } from 'lucide-react'
import { useEffect, useState } from 'react'
import { toast } from 'sonner'
import { PageHeader } from '@/components/page-header'
import { Alert, AlertDescription } from '@/components/ui/alert'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import { Skeleton } from '@/components/ui/skeleton'
import { Spinner } from '@/components/ui/spinner'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { api } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import { errorMessage } from '@/lib/forms'
import { titleCase } from '@/lib/format'

type Role = { id: number; name: string; locked: boolean; users_count: number; permissions: string[] }
type Group = { group: string; permissions: string[] }

/** Permission matrix: rows are permissions, columns are roles. Only Super Admin can change it. */
export function RolesPage() {
  const { can } = useAuth()
  const editable = can('roles.manage')
  const qc = useQueryClient()
  const roles = useQuery({ queryKey: ['roles'], queryFn: async () => (await api.get<{ data: Role[] }>('/roles')).data })
  const groups = useQuery({ queryKey: ['permissions'], queryFn: async () => (await api.get<{ data: Group[] }>('/permissions')).data })
  const [draft, setDraft] = useState<Record<number, string[]>>({})
  useEffect(() => {
    if (roles.data) setDraft(Object.fromEntries(roles.data.map((r) => [r.id, r.permissions])))
  }, [roles.data])

  const dirty = (roles.data ?? []).filter((r) => !r.locked && [...(draft[r.id] ?? [])].sort().join() !== [...r.permissions].sort().join())
  const save = useMutation({
    mutationFn: () => Promise.all(dirty.map((r) => api.put(`/roles/${r.id}`, { permissions: draft[r.id] }))),
    onSuccess: () => { qc.invalidateQueries({ queryKey: ['roles'] }); toast.success('Permissions updated') },
    onError: (e) => toast.error(errorMessage(e)),
  })

  if (roles.isLoading || groups.isLoading) return <Skeleton className="h-96" />
  const toggle = (roleId: number, perm: string, on: boolean) =>
    setDraft({ ...draft, [roleId]: on ? [...(draft[roleId] ?? []), perm] : (draft[roleId] ?? []).filter((p) => p !== perm) })

  return (
    <>
      <PageHeader title="Roles & permissions" description="Business rules check permissions, never role names. Changes apply on the user's next request and are audited."
        actions={editable && <Button onClick={() => save.mutate()} disabled={!dirty.length || save.isPending}>{save.isPending ? <Spinner /> : <Save />} Save {dirty.length > 0 && `(${dirty.length})`}</Button>} />
      {!editable && <Alert><Lock /><AlertDescription>Only a Super Admin can change role permissions.</AlertDescription></Alert>}
      <div className="overflow-x-auto rounded-lg border">
        <Table>
          <TableHeader className="bg-muted/50">
            <TableRow>
              <TableHead className="sticky left-0 min-w-56 bg-muted">Permission</TableHead>
              {roles.data?.map((r) => (
                <TableHead key={r.id} className="min-w-28 text-center text-xs whitespace-normal">
                  {r.name}<div className="font-normal text-muted-foreground">{r.users_count} user{r.users_count === 1 ? '' : 's'}</div>
                </TableHead>
              ))}
            </TableRow>
          </TableHeader>
          <TableBody>
            {groups.data?.map((g) => [
              <TableRow key={g.group} className="bg-muted/30 hover:bg-muted/30"><TableCell colSpan={(roles.data?.length ?? 0) + 1} className="font-semibold">{titleCase(g.group)}</TableCell></TableRow>,
              ...g.permissions.map((p) => (
                <TableRow key={p}>
                  <TableCell className="sticky left-0 bg-background font-mono text-xs">{p}</TableCell>
                  {roles.data?.map((r) => (
                    <TableCell key={r.id} className="text-center">
                      <Checkbox aria-label={`${r.name}: ${p}`} disabled={!editable || r.locked}
                        checked={r.locked || (draft[r.id] ?? []).includes(p)} onCheckedChange={(c) => toggle(r.id, p, c === true)} />
                    </TableCell>
                  ))}
                </TableRow>
              )),
            ])}
          </TableBody>
        </Table>
      </div>
    </>
  )
}
