import { Plus, Sparkles } from 'lucide-react'
import { AsyncCombobox, type Option } from '@/components/async-combobox'
import { Button } from '@/components/ui/button'
import type { Item } from '@/features/catalog/api'
import { ItemFormSheet } from '@/features/items/item-form-sheet'
import { useMatchSuggestions } from '@/features/requisitions/api'
import { useAuth } from '@/lib/auth'

export type PickedItem = { id: number; name: string; default_unit_id: number | null }

const toOption = (i: Item): Option => ({ value: i.id, label: i.name, hint: `${i.code} · ${i.default_unit.name}` })

/**
 * Match a requisition line to a catalog item. Suggestions are only offered, never applied:
 * the reviewer clicks one, searches, or creates a new item.
 */
export function LineItemPicker({ lineId, originalName, originalUnitId, value, onChange, invalid }: {
  lineId?: number
  originalName: string
  originalUnitId?: number | null
  value: PickedItem | null
  onChange: (item: PickedItem | null) => void
  invalid?: boolean
}) {
  const { can } = useAuth()
  const suggestions = useMatchSuggestions(lineId, !value)
  const pick = (i: Item) => onChange({ id: i.id, name: i.name, default_unit_id: i.default_unit.id })

  return (
    <div className="space-y-1.5">
      <div className="flex gap-1">
        <AsyncCombobox<Item>
          endpoint="/items"
          params={{ is_active: 1 }}
          toOption={toOption}
          value={value ? { value: value.id, label: value.name } : null}
          onChange={(o) => onChange(o ? { id: o.value, name: o.label, default_unit_id: null } : null)}
          placeholder="Match item…"
          invalid={invalid}
          className="min-w-48"
        />
        {can('items.manage') && !value && (
          <ItemFormSheet
            defaultName={originalName}
            defaultUnitId={originalUnitId}
            onSaved={pick}
            trigger={<Button type="button" variant="outline" size="icon" aria-label="Create item from this line"><Plus /></Button>}
          />
        )}
      </div>
      {!value && !!suggestions.data?.length && (
        <div className="flex flex-wrap items-center gap-1 text-xs">
          <Sparkles className="size-3 text-muted-foreground" />
          {suggestions.data.slice(0, 3).map((s) => (
            <button key={s.item.id} type="button" onClick={() => pick(s.item)}
              className="rounded-md border px-1.5 py-0.5 hover:bg-accent" title={`${s.score}% similar`}>
              {s.item.name} <span className="text-muted-foreground">{s.score}%</span>
            </button>
          ))}
        </div>
      )}
    </div>
  )
}
