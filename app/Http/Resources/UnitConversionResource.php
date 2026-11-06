<?php

namespace App\Http\Resources;

use App\Models\UnitConversion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin UnitConversion */
class UnitConversionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'item_id' => $this->item_id,
            'item' => $this->whenLoaded('item', fn () => $this->item ? ['id' => $this->item->id, 'name' => $this->item->name] : null),
            'from_unit' => $this->whenLoaded('fromUnit', fn () => ['id' => $this->fromUnit->id, 'code' => $this->fromUnit->code, 'name' => $this->fromUnit->name]),
            'to_unit' => $this->whenLoaded('toUnit', fn () => ['id' => $this->toUnit->id, 'code' => $this->toUnit->code, 'name' => $this->toUnit->name]),
            'factor' => $this->factor,
        ];
    }
}
