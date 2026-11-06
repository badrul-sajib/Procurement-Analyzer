/**
 * Exact decimal arithmetic on strings via BigInt, for previews only (the API computes the
 * authoritative amounts). Avoids float artefacts such as 0.1 + 0.2 = 0.30000000000000004.
 */
const parse = (value: string | number | null | undefined, scale: number): bigint => {
  const s = String(value ?? '0').trim() || '0'
  if (!/^-?\d*(\.\d*)?$/.test(s)) return 0n
  const negative = s.startsWith('-')
  const [int = '0', frac = ''] = s.replace('-', '').split('.')
  const digits = BigInt((int || '0') + frac.padEnd(scale, '0').slice(0, scale))
  return negative ? -digits : digits
}

const format = (value: bigint, scale: number): string => {
  const negative = value < 0n
  const abs = (negative ? -value : value).toString().padStart(scale + 1, '0')
  const out = scale ? `${abs.slice(0, -scale)}.${abs.slice(-scale)}` : abs
  return negative ? `-${out}` : out
}

/** Round half away from zero from scale `from` to scale `to`. */
const rescale = (value: bigint, from: number, to: number): bigint => {
  const factor = 10n ** BigInt(from - to)
  const half = factor / 2n
  return value >= 0n ? (value + half) / factor : -((-value + half) / factor)
}

/** round(qty × price, 2) − discount + tax, like the line_total generated column. */
export function lineTotal(qty: string, price: string, discount = '0', tax = '0'): string {
  const gross = rescale(parse(qty, 3) * parse(price, 4), 7, 2)
  return format(gross - parse(discount, 2) + parse(tax, 2), 2)
}

export function sumMoney(values: (string | null | undefined)[]): string {
  return format(values.reduce((acc, v) => acc + parse(v, 2), 0n), 2)
}

export function compareQty(a: string, b: string): number {
  const d = parse(a, 3) - parse(b, 3)
  return d === 0n ? 0 : d > 0n ? 1 : -1
}

export function addQty(a: string, b: string): string {
  return format(parse(a, 3) + parse(b, 3), 3)
}
