import { useCallback, useMemo } from 'react'
import { useSearchParams } from 'react-router'

/**
 * List state (page, sort, search, filters) lives in the URL so views are shareable and
 * survive reloads. Changing anything but the page resets to page 1.
 */
export function useListParams(defaults: Record<string, string> = {}) {
  const [searchParams, setSearchParams] = useSearchParams()

  const params = useMemo(() => {
    const result: Record<string, string> = { ...defaults }
    searchParams.forEach((value, key) => (result[key] = value))
    return result
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [searchParams])

  const set = useCallback(
    (changes: Record<string, string | number | null | undefined>) => {
      setSearchParams(
        (prev) => {
          const next = new URLSearchParams(prev)
          for (const [key, value] of Object.entries(changes)) {
            if (value === null || value === undefined || value === '') next.delete(key)
            else next.set(key, String(value))
          }
          if (!('page' in changes)) next.delete('page')
          return next
        },
        { replace: true },
      )
    },
    [setSearchParams],
  )

  return { params, set, page: Number(params.page ?? 1) }
}
