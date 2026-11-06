import { render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { DataTable, type DataTableColumn } from '@/components/data-table'

type Row = { id: number; name: string }
const columns: DataTableColumn<Row>[] = [{ accessorKey: 'name', header: 'Name', meta: { sortKey: 'name' } }]
const meta = { current_page: 1, per_page: 2, total: 3, last_page: 2, from: 1, to: 2 }

describe('DataTable', () => {
  it('cycles sort asc → desc → none and pages through the server', async () => {
    const onSort = vi.fn()
    const onPage = vi.fn()
    const { rerender } = render(
      <DataTable columns={columns} data={[{ id: 1, name: 'A' }, { id: 2, name: 'B' }]} meta={meta} sort="" onSortChange={onSort} onPageChange={onPage} />,
    )
    await userEvent.click(screen.getByRole('button', { name: /name/i }))
    expect(onSort).toHaveBeenLastCalledWith('name')

    rerender(<DataTable columns={columns} data={[]} meta={meta} sort="name" onSortChange={onSort} onPageChange={onPage} />)
    await userEvent.click(screen.getByRole('button', { name: /name/i }))
    expect(onSort).toHaveBeenLastCalledWith('-name')

    rerender(<DataTable columns={columns} data={[{ id: 1, name: 'A' }]} meta={meta} sort="-name" onSortChange={onSort} onPageChange={onPage} />)
    await userEvent.click(screen.getByRole('button', { name: /name/i }))
    expect(onSort).toHaveBeenLastCalledWith('')

    expect(screen.getByRole('button', { name: 'Previous page' })).toBeDisabled()
    await userEvent.click(screen.getByRole('button', { name: 'Next page' }))
    expect(onPage).toHaveBeenCalledWith(2)
    expect(screen.getByText('1–2 of 3')).toBeInTheDocument()
  })

  it('shows an empty state', () => {
    render(<DataTable columns={columns} data={[]} emptyTitle="No items found" />)
    expect(screen.getByText('No items found')).toBeInTheDocument()
  })
})
