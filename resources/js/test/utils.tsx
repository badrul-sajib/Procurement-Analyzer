import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { render } from '@testing-library/react'
import type { ReactElement } from 'react'
import { MemoryRouter } from 'react-router'
import { TooltipProvider } from '@/components/ui/tooltip'
import { AuthProvider } from '@/lib/auth'

export function renderWithProviders(ui: ReactElement, { route = '/' } = {}) {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  return render(
    <QueryClientProvider client={client}>
      <AuthProvider>
        <TooltipProvider>
          <MemoryRouter initialEntries={[route]}>{ui}</MemoryRouter>
        </TooltipProvider>
      </AuthProvider>
    </QueryClientProvider>,
  )
}

/** Route fetch calls by "METHOD /path" to canned JSON responses. */
export function mockFetch(routes: Record<string, { status?: number; body?: unknown }>) {
  const calls: { method: string; url: string; body?: unknown }[] = []
  globalThis.fetch = vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
    const url = String(input)
    const method = init?.method ?? 'GET'
    calls.push({ method, url, body: init?.body ? JSON.parse(String(init.body)) : undefined })
    const key = Object.keys(routes).find((k) => {
      const [m, p] = k.split(' ')
      return m === method && url.split('?')[0].endsWith(p)
    })
    const res = key ? routes[key] : { status: 404, body: { message: 'Not mocked' } }
    return new Response(res.body === undefined ? null : JSON.stringify(res.body), { status: res.status ?? 200 })
  }) as typeof fetch
  return calls
}
