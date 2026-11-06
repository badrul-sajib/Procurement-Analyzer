<?php

namespace App\Http\Resources;

use App\Models\Item;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Item */
class ItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'sku' => $this->sku,
            'description' => $this->description,
            'is_active' => $this->is_active,
            'category' => $this->whenLoaded('category', fn () => $this->category ? ['id' => $this->category->id, 'name' => $this->category->name] : null),
            'default_unit' => $this->whenLoaded('defaultUnit', fn () => ['id' => $this->defaultUnit->id, 'code' => $this->defaultUnit->code, 'name' => $this->defaultUnit->name]),
            'aliases' => $this->whenLoaded('aliases', fn () => $this->aliases->map(fn ($a) => ['id' => $a->id, 'alias' => $a->alias])),
            'conversions' => $this->whenLoaded('conversions', fn () => UnitConversionResource::collection($this->conversions)),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
