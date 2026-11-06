<?php

namespace App\Actions\Requisitions;

use App\Enums\RequisitionStatus;
use App\Models\Requisition;
use App\Support\AuditLogger;
use App\Support\DataVersion;
use Illuminate\Support\Facades\DB;

/** Send a verified (not yet approved) requisition back for correction. */
class ReopenRequisition
{
    public function handle(Requisition $requisition, int $userId): Requisition
    {
        return DB::transaction(function () use ($requisition) {
            $requisition = RequisitionGuard::lock($requisition);
            RequisitionGuard::assertStatus($requisition, [RequisitionStatus::Verified], 'reopen');

            $requisition->items()->update(['conversion_factor' => null]);
            $requisition->forceFill([
                'status' => $requisition->file()->exists() ? RequisitionStatus::Extracted : RequisitionStatus::Draft,
                'verified_by' => null, 'verified_at' => null,
            ])->save();
            AuditLogger::log('requisition.reopened', 'requisitions', $requisition->id, "Reopened {$requisition->reference_no} for correction");
            DataVersion::bump();

            return $requisition;
        });
    }
}
