import { addQty, compareQty, lineTotal, sumMoney } from '@/lib/decimal'

describe('decimal', () => {
  it('matches the server line_total formula without float drift', () => {
    expect(lineTotal('25', '450.50', '100', '50.25')).toBe('11212.75')
    expect(lineTotal('3', '33.3333')).toBe('100.00')
    expect(lineTotal('0.1', '0.2')).toBe('0.02')
    expect(lineTotal('2.5', '0.0050')).toBe('0.01') // 0.0125 rounds half away from zero
  })
  it('sums money and compares quantities exactly', () => {
    expect(sumMoney(['0.10', '0.20', null])).toBe('0.30')
    expect(compareQty('10', '10.000')).toBe(0)
    expect(compareQty('10.001', '10')).toBe(1)
    expect(addQty('1.5', '2.25')).toBe('3.750')
  })
})
