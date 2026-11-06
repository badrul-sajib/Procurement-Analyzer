import { formatDate, formatMoney, formatQty } from '@/lib/format'

describe('format', () => {
  it('formats money with the taka sign and lakh grouping', () => {
    expect(formatMoney('125000.5')).toBe('৳1,25,000.50')
    expect(formatMoney(null)).toBe('—')
  })
  it('trims insignificant quantity decimals', () => {
    expect(formatQty('30.000')).toBe('30')
    expect(formatQty('2.125')).toBe('2.125')
  })
  it('formats business dates without time-zone shifting', () => {
    expect(formatDate('2026-11-05')).toBe('05 Nov 2026')
  })
})
