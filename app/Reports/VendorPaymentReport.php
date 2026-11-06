<?php

namespace App\Reports;

use App\Enums\PaymentStatus;
use App\Support\DateRange;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class VendorPaymentReport extends Report
{
    public function title(): string
    {
        return 'Vendor-wise payment report';
    }

    public function dateBasis(array $filters): string
    {
        return 'Payment date';
    }

    public function permission(): string
    {
        return 'reports.financial';
    }

    public function rules(): array
    {
        return ['vendor_id' => ['nullable', 'integer'], 'method' => ['nullable', 'string']];
    }

    public function columns(array $filters): array
    {
        return ['payment_date' => ['Payment date', self::DATE], 'payment_reference' => ['Reference', self::TEXT], 'vendor' => ['Vendor', self::TEXT],
            'method' => ['Method', self::TEXT], 'transaction_reference' => ['Transaction ref', self::TEXT], 'amount' => ['Amount', self::MONEY],
            'allocated' => ['Allocated', self::MONEY], 'unallocated' => ['Unallocated', self::MONEY]];
    }

    public function defaultSort(): string
    {
        return '-payment_date';
    }

    public function totals(): array
    {
        return ['amount', 'allocated', 'unallocated'];
    }

    public function query(array $filters, ?DateRange $range): Builder
    {
        $allocated = DB::table('payment_allocations')->groupBy('payment_id')->select('payment_id')->selectRaw('SUM(amount) AS amount');

        return DB::table('payments as p')->join('vendors as v', 'v.id', '=', 'p.vendor_id')
            ->leftJoinSub($allocated, 'a', 'a.payment_id', '=', 'p.id')
            ->where('p.status', PaymentStatus::Active->value)
            ->when($range, fn ($q) => $q->whereBetween('p.payment_date', $range->dates()))
            ->when($filters['vendor_id'] ?? null, fn ($q, $v) => $q->where('p.vendor_id', $v))
            ->when($filters['method'] ?? null, fn ($q, $v) => $q->where('p.method', $v))
            ->select('p.id', 'p.payment_date', 'p.payment_reference', 'v.name as vendor', 'p.method', 'p.transaction_reference', 'p.amount')
            ->selectRaw('COALESCE(a.amount, 0) AS allocated, p.amount - COALESCE(a.amount, 0) AS unallocated');
    }
}
