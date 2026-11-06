<?php

namespace App\Reports;

use App\Enums\RequisitionStatus;
use App\Queries\Analyzer;
use App\Support\DateRange;
use Illuminate\Database\Query\Builder;

final class PendingPurchaseReport extends Report
{
    public function title(): string
    {
        return 'Pending purchase report';
    }

    public function dateBasis(array $filters): string
    {
        return 'Requisition date';
    }

    public function rules(): array
    {
        return (new ItemConsolidatedReport)->rules();
    }

    public function columns(array $filters): array
    {
        return array_intersect_key((new ItemConsolidatedReport)->columns($filters), array_flip(['code', 'name', 'unit', 'category', 'requisition_count', 'approved', 'on_order', 'purchased', 'pending']));
    }

    public function defaultSort(): string
    {
        return '-pending';
    }

    public function totals(): array
    {
        return ['approved', 'on_order', 'purchased', 'pending'];
    }

    public function query(array $filters, ?DateRange $range): Builder
    {
        return (new Analyzer(['status' => RequisitionStatus::Approved->value] + $filters + ($range ? ['dates' => $range->dates()] : [])))->items()->where('y.pending', '>', 0);
    }
}
