<?php

namespace App\Actions\Requisitions;

use App\Enums\RequisitionStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Requisition;
use App\Support\AuditLogger;
use App\Support\DataVersion;
use Illuminate\Support\Facades\DB;

/** Approver sets the purchasable quantity per line: requested by default, may only go down. */
class ApproveRequisition
{
    /** @param  array<int, string>  $approved  requisition_item_id => approved quantity (base unit) */
    public function handle(Requisition $requisition, array $approved, int $userId): Requisition
    {
        return DB::transaction(function () use ($requisition, $approved, $userId) {
            $requisition = RequisitionGuard::lock($requisition);
            RequisitionGuard::assertStatus($requisition, [RequisitionStatus::Verified], 'approve');

            $lines = $requisition->items()->get()->keyBy('id');
            if ($unknown = array_diff(array_keys($approved), $lines->keys()->all())) {
                throw new BusinessRuleException('unknown_line', 'Some lines do not belong to this requisition.', ['lines' => ['Unknown line ids: '.implode(', ', $unknown)]]);
            }

            $errors = [];
            $reduced = [];
            foreach ($lines as $line) {
                $quantity = bcadd((string) ($approved[$line->id] ?? $line->base_quantity), '0', 3);
                if (bccomp($quantity, '0', 3) < 0 || bccomp($quantity, $line->base_quantity, 3) > 0) {
                    $errors["lines.{$line->id}.approved_quantity"][] = "Line {$line->line_no}: approved quantity must be between 0 and the requested {$line->base_quantity}.";

                    continue;
                }
                if (bccomp($quantity, $line->base_quantity, 3) !== 0) {
                    $reduced[] = ['line' => $line->line_no, 'requested' => $line->base_quantity, 'approved' => $quantity];
                }
                $line->forceFill(['approved_quantity' => $quantity])->save();
            }
            if ($errors) {
                throw new BusinessRuleException('invalid_approved_quantity', 'Approved quantities are out of range.', $errors);
            }

            $requisition->forceFill(['status' => RequisitionStatus::Approved, 'approved_by' => $userId, 'approved_at' => now()])->save();
            AuditLogger::log('requisition.approved', 'requisitions', $requisition->id, "Approved {$requisition->reference_no}", null, $reduced ? ['reduced_lines' => $reduced] : null);
            DataVersion::bump();

            return $requisition;
        });
    }
}
