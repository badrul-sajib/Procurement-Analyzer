import { useState, type ReactNode } from 'react'
import {
  AlertDialog, AlertDialogAction, AlertDialogCancel, AlertDialogContent, AlertDialogDescription, AlertDialogFooter,
  AlertDialogHeader, AlertDialogTitle, AlertDialogTrigger,
} from '@/components/ui/alert-dialog'
import { Field, FieldLabel } from '@/components/ui/field'
import { Spinner } from '@/components/ui/spinner'
import { Textarea } from '@/components/ui/textarea'

type Props = {
  trigger: ReactNode
  title: string
  description: ReactNode
  confirmLabel?: string
  destructive?: boolean
  /** Ask for a reason (cancellations and reversals must say why). */
  requireReason?: boolean
  onConfirm: (reason: string) => Promise<unknown>
}

export function ConfirmDialog({ trigger, title, description, confirmLabel = 'Confirm', destructive, requireReason, onConfirm }: Props) {
  const [open, setOpen] = useState(false)
  const [reason, setReason] = useState('')
  const [pending, setPending] = useState(false)

  const confirm = async (e: React.MouseEvent) => {
    e.preventDefault()
    setPending(true)
    try {
      await onConfirm(reason.trim())
      setOpen(false)
      setReason('')
    } finally {
      setPending(false)
    }
  }

  return (
    <AlertDialog open={open} onOpenChange={setOpen}>
      <AlertDialogTrigger asChild>{trigger}</AlertDialogTrigger>
      <AlertDialogContent>
        <AlertDialogHeader>
          <AlertDialogTitle>{title}</AlertDialogTitle>
          <AlertDialogDescription asChild><div>{description}</div></AlertDialogDescription>
        </AlertDialogHeader>
        {requireReason && (
          <Field>
            <FieldLabel htmlFor="confirm-reason">Reason</FieldLabel>
            <Textarea id="confirm-reason" value={reason} onChange={(e) => setReason(e.target.value)} maxLength={500} />
          </Field>
        )}
        <AlertDialogFooter>
          <AlertDialogCancel disabled={pending}>Back</AlertDialogCancel>
          <AlertDialogAction
            onClick={confirm}
            disabled={pending || (requireReason && reason.trim().length < 3)}
            className={destructive ? 'bg-destructive text-white hover:bg-destructive/90' : undefined}
          >
            {pending && <Spinner />} {confirmLabel}
          </AlertDialogAction>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  )
}
