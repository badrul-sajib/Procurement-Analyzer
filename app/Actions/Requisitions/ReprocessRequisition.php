<?php

namespace App\Actions\Requisitions;

use App\Enums\RequisitionStatus;
use App\Exceptions\BusinessRuleException;
use App\Jobs\ProcessRequisitionDocument;
use App\Models\Requisition;
use App\Support\AuditLogger;
use Illuminate\Support\Facades\DB;

/** Re-run extraction on the original document. Replaces unverified lines. */
class ReprocessRequisition
{
    public function handle(Requisition $requisition): Requisition
    {
        return DB::transaction(function () use ($requisition) {
            $requisition = RequisitionGuard::lock($requisition);
            RequisitionGuard::assertStatus($requisition, [RequisitionStatus::Failed, RequisitionStatus::Extracted], 'reprocess');
            if (! $requisition->file()->exists()) {
                throw new BusinessRuleException('no_document', 'This requisition has no uploaded document to process.');
            }

            $requisition->update(['status' => RequisitionStatus::Uploaded]);
            AuditLogger::log('requisition.reprocessed', 'requisitions', $requisition->id, "Reprocessing {$requisition->reference_no}");
            ProcessRequisitionDocument::dispatch($requisition->id)->afterCommit();

            return $requisition;
        });
    }
}
