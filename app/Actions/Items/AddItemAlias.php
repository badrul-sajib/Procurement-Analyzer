<?php

namespace App\Actions\Items;

use App\Models\Item;
use App\Models\ItemAlias;
use App\Support\AuditLogger;
use App\Support\ItemName;
use Illuminate\Validation\ValidationException;

class AddItemAlias
{
    /** An alias must map to exactly one item: it may not equal any item name or another alias. */
    public function handle(Item $item, string $alias, ?int $userId): ItemAlias
    {
        $normalized = ItemName::normalize($alias);
        if ($normalized === '') {
            throw ValidationException::withMessages(['alias' => 'Alias must contain letters or numbers.']);
        }
        if ($normalized === $item->normalized_name) {
            throw ValidationException::withMessages(['alias' => 'Alias is the same as the item name.']);
        }
        $clash = Item::where('normalized_name', $normalized)->value('name')
            ?? ItemAlias::where('normalized_alias', $normalized)->with('item:id,name')->first()?->item->name;
        if ($clash) {
            throw ValidationException::withMessages(['alias' => "\"{$alias}\" already identifies the item \"{$clash}\"."]);
        }

        $record = $item->aliases()->create(['alias' => trim($alias), 'created_by' => $userId]);
        AuditLogger::log('item.alias_added', 'items', $item->id, "Alias \"{$record->alias}\" added to {$item->name}", null, ['alias' => $record->alias]);

        return $record;
    }
}
