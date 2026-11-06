import { createContext, useCallback, useContext, useEffect, type ReactNode } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, ApiError, ensureCsrfCookie, setUnauthenticatedHandler } from '@/lib/api'

export type AuthUser = {
  id: number
  name: string
  email: string
  roles: string[]
  permissions: string[]
}

type AuthContextValue = {
  user: AuthUser | null
  isLoading: boolean
  login: (email: string, password: string) => Promise<AuthUser>
  logout: () => Promise<void>
  can: (permission: string) => boolean
}

const AuthContext = createContext<AuthContextValue | null>(null)

export function AuthProvider({ children }: { children: ReactNode }) {
  const queryClient = useQueryClient()

  const me = useQuery({
    queryKey: ['auth', 'me'],
    queryFn: async () => {
      try {
        return (await api.get<{ data: AuthUser }>('/auth/me')).data
      } catch (e) {
        if (e instanceof ApiError && e.status === 401) return null
        throw e
      }
    },
    staleTime: 5 * 60_000,
    retry: false,
  })

  useEffect(() => {
    setUnauthenticatedHandler(() => queryClient.setQueryData(['auth', 'me'], null))
  }, [queryClient])

  const loginMutation = useMutation({
    mutationFn: async ({ email, password }: { email: string; password: string }) => {
      await ensureCsrfCookie()
      return (await api.post<{ data: AuthUser }>('/auth/login', { email, password })).data
    },
    onSuccess: (user) => queryClient.setQueryData(['auth', 'me'], user),
  })

  const logout = useCallback(async () => {
    await api.post('/auth/logout').catch(() => undefined)
    queryClient.clear()
    queryClient.setQueryData(['auth', 'me'], null)
  }, [queryClient])

  const user = me.data ?? null
  const can = useCallback((permission: string) => !!user?.permissions.includes(permission), [user])

  return (
    <AuthContext.Provider
      value={{
        user,
        isLoading: me.isLoading,
        login: (email, password) => loginMutation.mutateAsync({ email, password }),
        logout,
        can,
      }}
    >
      {children}
    </AuthContext.Provider>
  )
}

export function useAuth() {
  const ctx = useContext(AuthContext)
  if (!ctx) throw new Error('useAuth must be used inside <AuthProvider>')
  return ctx
}
