<?php

namespace App\Http\Resources;

use App\Models\Requisition;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Requisition */
class RequisitionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $person = fn ($user) => $user ? ['id' => $user->id, 'name' => $user->name] : null;

        return [
            'id' => $this->id,
            'reference_no' => $this->reference_no,
            'external_reference' => $this->external_reference,
            'title' => $this->title,
            'requisition_date' => $this->requisition_date?->toDateString(),
            'status' => $this->status->value,
            'notes' => $this->notes,
            'department' => $this->whenLoaded('department', fn () => $this->department ? ['id' => $this->department->id, 'name' => $this->department->name] : null),
            'uploaded_at' => $this->created_at?->toIso8601String(),
            'created_by' => $this->whenLoaded('creator', fn () => $person($this->creator)),
            'verified_by' => $this->whenLoaded('verifier', fn () => $person($this->verifier)),
            'verified_at' => $this->verified_at?->toIso8601String(),
            'approved_by' => $this->whenLoaded('approver', fn () => $person($this->approver)),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'cancelled_by' => $this->whenLoaded('canceller', fn () => $person($this->canceller)),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancellation_reason' => $this->cancellation_reason,
            'file' => $this->whenLoaded('file', fn () => $this->file ? [
                'original_name' => $this->file->original_name, 'size_bytes' => $this->file->size_bytes, 'mime_type' => $this->file->mime_type,
            ] : null),
            // Counts are three different metrics and are never mixed: lines, distinct items, physical quantity.
            'line_count' => $this->whenHas('line_count'),
            'item_count' => $this->whenHas('item_count'),
            'total_requested_quantity' => $this->whenHas('total_requested_quantity', fn () => $this->total_requested_quantity === null ? null : bcadd($this->total_requested_quantity, '0', 3)),
            'lines' => RequisitionItemResource::collection($this->whenLoaded('items')),
            'processing_logs' => $this->whenLoaded('processingLogs', fn () => $this->processingLogs->map(fn ($log) => [
                'id' => $log->id, 'attempt' => $log->attempt, 'status' => $log->status, 'extractor' => $log->extractor,
                'message' => $log->message, 'context' => $log->context, 'started_at' => $log->started_at?->toIso8601String(),
                'finished_at' => $log->finished_at?->toIso8601String(),
            ])),
            'possible_duplicates' => $this->when(isset($this->possible_duplicates), fn () => $this->possible_duplicates),
        ];
    }
}
