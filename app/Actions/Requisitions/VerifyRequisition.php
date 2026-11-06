<?php

namespace App\Actions\Requisitions;

use App\Enums\RequisitionStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Requisition;
use App\Support\AuditLogger;
use App\Support\DataVersion;
use App\Support\UnitConverter;
use Illuminate\Support\Facades\DB;

/**
 * Human confirmation of extracted data. Every line must be matched, positive, and convertible to
 * its item's default unit; the conversion factor is frozen on the line here.
 */
class VerifyRequisition
{
    public function handle(Requisition $requisition, int $userId): Requisition
    {
        return DB::transaction(function () use ($requisition, $userId) {
            $requisition = RequisitionGuard::lock($requisition);
            RequisitionGuard::assertStatus($requisition, [RequisitionStatus::Draft, RequisitionStatus::Extracted, RequisitionStatus::Failed], 'verify');

            $lines = $requisition->items()->with(['item.defaultUnit', 'unit'])->get();
            $errors = [];
            if (! $requisition->requisition_date) {
                $errors['requisition_date'][] = 'Requisition date is required.';
            }
            if ($lines->isEmpty()) {
                $errors['lines'][] = 'Add at least one line.';
            }

            $factors = [];
            foreach ($lines->values() as $i => $line) {
                $key = "lines.{$i}";
                if (! $line->item) {
                    $errors["{$key}.item_id"][] = "Line {$line->line_no}: match \"{$line->original_name}\" to a catalog item.";
                } elseif (! $line->item->is_active) {
                    $errors["{$key}.item_id"][] = "Line {$line->line_no}: {$line->item->name} is inactive.";
                }
                if ($line->quantity === null || bccomp($line->quantity, '0', 3) <= 0) {
                    $errors["{$key}.quantity"][] = "Line {$line->line_no}: quantity must be greater than zero.";
                }
                if (! $line->unit_id) {
                    $errors["{$key}.unit_id"][] = "Line {$line->line_no}: choose a unit.";
                } elseif ($line->item) {
                    $factor = UnitConverter::factor($line->item->id, $line->item->default_unit_id, $line->unit_id);
                    if ($factor === null) {
                        $errors["{$key}.unit_id"][] = "Line {$line->line_no}: no conversion from {$line->unit->name} to {$line->item->defaultUnit->name} for {$line->item->name}.";
                    }
                    $factors[$line->id] = $factor;
                }
            }

            if ($errors) {
                throw new BusinessRuleException('verification_failed', 'The requisition has lines that need attention before it can be verified.', $errors);
            }

            foreach ($lines as $line) {
                $line->forceFill(['conversion_factor' => $factors[$line->id]])->save();
            }
            $requisition->forceFill(['status' => RequisitionStatus::Verified, 'verified_by' => $userId, 'verified_at' => now()])->save();
            AuditLogger::log('requisition.verified', 'requisitions', $requisition->id, "Verified {$requisition->reference_no} ({$lines->count()} lines)");
            DataVersion::bump();

            return $requisition;
        });
    }
}
