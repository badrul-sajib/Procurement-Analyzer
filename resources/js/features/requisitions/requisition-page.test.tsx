import { screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { Route, Routes } from 'react-router'
import { RequisitionPage } from '@/features/requisitions/requisition-page'
import { mockFetch, renderWithProviders } from '@/test/utils'

const user = { id: 1, name: 'Officer', email: 'o@x.co', roles: ['Requisition Officer'], permissions: ['requisitions.view', 'requisitions.update', 'requisitions.verify', 'items.view'] }
const requisition = {
  id: 7, reference_no: 'REQ-2026-000007', external_reference: 'ACC/118', title: 'November stationery', requisition_date: '2026-11-05',
  status: 'extracted', notes: null, department: null, uploaded_at: '2026-11-05T05:00:00Z', created_by: { id: 1, name: 'Officer' },
  verified_at: null, approved_at: null, cancelled_at: null, cancellation_reason: null, file: { original_name: 'req.txt', size_bytes: 10, mime_type: 'text/plain' },
  line_count: 1, item_count: 0, total_requested_quantity: null, processing_logs: [], possible_duplicates: [],
  lines: [{ id: 70, line_no: 1, original_name: 'A4 Papers', original_unit: 'Reams', description: null, quantity: '10.000', unit: { id: 2, code: 'ream', name: 'Ream' },
    item: null, match_type: null, conversion_factor: null, base_quantity: null, approved_quantity: null, requested_date: null, department: null, notes: null }],
}

describe('RequisitionPage review', () => {
  it('offers match suggestions and shows server verification errors on the line', async () => {
    const calls = mockFetch({
      'GET /auth/me': { body: { data: user } },
      'GET /requisitions/7': { body: { data: requisition } },
      'GET /units': { body: { data: [{ id: 2, code: 'ream', name: 'Ream', is_active: true }] } },
      'GET /departments': { body: { data: [] } },
      'GET /requisition-items/70/match-suggestions': { body: { data: [{ score: 94, item: { id: 5, code: 'ITM-1', name: 'A4 Paper', default_unit: { id: 2, code: 'ream', name: 'Ream' } } }] } },
      'POST /requisitions/7/verify': { status: 422, body: { message: 'Needs attention', code: 'verification_failed', errors: { 'lines.0.item_id': ['Line 1: match "A4 Papers" to a catalog item.'] } } },
    })

    renderWithProviders(<Routes><Route path="/requisitions/:id" element={<RequisitionPage />} /></Routes>, { route: '/requisitions/7' })

    expect(await screen.findByText('November stationery')).toBeInTheDocument()
    expect(await screen.findByRole('button', { name: /A4 Paper 94%/ })).toBeInTheDocument() // suggestion offered, not applied

    await userEvent.click(screen.getByRole('button', { name: /verify/i }))

    expect(await screen.findByText('Line 1: match "A4 Papers" to a catalog item.')).toBeInTheDocument()
    await waitFor(() => expect(calls.some((c) => c.method === 'POST' && c.url.endsWith('/requisitions/7/verify'))).toBe(true))
    expect(calls.some((c) => c.method === 'PUT')).toBe(false) // nothing changed, so nothing saved

    // Picking the suggestion fills the line and offers to remember the alias.
    await userEvent.click(screen.getByRole('button', { name: /A4 Paper 94%/ }))
    const row = screen.getByText('A4 Papers').closest('tr')!
    expect(within(row).getByText(/Remember “A4 Papers” as an alias/)).toBeInTheDocument()
  })
})
