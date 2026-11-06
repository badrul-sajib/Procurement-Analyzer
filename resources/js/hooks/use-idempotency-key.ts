import { useCallback, useRef } from 'react'
import { ApiError } from '@/lib/api'

/**
 * One key per user intent: a retry after a network failure or 5xx reuses it (the server replays
 * instead of applying twice); any definitive answer (2xx or 4xx) rotates it for the next attempt.
 */
export function useIdempotencyKey() {
  const key = useRef(crypto.randomUUID())
  const settle = useCallback((error: unknown) => {
    if (!error || (error instanceof ApiError && error.status < 500)) key.current = crypto.randomUUID()
  }, [])
  return { headers: () => ({ 'Idempotency-Key': key.current }), settle }
}
