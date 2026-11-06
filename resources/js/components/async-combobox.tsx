import { useQuery } from '@tanstack/react-query'
import { Check, ChevronsUpDown } from 'lucide-react'
import { useState } from 'react'
import { Button } from '@/components/ui/button'
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from '@/components/ui/command'
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover'
import { Spinner } from '@/components/ui/spinner'
import { api, type Paginated } from '@/lib/api'
import { cn } from '@/lib/utils'

export type Option = { value: number; label: string; hint?: string }

type Props<T> = {
  /** API list endpoint supporting ?search= (e.g. /items). */
  endpoint: string
  params?: Record<string, string | number | boolean>
  toOption: (row: T) => Option
  value: Option | null
  onChange: (option: Option | null) => void
  placeholder?: string
  disabled?: boolean
  className?: string
  invalid?: boolean
}

/** Searchable dropdown backed by a server-side search endpoint (never loads whole tables). */
export function AsyncCombobox<T>({ endpoint, params, toOption, value, onChange, placeholder = 'Select…', disabled, className, invalid }: Props<T>) {
  const [open, setOpen] = useState(false)
  const [search, setSearch] = useState('')
  const { data, isFetching } = useQuery({
    queryKey: [endpoint, 'combobox', search, params],
    queryFn: () => api.get<Paginated<T>>(endpoint, { search, per_page: 20, ...params }),
    enabled: open,
    placeholderData: (prev) => prev,
  })
  const options = data?.data.map(toOption) ?? []

  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <Button variant="outline" role="combobox" aria-expanded={open} aria-invalid={invalid} disabled={disabled}
          className={cn('w-full min-w-0 shrink justify-between font-normal', !value && 'text-muted-foreground', className)}>
          <span className="truncate">{value?.label ?? placeholder}</span>
          <ChevronsUpDown className="opacity-50" />
        </Button>
      </PopoverTrigger>
      <PopoverContent className="w-(--radix-popover-trigger-width) min-w-64 p-0" align="start">
        <Command shouldFilter={false}>
          <CommandInput placeholder="Type to search…" value={search} onValueChange={setSearch} />
          <CommandList>
            {isFetching && !options.length ? (
              <div className="flex justify-center p-4"><Spinner /></div>
            ) : (
              <CommandEmpty>No matches.</CommandEmpty>
            )}
            <CommandGroup>
              {options.map((option) => (
                <CommandItem key={option.value} value={String(option.value)} onSelect={() => { onChange(option); setOpen(false) }}>
                  <Check className={cn(value?.value === option.value ? 'opacity-100' : 'opacity-0')} />
                  <div className="flex min-w-0 flex-col">
                    <span className="truncate">{option.label}</span>
                    {option.hint && <span className="truncate text-xs text-muted-foreground">{option.hint}</span>}
                  </div>
                </CommandItem>
              ))}
            </CommandGroup>
          </CommandList>
        </Command>
      </PopoverContent>
    </Popover>
  )
}
