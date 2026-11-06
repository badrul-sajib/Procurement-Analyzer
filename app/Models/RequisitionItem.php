<?php

namespace App\Models;

use App\Enums\MatchType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['requisition_id', 'line_no', 'item_id', 'original_name', 'description', 'quantity', 'unit_id',
    'original_unit', 'requested_date', 'department_id', 'notes', 'match_type'])]
class RequisitionItem extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'conversion_factor' => 'decimal:6',
            'base_quantity' => 'decimal:3',
            'approved_quantity' => 'decimal:3',
            'requested_date' => 'date:Y-m-d',
            'match_type' => MatchType::class,
        ];
    }

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(Requisition::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function purchaseAllocations(): HasMany
    {
        return $this->hasMany(PurchaseOrderItemRequisition::class);
    }
}
