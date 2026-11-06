import { flexRender, getCoreRowModel, useReactTable, type ColumnDef, type Row } from '@tanstack/react-table'
import { ArrowDown, ArrowUp, ChevronsUpDown, ChevronLeft, ChevronRight, Inbox } from 'lucide-react'
import { Fragment, type ReactNode } from 'react'
import { Button } from '@/components/ui/button'
import { Empty, EmptyDescription, EmptyHeader, EmptyMedia, EmptyTitle } from '@/components/ui/empty'
import { Skeleton } from '@/components/ui/skeleton'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import type { PageMeta } from '@/lib/api'
import { formatInt } from '@/lib/format'
import { cn } from '@/lib/utils'

export type DataTableColumn<T> = ColumnDef<T> & { meta?: { sortKey?: string; align?: 'right' | 'center'; className?: string } }

type Props<T> = {
  columns: DataTableColumn<T>[]
  data: T[] | undefined
  meta?: PageMeta
  isLoading?: boolean
  sort?: string
  onSortChange?: (sort: string) => void
  onPageChange?: (page: number) => void
  onRowClick?: (row: T) => void
  renderExpanded?: (row: Row<T>) => ReactNode
  isExpanded?: (row: T) => boolean
  emptyTitle?: string
  emptyDescription?: string
}

/** Server-driven table: pagination, sorting and filtering happen in the API. */
export function DataTable<T>({
  columns, data, meta, isLoading, sort, onSortChange, onPageChange, onRowClick, renderExpanded, isExpanded,
  emptyTitle = 'Nothing here yet', emptyDescription = 'Try changing the filters.',
}: Props<T>) {
  const table = useReactTable({ data: data ?? [], columns, getCoreRowModel: getCoreRowModel(), manualPagination: true, manualSorting: true })

  const toggleSort = (key: string) => {
    if (!onSortChange) return
    onSortChange(sort === key ? `-${key}` : sort === `-${key}` ? '' : key)
  }

  return (
    <div className="space-y-3">
      <div className="overflow-hidden rounded-lg border">
        <Table>
          <TableHeader className="bg-muted/50">
            {table.getHeaderGroups().map((group) => (
              <TableRow key={group.id}>
                {group.headers.map((header) => {
                  const m = (header.column.columnDef as DataTableColumn<T>).meta
                  const sortKey = m?.sortKey
                  const active = sortKey && (sort === sortKey || sort === `-${sortKey}`)
                  return (
                    <TableHead key={header.id} className={cn(m?.align === 'right' && 'text-right', m?.className)}>
                      {sortKey && onSortChange ? (
                        <Button variant="ghost" size="sm" className={cn('-mx-2 h-8', m?.align === 'right' && 'ml-auto flex')} onClick={() => toggleSort(sortKey)}>
                          {flexRender(header.column.columnDef.header, header.getContext())}
                          {active ? (sort?.startsWith('-') ? <ArrowDown /> : <ArrowUp />) : <ChevronsUpDown className="opacity-40" />}
                        </Button>
                      ) : (
                        flexRender(header.column.columnDef.header, header.getContext())
                      )}
                    </TableHead>
                  )
                })}
              </TableRow>
            ))}
          </TableHeader>
          <TableBody>
            {isLoading ? (
              Array.from({ length: 6 }).map((_, i) => (
                <TableRow key={i}>
                  {columns.map((_, j) => (
                    <TableCell key={j}><Skeleton className="h-5 w-full" /></TableCell>
                  ))}
                </TableRow>
              ))
            ) : table.getRowModel().rows.length ? (
              table.getRowModel().rows.map((row) => (
                <Fragment key={row.id}>
                  <TableRow
                    className={cn(onRowClick && 'cursor-pointer')}
                    onClick={onRowClick ? () => onRowClick(row.original) : undefined}
                  >
                    {row.getVisibleCells().map((cell) => {
                      const m = (cell.column.columnDef as DataTableColumn<T>).meta
                      return (
                        <TableCell key={cell.id} className={cn(m?.align === 'right' && 'text-right tabular-nums', m?.className)}>
                          {flexRender(cell.column.columnDef.cell, cell.getContext())}
                        </TableCell>
                      )
                    })}
                  </TableRow>
                  {renderExpanded && isExpanded?.(row.original) && (
                    <TableRow className="bg-muted/30 hover:bg-muted/30">
                      <TableCell colSpan={columns.length} className="p-0">{renderExpanded(row)}</TableCell>
                    </TableRow>
                  )}
                </Fragment>
              ))
            ) : (
              <TableRow className="hover:bg-transparent">
                <TableCell colSpan={columns.length}>
                  <Empty className="border-0 py-10">
                    <EmptyHeader>
                      <EmptyMedia variant="icon"><Inbox /></EmptyMedia>
                      <EmptyTitle>{emptyTitle}</EmptyTitle>
                      <EmptyDescription>{emptyDescription}</EmptyDescription>
                    </EmptyHeader>
                  </Empty>
                </TableCell>
              </TableRow>
            )}
          </TableBody>
        </Table>
      </div>
      {meta && meta.total > 0 && onPageChange && (
        <div className="flex items-center justify-between gap-2 text-sm text-muted-foreground">
          <span>
            {formatInt(meta.from)}–{formatInt(meta.to)} of {formatInt(meta.total)}
          </span>
          <div className="flex items-center gap-2">
            <span>Page {meta.current_page} of {meta.last_page}</span>
            <Button variant="outline" size="icon" className="size-8" aria-label="Previous page" disabled={meta.current_page <= 1} onClick={() => onPageChange(meta.current_page - 1)}>
              <ChevronLeft />
            </Button>
            <Button variant="outline" size="icon" className="size-8" aria-label="Next page" disabled={meta.current_page >= meta.last_page} onClick={() => onPageChange(meta.current_page + 1)}>
              <ChevronRight />
            </Button>
          </div>
        </div>
      )}
    </div>
  )
}
