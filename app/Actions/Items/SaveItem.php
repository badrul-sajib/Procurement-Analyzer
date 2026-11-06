<?php

namespace App\Actions\Items;

use App\Exceptions\BusinessRuleException;
use App\Models\Item;
use App\Models\ItemAlias;
use App\Support\AuditLogger;
use App\Support\DataVersion;
use App\Support\ItemName;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaveItem
{
    public function __construct(private AddItemAlias $addAlias) {}

    public function handle(array $data, ?Item $item, ?int $userId): Item
    {
        return DB::transaction(function () use ($data, $item, $userId) {
            if (isset($data['name'])) {
                $this->assertNameIsFree($data['name'], $item);
            }

            if ($item && isset($data['default_unit_id']) && (int) $data['default_unit_id'] !== $item->default_unit_id
                && $this->hasQuantityHistory($item)) {
                throw new BusinessRuleException('default_unit_locked',
                    'The default unit cannot change once verified requisitions or purchase orders use this item.',
                    ['default_unit_id' => ['Default unit is locked by existing transactions.']]);
            }

            $aliases = $data['aliases'] ?? [];
            unset($data['aliases']);

            if ($item) {
                $before = $item->only($item->getFillable());
                $item->update($data);
                [$old, $new] = AuditLogger::diff($before, $item->only($item->getFillable()));
                if ($new) {
                    AuditLogger::log('item.updated', 'items', $item->id, "Updated {$item->name}", $old, $new);
                }
            } else {
                $item = Item::create($data + ['created_by' => $userId]);
                if (empty($data['code'])) {
                    $item->forceFill(['code' => sprintf('ITM-%05d', $item->id)])->saveQuietly();
                }
                AuditLogger::log('item.created', 'items', $item->id, "Created {$item->name}", null, $item->only($item->getFillable()));
            }

            foreach ($aliases as $alias) {
                $this->addAlias->handle($item, $alias, $userId);
            }
            DataVersion::bump();

            return $item;
        });
    }

    private function assertNameIsFree(string $name, ?Item $item): void
    {
        $normalized = ItemName::normalize($name);
        $existing = Item::where('normalized_name', $normalized)->when($item, fn ($q) => $q->whereKeyNot($item->id))->value('name');
        $alias = ItemAlias::where('normalized_alias', $normalized)
            ->when($item, fn ($q) => $q->where('item_id', '!=', $item->id))
            ->with('item:id,name')->first();

        if ($existing || $alias) {
            throw ValidationException::withMessages([
                'name' => 'An item with this name already exists: '.($existing ?? $alias->item->name).'.',
            ]);
        }
    }

    private function hasQuantityHistory(Item $item): bool
    {
        return $item->requisitionItems()->whereNotNull('conversion_factor')->exists()
            || $item->purchaseOrderItems()->exists();
    }
}
