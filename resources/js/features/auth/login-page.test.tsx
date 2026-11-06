import { screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { LoginPage } from '@/features/auth/login-page'
import { mockFetch, renderWithProviders } from '@/test/utils'

describe('LoginPage', () => {
  it('validates input before calling the API', async () => {
    const calls = mockFetch({ 'GET /auth/me': { status: 401, body: { message: 'Unauthenticated.' } } })
    renderWithProviders(<LoginPage />, { route: '/login' })

    await userEvent.click(await screen.findByRole('button', { name: /sign in/i }))

    expect(await screen.findByText('Enter a valid email address')).toBeInTheDocument()
    expect(screen.getByText('Password is required')).toBeInTheDocument()
    expect(calls.some((c) => c.url.includes('/auth/login'))).toBe(false)
  })

  it('shows the server credential error on the email field', async () => {
    const calls = mockFetch({
      'GET /auth/me': { status: 401, body: { message: 'Unauthenticated.' } },
      'GET /sanctum/csrf-cookie': { status: 204 },
      'POST /auth/login': { status: 422, body: { message: 'Invalid', errors: { email: ['These credentials do not match our records.'] } } },
    })
    renderWithProviders(<LoginPage />, { route: '/login' })

    await userEvent.type(await screen.findByLabelText('Email'), 'a@b.co')
    await userEvent.type(screen.getByLabelText('Password'), 'wrong')
    await userEvent.click(screen.getByRole('button', { name: /sign in/i }))

    expect(await screen.findByText('These credentials do not match our records.')).toBeInTheDocument()
    await waitFor(() => expect(calls.find((c) => c.url.includes('/auth/login'))?.body).toEqual({ email: 'a@b.co', password: 'wrong' }))
  })
})
