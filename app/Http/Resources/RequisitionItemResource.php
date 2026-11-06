<?php

namespace App\Http\Resources;

use App\Models\RequisitionItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin RequisitionItem */
class RequisitionItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'line_no' => $this->line_no,
            'original_name' => $this->original_name,
            'original_unit' => $this->original_unit,
            'description' => $this->description,
            'quantity' => $this->quantity,
            'unit' => $this->whenLoaded('unit', fn () => $this->unit ? ['id' => $this->unit->id, 'code' => $this->unit->code, 'name' => $this->unit->name] : null),
            'item' => $this->whenLoaded('item', fn () => $this->item ? [
                'id' => $this->item->id, 'code' => $this->item->code, 'name' => $this->item->name, 'is_active' => $this->item->is_active,
                'default_unit' => $this->item->relationLoaded('defaultUnit') ? ['id' => $this->item->defaultUnit->id, 'code' => $this->item->defaultUnit->code, 'name' => $this->item->defaultUnit->name] : null,
            ] : null),
            'match_type' => $this->match_type?->value,
            'conversion_factor' => $this->conversion_factor,
            'base_quantity' => $this->base_quantity,
            'approved_quantity' => $this->approved_quantity,
            'requested_date' => $this->requested_date?->toDateString(),
            'department' => $this->whenLoaded('department', fn () => $this->department ? ['id' => $this->department->id, 'name' => $this->department->name] : null),
            'notes' => $this->notes,
            // Present on detail views once purchasing exists (see Queries\RequisitionFulfilment).
            'fulfilment' => $this->when(isset($this->fulfilment), fn () => $this->fulfilment),
        ];
    }
}
