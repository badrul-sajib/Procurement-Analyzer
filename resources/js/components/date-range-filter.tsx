import { format, parseISO } from 'date-fns'
import { CalendarIcon } from 'lucide-react'
import type { DateRange as DayRange } from 'react-day-picker'
import { Button } from '@/components/ui/button'
import { Calendar } from '@/components/ui/calendar'
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'

export const PERIODS = [
  ['all', 'All time'], ['today', 'Today'], ['yesterday', 'Yesterday'], ['this_week', 'This week'], ['last_week', 'Last week'],
  ['this_month', 'This month'], ['last_month', 'Last month'], ['this_year', 'This year'], ['custom', 'Custom range'],
] as const

export type DateFilter = { period?: string; date_from?: string; date_to?: string }

/** Preset periods are resolved by the API in the business time zone; custom ranges are calendar dates. */
export function DateRangeFilter({ value, onChange, allowAll = true }: { value: DateFilter; onChange: (v: DateFilter) => void; allowAll?: boolean }) {
  const period = value.period ?? (allowAll ? 'all' : 'this_month')
  const range: DayRange | undefined = value.date_from ? { from: parseISO(value.date_from), to: value.date_to ? parseISO(value.date_to) : undefined } : undefined

  return (
    <div className="flex flex-wrap items-center gap-2">
      <Select
        value={period}
        onValueChange={(p) => onChange(p === 'all' ? { period: undefined, date_from: undefined, date_to: undefined } : p === 'custom' ? { ...value, period: 'custom' } : { period: p, date_from: undefined, date_to: undefined })}
      >
        <SelectTrigger className="w-40" aria-label="Date range"><CalendarIcon /><SelectValue /></SelectTrigger>
        <SelectContent>
          {PERIODS.filter(([k]) => allowAll || k !== 'all').map(([k, label]) => <SelectItem key={k} value={k}>{label}</SelectItem>)}
        </SelectContent>
      </Select>
      {period === 'custom' && (
        <Popover>
          <PopoverTrigger asChild>
            <Button variant="outline" className="font-normal">
              {range?.from ? `${format(range.from, 'dd MMM yyyy')} – ${range.to ? format(range.to, 'dd MMM yyyy') : '…'}` : 'Pick dates'}
            </Button>
          </PopoverTrigger>
          <PopoverContent className="w-auto p-0" align="start">
            <Calendar
              mode="range"
              numberOfMonths={2}
              selected={range}
              onSelect={(r) => onChange({
                period: 'custom',
                date_from: r?.from ? format(r.from, 'yyyy-MM-dd') : undefined,
                date_to: r?.to ? format(r.to, 'yyyy-MM-dd') : r?.from ? format(r.from, 'yyyy-MM-dd') : undefined,
              })}
            />
          </PopoverContent>
        </Popover>
      )}
    </div>
  )
}

/** Single business date (Y-m-d string) picker. */
export function DatePicker({ value, onChange, placeholder = 'Pick a date', invalid, id }: { value: string | null | undefined; onChange: (v: string | null) => void; placeholder?: string; invalid?: boolean; id?: string }) {
  return (
    <Popover>
      <PopoverTrigger asChild>
        <Button id={id} variant="outline" aria-invalid={invalid} className="w-full justify-start font-normal data-[empty=true]:text-muted-foreground" data-empty={!value}>
          <CalendarIcon /> {value ? format(parseISO(value), 'dd MMM yyyy') : placeholder}
        </Button>
      </PopoverTrigger>
      <PopoverContent className="w-auto p-0" align="start">
        <Calendar mode="single" selected={value ? parseISO(value) : undefined} onSelect={(d) => onChange(d ? format(d, 'yyyy-MM-dd') : null)} />
      </PopoverContent>
    </Popover>
  )
}
