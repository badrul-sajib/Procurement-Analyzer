// Display-only formatting. Values arrive as decimal strings and are never used for arithmetic here.
const moneyFmt = new Intl.NumberFormat('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const qtyFmt = new Intl.NumberFormat('en-IN', { maximumFractionDigits: 3 })
const intFmt = new Intl.NumberFormat('en-IN')

export function formatMoney(value: string | number | null | undefined, symbol = '৳'): string {
  if (value === null || value === undefined || value === '') return '—'
  return `${symbol}${moneyFmt.format(Number(value))}`
}

export function formatQty(value: string | number | null | undefined): string {
  if (value === null || value === undefined || value === '') return '—'
  return qtyFmt.format(Number(value))
}

export function formatInt(value: number | null | undefined): string {
  return value === null || value === undefined ? '—' : intFmt.format(value)
}

/** "2026-11-05" → "05 Nov 2026" without time-zone shifting (business dates are calendar dates). */
export function formatDate(value: string | null | undefined): string {
  if (!value) return '—'
  const [y, m, d] = value.slice(0, 10).split('-').map(Number)
  return new Date(Date.UTC(y, m - 1, d)).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric', timeZone: 'UTC' })
}

export function formatDateTime(value: string | null | undefined): string {
  if (!value) return '—'
  return new Date(value).toLocaleString('en-GB', {
    day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit', timeZone: 'Asia/Dhaka',
  })
}

export function titleCase(value: string): string {
  return value.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase())
}
