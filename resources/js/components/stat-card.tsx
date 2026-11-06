import type { LucideIcon } from 'lucide-react'
import { Card, CardContent } from '@/components/ui/card'
import { Skeleton } from '@/components/ui/skeleton'

export function StatCard({ label, value, hint, icon: Icon, loading }: { label: string; value: string; hint?: string; icon?: LucideIcon; loading?: boolean }) {
  return (
    <Card className="gap-0 py-4">
      <CardContent className="space-y-1 px-4">
        <div className="flex items-center justify-between text-sm text-muted-foreground">
          <span>{label}</span>
          {Icon && <Icon className="size-4" />}
        </div>
        {loading ? <Skeleton className="h-7 w-24" /> : <div className="text-2xl font-semibold tabular-nums tracking-tight">{value}</div>}
        {hint && <p className="text-xs text-muted-foreground">{hint}</p>}
      </CardContent>
    </Card>
  )
}
