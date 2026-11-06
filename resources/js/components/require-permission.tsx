import type { ReactNode } from 'react'
import { ShieldAlert } from 'lucide-react'
import { Empty, EmptyDescription, EmptyHeader, EmptyMedia, EmptyTitle } from '@/components/ui/empty'
import { useAuth } from '@/lib/auth'

/** UI-side guard only; the API enforces the same permission. */
export function RequirePermission({ permission, children }: { permission: string; children: ReactNode }) {
  const { can } = useAuth()
  if (can(permission)) return children
  return (
    <Empty>
      <EmptyHeader>
        <EmptyMedia variant="icon"><ShieldAlert /></EmptyMedia>
        <EmptyTitle>Access denied</EmptyTitle>
        <EmptyDescription>You don't have permission to view this page. Ask an administrator if you need access.</EmptyDescription>
      </EmptyHeader>
    </Empty>
  )
}
