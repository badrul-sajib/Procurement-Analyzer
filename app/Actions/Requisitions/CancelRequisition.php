<?php

namespace App\Actions\Requisitions;

use App\Enums\RequisitionStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Requisition;
use App\Support\AuditLogger;
use App\Support\DataVersion;
use Illuminate\Support\Facades\DB;

class CancelRequisition
{
    public function handle(Requisition $requisition, string $reason, int $userId): Requisition
    {
        return DB::transaction(function () use ($requisition, $reason, $userId) {
            $requisition = RequisitionGuard::lock($requisition);
            if ($requisition->status === RequisitionStatus::Cancelled) {
                throw new BusinessRuleException('invalid_status', 'This requisition is already cancelled.');
            }
            if (RequisitionGuard::hasActivePurchases($requisition)) {
                throw new BusinessRuleException('requisition_has_purchases', 'Lines of this requisition are on purchase orders. Cancel those purchase orders first.');
            }

            $from = $requisition->status->value;
            $requisition->forceFill([
                'status' => RequisitionStatus::Cancelled, 'cancelled_by' => $userId, 'cancelled_at' => now(), 'cancellation_reason' => $reason,
            ])->save();
            AuditLogger::log('requisition.cancelled', 'requisitions', $requisition->id, "Cancelled {$requisition->reference_no}: {$reason}", ['status' => $from], ['status' => 'cancelled']);
            DataVersion::bump();

            return $requisition;
        });
    }
}
