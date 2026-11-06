<?php

namespace App\Actions\Purchases;

use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use App\Support\AuditLogger;
use App\Support\DataVersion;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;

/** Draft → pending approval, or straight to approved when approval is disabled in settings. */
class SubmitPurchaseOrder
{
    public function handle(PurchaseOrder $po, int $userId): PurchaseOrder
    {
        return DB::transaction(function () use ($po, $userId) {
            $po = PurchaseOrderGuard::lock($po);
            PurchaseOrderGuard::assertStatus($po, [PurchaseOrderStatus::Draft], 'submit');
            $needsApproval = (bool) Settings::get('require_po_approval');

            $po->forceFill([
                'status' => $needsApproval ? PurchaseOrderStatus::PendingApproval : PurchaseOrderStatus::Approved,
                'submitted_by' => $userId, 'submitted_at' => now(),
            ] + ($needsApproval ? [] : ['approved_by' => $userId, 'approved_at' => now()]))->save();

            AuditLogger::log('purchase_order.submitted', 'purchase_orders', $po->id,
                "Submitted {$po->po_number}".($needsApproval ? ' for approval' : ' (approval not required)'), ['status' => 'draft'], ['status' => $po->status->value]);
            DataVersion::bump();

            return $po;
        });
    }
}
