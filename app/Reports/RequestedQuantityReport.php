<?php

namespace App\Reports;

use App\Enums\RequisitionStatus;
use App\Support\DateRange;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class RequestedQuantityReport extends Report
{
    public function title(): string
    {
        return 'Total requested quantity report';
    }

    public function dateBasis(array $filters): string
    {
        return 'Requisition date';
    }

    public function rules(): array
    {
        return ['group_by' => ['nullable', 'in:day,month'], 'item_id' => ['nullable', 'integer'], 'department_id' => ['nullable', 'integer']];
    }

    public function columns(array $filters): array
    {
        return ['period' => [($filters['group_by'] ?? 'day') === 'month' ? 'Month' : 'Date', self::TEXT], 'requisitions' => ['Requisitions', self::INT],
            'lines' => ['Lines', self::INT], 'items' => ['Unique items', self::INT], 'requested_quantity' => ['Requested qty', self::QTY], 'approved_quantity' => ['Approved qty', self::QTY]];
    }

    public function defaultSort(): string
    {
        return 'period';
    }

    public function totals(): array
    {
        return ['lines', 'requested_quantity', 'approved_quantity'];
    }

    public function query(array $filters, ?DateRange $range): Builder
    {
        $b = self::bucket('r.requisition_date', $filters);

        return DB::table('requisition_items as ri')->join('requisitions as r', 'r.id', '=', 'ri.requisition_id')
            ->whereIn('r.status', RequisitionStatus::confirmed())
            ->when($range, fn ($q) => $q->whereBetween('r.requisition_date', $range->dates()))
            ->when($filters['item_id'] ?? null, fn ($q, $v) => $q->where('ri.item_id', $v))
            ->when($filters['department_id'] ?? null, fn ($q, $v) => $q->where('ri.department_id', $v))
            ->groupByRaw($b)
            ->selectRaw("{$b} AS period, COUNT(DISTINCT r.id) AS requisitions, COUNT(*) AS `lines`, COUNT(DISTINCT ri.item_id) AS items")
            ->selectRaw('SUM(ri.base_quantity) AS requested_quantity, SUM(COALESCE(ri.approved_quantity, 0)) AS approved_quantity');
    }
}
