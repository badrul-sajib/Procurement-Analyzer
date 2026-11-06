<?php

namespace App\Reports;

use App\Enums\PurchaseOrderStatus;
use App\Queries\VendorBalance;
use App\Support\DateRange;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Purchase amounts by purchase date, grouped by day or month. */
final class PurchaseAmountReport extends Report
{
    public function title(): string
    {
        return 'Purchase amount report';
    }

    public function dateBasis(array $filters): string
    {
        return 'Purchase date';
    }

    public function permission(): string
    {
        return 'reports.financial';
    }

    public function rules(): array
    {
        return ['group_by' => ['nullable', 'in:day,month'], 'vendor_id' => ['nullable', 'integer']];
    }

    public function columns(array $filters): array
    {
        return ['period' => [($filters['group_by'] ?? 'day') === 'month' ? 'Month' : 'Date', self::TEXT], 'purchase_orders' => ['POs', self::INT],
            'vendors' => ['Vendors', self::INT], 'amount' => ['Purchase amount', self::MONEY]];
    }

    public function defaultSort(): string
    {
        return 'period';
    }

    public function totals(): array
    {
        return ['purchase_orders', 'amount'];
    }

    public function query(array $filters, ?DateRange $range): Builder
    {
        $b = self::bucket('t.purchase_date', $filters);

        return DB::query()->fromSub(VendorBalance::poTotals(), 't')
            ->whereIn('t.status', PurchaseOrderStatus::purchased())
            ->when($range, fn ($q) => $q->whereBetween('t.purchase_date', $range->dates()))
            ->when($filters['vendor_id'] ?? null, fn ($q, $v) => $q->where('t.vendor_id', $v))
            ->groupByRaw($b)
            ->selectRaw("{$b} AS period, COUNT(*) AS purchase_orders, COUNT(DISTINCT t.vendor_id) AS vendors, SUM(t.grand_total) AS amount");
    }
}
