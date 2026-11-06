<?php

namespace App\Reports;

/** Registry of the ten date-wise reports (spec §12). */
final class Reports
{
    public static function all(): array
    {
        return [
            'requisitions' => new RequisitionUploadReport,
            'items' => new ItemConsolidatedReport,
            'requested-quantity' => new RequestedQuantityReport,
            'pending-purchases' => new PendingPurchaseReport,
            'purchased-quantity' => new PurchasedQuantityReport,
            'deliveries' => new DeliveredQuantityReport,
            'purchase-amount' => new PurchaseAmountReport,
            'vendor-purchases' => new VendorPurchaseReport,
            'vendor-payments' => new VendorPaymentReport,
            'outstanding' => new OutstandingReport,
        ];
    }

    public static function find(string $key): ?Report
    {
        return self::all()[$key] ?? null;
    }
}
