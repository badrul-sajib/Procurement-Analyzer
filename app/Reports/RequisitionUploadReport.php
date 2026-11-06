<?php

namespace App\Reports;

use App\Support\DateRange;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class RequisitionUploadReport extends Report
{
    public function title(): string
    {
        return 'Requisition upload report';
    }

    public function dateBasis(array $filters): string
    {
        return ($filters['date_basis'] ?? null) === 'uploaded_at' ? 'Upload date' : 'Requisition date';
    }

    public function rules(): array
    {
        return ['date_basis' => ['nullable', 'in:requisition_date,uploaded_at'], 'status' => ['nullable', 'string'], 'department_id' => ['nullable', 'integer']];
    }

    public function columns(array $filters): array
    {
        return ['reference_no' => ['Reference', self::TEXT], 'title' => ['Title', self::TEXT], 'requisition_date' => ['Requisition date', self::DATE],
            'uploaded_at' => ['Uploaded', self::TEXT], 'department' => ['Department', self::TEXT], 'status' => ['Status', self::TEXT],
            'line_count' => ['Lines', self::INT], 'item_count' => ['Items', self::INT], 'requested_quantity' => ['Requested qty', self::QTY],
            'uploaded_by' => ['Uploaded by', self::TEXT]];
    }

    public function defaultSort(): string
    {
        return '-uploaded_at';
    }

    public function totals(): array
    {
        return ['line_count', 'requested_quantity'];
    }

    public function query(array $filters, ?DateRange $range): Builder
    {
        $lines = DB::table('requisition_items')->groupBy('requisition_id')->select('requisition_id')
            ->selectRaw('COUNT(*) AS line_count, COUNT(DISTINCT item_id) AS item_count, SUM(base_quantity) AS requested_quantity');

        $q = DB::table('requisitions as r')->leftJoinSub($lines, 'l', 'l.requisition_id', '=', 'r.id')
            ->leftJoin('departments as d', 'd.id', '=', 'r.department_id')->join('users as u', 'u.id', '=', 'r.created_by')
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->whereIn('r.status', explode(',', $s)))
            ->when($filters['department_id'] ?? null, fn ($q, $d) => $q->where('r.department_id', $d))
            ->select('r.id', 'r.reference_no', 'r.title', 'r.requisition_date', 'r.created_at as uploaded_at', 'd.name as department', 'r.status', 'u.name as uploaded_by')
            ->selectRaw('COALESCE(l.line_count, 0) AS line_count, COALESCE(l.item_count, 0) AS item_count, l.requested_quantity');

        if ($range) {
            ($filters['date_basis'] ?? null) === 'uploaded_at'
                ? $q->whereBetween('r.created_at', $range->utcBounds())
                : $q->whereBetween('r.requisition_date', $range->dates());
        }

        return $q;
    }
}
