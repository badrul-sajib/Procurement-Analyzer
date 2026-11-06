import type { FieldValues, Path, UseFormReturn } from 'react-hook-form'
import { toast } from 'sonner'
import { ApiError } from '@/lib/api'

/** Push server-side 422 field errors into the form; toast anything else. Server validation is authoritative. */
export function applyServerErrors<T extends FieldValues>(form: UseFormReturn<T>, error: unknown) {
  if (error instanceof ApiError && error.status === 422 && Object.keys(error.errors).length) {
    for (const [field, messages] of Object.entries(error.errors)) {
      form.setError(field as Path<T>, { type: 'server', message: messages[0] })
    }
    if (error.code) toast.error(error.message)
    return
  }
  toast.error(error instanceof Error ? error.message : 'Something went wrong')
}

export function errorMessage(error: unknown): string {
  return error instanceof Error ? error.message : 'Something went wrong'
}
