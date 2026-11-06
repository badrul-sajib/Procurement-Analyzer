<?php

namespace App\Models;

use App\Enums\RequisitionStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['external_reference', 'title', 'requisition_date', 'department_id', 'status', 'notes', 'created_by'])]
class Requisition extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => RequisitionStatus::class,
            'requisition_date' => 'date:Y-m-d',
            'verified_at' => 'datetime',
            'approved_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(RequisitionItem::class)->orderBy('line_no');
    }

    public function file(): HasOne
    {
        return $this->hasOne(RequisitionFile::class);
    }

    public function processingLogs(): HasMany
    {
        return $this->hasMany(RequisitionProcessingLog::class)->orderByDesc('id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
