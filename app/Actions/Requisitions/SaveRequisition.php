<?php

namespace App\Actions\Requisitions;

use App\Actions\Items\AddItemAlias;
use App\DocumentProcessing\ItemMatcher;
use App\Enums\MatchType;
use App\Enums\RequisitionStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Requisition;
use App\Support\AuditLogger;
use App\Support\DataVersion;
use App\Support\ItemName;
use App\Support\ReferenceNumber;
use Illuminate\Support\Facades\DB;

/**
 * Create a manual requisition or save review corrections. Lines are upserted by id; the
 * extracted original_name/original_unit of existing lines are never overwritten.
 */
class SaveRequisition
{
    private const HEADER = ['title', 'external_reference', 'requisition_date', 'department_id', 'notes'];

    public function __construct(private ItemMatcher $matcher, private AddItemAlias $addAlias) {}

    public function handle(array $data, ?Requisition $requisition, int $userId): Requisition
    {
        return DB::transaction(function () use ($data, $requisition, $userId) {
            if ($requisition) {
                $requisition = RequisitionGuard::lock($requisition);
                RequisitionGuard::assertStatus($requisition, [RequisitionStatus::Draft, RequisitionStatus::Extracted, RequisitionStatus::Failed], 'edit');
                $before = $requisition->only(self::HEADER);
                $requisition->fill(array_intersect_key($data, array_flip(self::HEADER)));
                // A failed extraction corrected by hand is ready for review.
                if ($requisition->status === RequisitionStatus::Failed && ! empty($data['lines'])) {
                    $requisition->status = RequisitionStatus::Extracted;
                }
                $requisition->save();
            } else {
                $before = null;
                $requisition = Requisition::create(array_intersect_key($data, array_flip(self::HEADER)) + [
                    'status' => RequisitionStatus::Draft, 'created_by' => $userId,
                ]);
                ReferenceNumber::assign($requisition, 'REQ', 'reference_no');
            }

            $lineChanges = array_key_exists('lines', $data) ? $this->syncLines($requisition, $data['lines'], $userId) : [];

            $after = $requisition->only(self::HEADER);
            [$old, $new] = $before ? AuditLogger::diff($before, $after) : [null, $after];
            if ($new || $lineChanges) {
                AuditLogger::log($before ? 'requisition.updated' : 'requisition.created', 'requisitions', $requisition->id,
                    $before ? 'Requisition corrected during review' : 'Requisition entered manually',
                    $old ?: null, ($new ?: []) + ($lineChanges ? ['lines' => $lineChanges] : []));
            }
            DataVersion::bump();

            return $requisition;
        });
    }

    /** @return array<int, array> per-line change summary for the audit log */
    private function syncLines(Requisition $requisition, array $lines, int $userId): array
    {
        $existing = $requisition->items()->with('item')->get()->keyBy('id');
        $foreign = array_diff(array_filter(array_column($lines, 'id')), $existing->keys()->all());
        if ($foreign) {
            throw new BusinessRuleException('unknown_line', 'Some lines do not belong to this requisition.', ['lines' => ['Unknown line ids: '.implode(', ', $foreign)]]);
        }
        $autoMatches = $this->matcher->matchMany(array_column($lines, 'original_name'));
        $changes = [];
        $keep = [];

        foreach (array_values($lines) as $i => $input) {
            $line = isset($input['id']) ? $existing->get($input['id']) : null;
            $originalName = $line?->original_name ?? $input['original_name'];
            $itemId = $input['item_id'] ?? null;

            [$autoItem, $autoType] = $autoMatches[ItemName::normalize($originalName)] ?? [null, null];
            $matchType = $itemId ? ($autoItem?->id === (int) $itemId ? $autoType : MatchType::Manual) : null;

            $values = [
                'line_no' => $i + 1,
                'item_id' => $itemId,
                'match_type' => $matchType,
                'description' => $input['description'] ?? null,
                'quantity' => $input['quantity'] ?? null,
                'unit_id' => $input['unit_id'] ?? null,
                'requested_date' => $input['requested_date'] ?? null,
                'department_id' => $input['department_id'] ?? null,
                'notes' => $input['notes'] ?? null,
            ];

            if ($line) {
                $before = $line->only(['item_id', 'quantity', 'unit_id']);
                $line->update($values);
                [$old, $new] = AuditLogger::diff($before, $line->only(['item_id', 'quantity', 'unit_id']));
                if ($new) {
                    $changes[] = ['line' => $line->line_no, 'name' => $originalName, 'from' => $old, 'to' => $new];
                }
            } else {
                $line = $requisition->items()->create($values + ['original_name' => $originalName, 'original_unit' => $input['original_unit'] ?? null]);
                $changes[] = ['line' => $line->line_no, 'name' => $originalName, 'added' => true];
            }
            $keep[] = $line->id;

            if ($matchType === MatchType::Manual) {
                AuditLogger::log('item.matched', 'requisitions', $requisition->id, "Line {$line->line_no} \"{$originalName}\" matched manually to item #{$itemId}", null, ['line_id' => $line->id, 'item_id' => (int) $itemId]);
                if (! empty($input['save_alias'])) {
                    $this->addAlias->handle($line->load('item')->item, $originalName, $userId);
                }
            }
        }

        foreach ($existing->except($keep) as $removed) {
            $changes[] = ['line' => $removed->line_no, 'name' => $removed->original_name, 'removed' => true];
            $removed->delete();
        }

        return $changes;
    }
}
