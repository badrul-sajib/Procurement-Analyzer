<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['requisition_id', 'disk', 'path', 'original_name', 'mime_type', 'size_bytes', 'sha256', 'uploaded_by'])]
class RequisitionFile extends Model
{
    public function requisition(): BelongsTo
    {
        return $this->belongsTo(Requisition::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
