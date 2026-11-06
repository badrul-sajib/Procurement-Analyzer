<?php

namespace App\Reports;

use App\Queries\VendorBalance;
use App\Support\DateRange;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Balances as of the end of the period (purchases and payments dated on or before it). */
final class OutstandingReport extends Report
{
    public function title(): string
    {
        return 'Outstanding payment report';
    }

    public function dateBasis(array $filters): string
    {
        return 'As of the end date (purchase and payment dates)';
    }

    public function permission(): string
    {
        return 'reports.financial';
    }

    public function rules(): array
    {
        return ['only_due' => ['nullable', 'boolean']];
    }

    public function columns(array $filters): array
    {
        return ['code' => ['Code', self::TEXT], 'name' => ['Vendor', self::TEXT], 'payable' => ['Purchase amount', self::MONEY],
            'allocated' => ['Paid (allocated)', self::MONEY], 'outstanding' => ['Outstanding', self::MONEY], 'credit' => ['Unallocated credit', self::MONEY],
            'purchase_orders' => ['POs', self::INT], 'last_purchase_date' => ['Last purchase', self::DATE]];
    }

    public function defaultSort(): string
    {
        return '-outstanding';
    }

    public function totals(): array
    {
        return ['payable', 'allocated', 'outstanding', 'credit'];
    }

    public function query(array $filters, ?DateRange $range): Builder
    {
        return DB::query()->fromSub(VendorBalance::query($range?->dates()[1]), 'b')
            ->where(fn ($q) => $q->where('b.payable', '>', 0)->orWhere('b.credit', '>', 0))
            ->when($filters['only_due'] ?? false, fn ($q) => $q->where('b.outstanding', '>', 0))
            ->select('b.*');
    }
}
