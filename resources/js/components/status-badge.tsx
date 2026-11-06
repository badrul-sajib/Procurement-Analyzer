import { Badge } from '@/components/ui/badge'
import { titleCase } from '@/lib/format'
import { cn } from '@/lib/utils'

type Tone = 'neutral' | 'info' | 'warning' | 'success' | 'danger'

const tones: Record<Tone, string> = {
  neutral: 'bg-muted text-muted-foreground border-transparent',
  info: 'bg-blue-500/10 text-blue-700 dark:text-blue-300 border-transparent',
  warning: 'bg-amber-500/15 text-amber-800 dark:text-amber-300 border-transparent',
  success: 'bg-emerald-500/15 text-emerald-800 dark:text-emerald-300 border-transparent',
  danger: 'bg-red-500/15 text-red-700 dark:text-red-300 border-transparent',
}

// Every status the API emits, mapped to one tone. Unknown values fall back to neutral.
const statusTones: Record<string, Tone> = {
  active: 'success', inactive: 'neutral',
  draft: 'neutral', uploaded: 'info', processing: 'info', extracted: 'warning', failed: 'danger',
  verified: 'info', approved: 'success', cancelled: 'neutral',
  pending_approval: 'warning', purchased: 'success', partially_purchased: 'warning', completed: 'success',
  pending: 'warning', confirmed: 'success', reversed: 'danger',
  not_purchased: 'neutral', not_delivered: 'neutral', partially_delivered: 'warning', fully_delivered: 'success',
  unpaid: 'danger', partially_paid: 'warning', paid: 'success',
}

export function StatusBadge({ status, label, className }: { status: string; label?: string; className?: string }) {
  return (
    <Badge variant="outline" className={cn(tones[statusTones[status] ?? 'neutral'], className)}>
      {label ?? titleCase(status)}
    </Badge>
  )
}
