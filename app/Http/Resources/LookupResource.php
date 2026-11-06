<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Departments, units and item categories. */
class LookupResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'synonyms' => $this->when(array_key_exists('synonyms', $this->resource->getAttributes()), fn () => $this->synonyms ?? []),
            'is_active' => $this->is_active,
        ];
    }
}
