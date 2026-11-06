<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['requisition_id', 'attempt', 'status', 'extractor', 'message', 'context', 'started_at', 'finished_at'])]
class RequisitionProcessingLog extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['context' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime'];
    }
}
