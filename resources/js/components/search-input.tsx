import { Search } from 'lucide-react'
import { useEffect, useState } from 'react'
import { InputGroup, InputGroupAddon, InputGroupInput } from '@/components/ui/input-group'

/** Debounced search box; emits after the user pauses typing. */
export function SearchInput({ value, onChange, placeholder = 'Search…', className }: { value: string; onChange: (v: string) => void; placeholder?: string; className?: string }) {
  const [draft, setDraft] = useState(value)
  useEffect(() => setDraft(value), [value])
  useEffect(() => {
    if (draft === value) return
    const t = setTimeout(() => onChange(draft), 350)
    return () => clearTimeout(t)
  }, [draft, value, onChange])

  return (
    <InputGroup className={className ?? 'w-full sm:w-72'}>
      <InputGroupAddon><Search /></InputGroupAddon>
      <InputGroupInput value={draft} onChange={(e) => setDraft(e.target.value)} placeholder={placeholder} aria-label={placeholder} />
    </InputGroup>
  )
}
