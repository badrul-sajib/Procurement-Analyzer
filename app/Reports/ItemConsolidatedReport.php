<?php

namespace App\Reports;

use App\Queries\Analyzer;
use App\Support\DateRange;
use Illuminate\Database\Query\Builder;

final class ItemConsolidatedReport extends Report
{
    public function title(): string
    {
        return 'Item-wise consolidated report';
    }

    public function dateBasis(array $filters): string
    {
        return 'Requisition date';
    }

    public function rules(): array
    {
        return array_diff_key(Analyzer::rules(), DateRange::rules());
    }

    public function columns(array $filters): array
    {
        return ['code' => ['Code', self::TEXT], 'name' => ['Item', self::TEXT], 'unit' => ['Unit', self::TEXT], 'category' => ['Category', self::TEXT],
            'requisition_count' => ['Requisitions', self::INT], 'requested' => ['Requested', self::QTY], 'approved' => ['Approved', self::QTY],
            'on_order' => ['On order', self::QTY], 'purchased' => ['Purchased', self::QTY], 'pending' => ['Pending', self::QTY],
            'delivered' => ['Delivered', self::QTY], 'remaining_delivery' => ['To deliver', self::QTY],
            'purchase_status' => ['Purchase status', self::TEXT], 'delivery_status' => ['Delivery status', self::TEXT]];
    }

    public function defaultSort(): string
    {
        return 'name';
    }

    public function totals(): array
    {
        return ['requested', 'approved', 'on_order', 'purchased', 'pending', 'delivered', 'remaining_delivery'];
    }

    public function query(array $filters, ?DateRange $range): Builder
    {
        return (new Analyzer($filters + ($range ? ['dates' => $range->dates()] : [])))->items();
    }
}
