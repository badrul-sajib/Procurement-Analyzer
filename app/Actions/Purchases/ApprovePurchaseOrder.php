<?php

namespace App\Actions\Purchases;

use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use App\Support\AuditLogger;
use App\Support\DataVersion;
use Illuminate\Support\Facades\DB;

class ApprovePurchaseOrder
{
    public function handle(PurchaseOrder $po, int $userId): PurchaseOrder
    {
        return DB::transaction(function () use ($po, $userId) {
            $po = PurchaseOrderGuard::lock($po);
            PurchaseOrderGuard::assertStatus($po, [PurchaseOrderStatus::PendingApproval], 'approve');
            $po->forceFill(['status' => PurchaseOrderStatus::Approved, 'approved_by' => $userId, 'approved_at' => now()])->save();
            AuditLogger::log('purchase_order.approved', 'purchase_orders', $po->id, "Approved {$po->po_number}", ['status' => 'pending_approval'], ['status' => 'approved']);
            DataVersion::bump();

            return $po;
        });
    }
}
