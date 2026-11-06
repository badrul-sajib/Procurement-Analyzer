<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Delivery;
use App\Models\Payment;
use App\Models\PurchaseOrder;
use App\Models\Requisition;
use App\Queries\Dashboard;
use App\Support\DateRange;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        $request->validate(DateRange::rules());

        return response()->json(['data' => (new Dashboard(DateRange::fromRequest($request)))->summary($request->user()->can('finance.view'))]);
    }

    public function charts(Request $request): JsonResponse
    {
        $request->validate(DateRange::rules());

        return response()->json(['data' => (new Dashboard(DateRange::fromRequest($request)))->charts($request->user()->can('finance.view'))]);
    }

    /** Latest records per module, limited to what this user may see. */
    public function recentActivities(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = [];
        if ($user->can('requisitions.view')) {
            $data['requisitions'] = Requisition::latest('id')->limit(5)->get(['id', 'reference_no', 'title', 'status', 'created_at'])
                ->map(fn ($r) => ['id' => $r->id, 'reference' => $r->reference_no, 'title' => $r->title, 'status' => $r->status->value, 'at' => $r->created_at->toIso8601String()]);
        }
        if ($user->can('purchases.view')) {
            $data['purchase_orders'] = PurchaseOrder::with('vendor:id,name')->latest('id')->limit(5)->get()
                ->map(fn ($p) => ['id' => $p->id, 'reference' => $p->po_number, 'title' => $p->vendor->name, 'status' => $p->status->value, 'at' => $p->created_at->toIso8601String()]);
        }
        if ($user->can('deliveries.view')) {
            $data['deliveries'] = Delivery::with('purchaseOrder:id,po_number')->latest('id')->limit(5)->get()
                ->map(fn ($d) => ['id' => $d->id, 'reference' => $d->delivery_number, 'title' => $d->purchaseOrder->po_number, 'status' => $d->status->value, 'at' => $d->created_at->toIso8601String()]);
        }
        if ($user->can('payments.view')) {
            $data['payments'] = Payment::with('vendor:id,name')->latest('id')->limit(5)->get()
                ->map(fn ($p) => ['id' => $p->id, 'reference' => $p->payment_reference, 'title' => $p->vendor->name, 'amount' => $p->amount, 'status' => $p->status->value, 'at' => $p->created_at->toIso8601String()]);
        }
        $modules = array_keys(array_filter([
            'requisitions' => $user->can('requisitions.view'), 'purchase_orders' => $user->can('purchases.view'),
            'deliveries' => $user->can('deliveries.view'), 'payments' => $user->can('payments.view'),
        ]));
        $data['status_updates'] = ActivityLog::with('user:id,name')->whereIn('module', $modules ?: ['-'])
            ->where(fn ($q) => $q->where('action', 'like', '%.approved')->orWhere('action', 'like', '%.verified')->orWhere('action', 'like', '%.purchased')
                ->orWhere('action', 'like', '%.cancelled')->orWhere('action', 'like', '%.confirmed')->orWhere('action', 'like', '%.reversed')->orWhere('action', 'like', '%.completed'))
            ->latest('id')->limit(10)->get()
            ->map(fn ($l) => ['id' => $l->id, 'module' => $l->module, 'record_id' => $l->record_id, 'description' => $l->description, 'user' => $l->user?->name, 'at' => $l->created_at->toIso8601String()]);

        return response()->json(['data' => $data]);
    }
}
