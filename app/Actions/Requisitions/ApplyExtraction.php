<?php

namespace App\Actions\Requisitions;

use App\DocumentProcessing\ExtractionResult;
use App\DocumentProcessing\ItemMatcher;
use App\DocumentProcessing\ReferenceResolver;
use App\Enums\RequisitionStatus;
use App\Models\Requisition;
use App\Support\DataVersion;
use App\Support\ItemName;
use Illuminate\Support\Facades\DB;

/** Writes extraction output as unverified lines. Replaces earlier extracted lines on reprocess. */
class ApplyExtraction
{
    public function __construct(private ItemMatcher $matcher) {}

    public function handle(Requisition $requisition, ExtractionResult $result, ReferenceResolver $references): array
    {
        return DB::transaction(function () use ($requisition, $result, $references) {
            $requisition = Requisition::lockForUpdate()->findOrFail($requisition->id);
            $headerDepartment = $references->departmentId($result->department);
            $notes = $requisition->notes;
            if ($result->department && ! $headerDepartment && ! str_contains((string) $notes, $result->department)) {
                $notes = trim($notes."\nDepartment in document: {$result->department} (not in master data)");
            }

            $requisition->fill([
                'external_reference' => $requisition->external_reference ?? $result->externalReference,
                'requisition_date' => $requisition->requisition_date ?? $result->requisitionDate,
                'department_id' => $requisition->department_id ?? $headerDepartment,
                'notes' => $notes ?: null,
                'status' => RequisitionStatus::Extracted,
            ]);
            if ($result->title) {
                $requisition->title = $result->title;
            }
            $requisition->save();

            $requisition->items()->delete();
            $matches = $this->matcher->matchMany(array_map(fn ($l) => $l->name, $result->lines));
            $matched = 0;

            foreach (array_values($result->lines) as $i => $line) {
                [$item, $matchType] = $matches[ItemName::normalize($line->name)] ?? [null, null];
                $matched += $item ? 1 : 0;
                $unitId = $references->unitId($line->unit) ?? ($line->unit === null ? $item?->default_unit_id : null);
                $lineDepartment = $references->departmentId($line->department);

                $requisition->items()->create([
                    'line_no' => $i + 1,
                    'item_id' => $item?->id,
                    'match_type' => $matchType,
                    'original_name' => mb_substr($line->name, 0, 255),
                    'description' => $line->description,
                    'quantity' => $line->quantity,
                    'unit_id' => $unitId,
                    'original_unit' => $line->unit ? mb_substr($line->unit, 0, 50) : null,
                    'requested_date' => $line->requestedDate,
                    'department_id' => $lineDepartment ?? $requisition->department_id,
                    'notes' => trim(implode(' · ', array_filter([
                        $line->notes,
                        $line->department && ! $lineDepartment ? "Department: {$line->department}" : null,
                    ]))) ?: null,
                ]);
            }
            DataVersion::bump();

            return ['lines' => count($result->lines), 'matched' => $matched, 'unmatched' => count($result->lines) - $matched, 'skipped' => count($result->skipped)];
        });
    }
}
