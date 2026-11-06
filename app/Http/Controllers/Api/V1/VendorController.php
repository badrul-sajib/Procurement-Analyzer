<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\VendorResource;
use App\Models\ActivityLog;
use App\Models\Vendor;
use App\Queries\PriceHistory;
use App\Queries\VendorBalance;
use App\Support\AuditLogger;
use App\Support\Decimal;
use App\Support\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class VendorController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['search' => ['nullable', 'string', 'max:100'], 'is_active' => ['nullable', 'boolean']]);

        $query = Vendor::query()
            ->when($request->query('search'), fn ($q, $s) => $q->where(fn ($q) => $q
                ->where('name', 'like', ListQuery::like($s))->orWhere('code', 'like', ListQuery::like($s))
                ->orWhere('phone', 'like', ListQuery::like($s))->orWhere('email', 'like', ListQuery::like($s))
                ->orWhere('contact_person', 'like', ListQuery::like($s))))
            ->when($request->has('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')));

        if ($request->boolean('all')) {
            return VendorResource::collection($query->orderBy('name')->get());
        }

        return VendorResource::collection(ListQuery::paginate($query, $request, [
            'name' => 'name', 'code' => 'code', 'created_at' => 'created_at', 'id' => 'id',
        ], 'name'));
    }

    public function show(Vendor $vendor): VendorResource
    {
        return new VendorResource($vendor);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $vendor = DB::transaction(function () use ($data, $request) {
            $vendor = Vendor::create($data + ['created_by' => $request->user()->id]);
            if (! $vendor->code) {
                $vendor->forceFill(['code' => sprintf('VEN-%04d', $vendor->id)])->saveQuietly();
            }
            AuditLogger::log('vendor.created', 'vendors', $vendor->id, "Created vendor {$vendor->name}", null, $vendor->only($vendor->getFillable()));

            return $vendor;
        });

        return (new VendorResource($vendor))->response()->setStatusCode(201);
    }

    public function update(Request $request, Vendor $vendor): VendorResource
    {
        $before = $vendor->only($vendor->getFillable());
        $vendor->update($this->validated($request, $vendor));
        [$old, $new] = AuditLogger::diff($before, $vendor->only($vendor->getFillable()));
        if ($new) {
            AuditLogger::log('vendor.updated', 'vendors', $vendor->id, "Updated vendor {$vendor->name}", $old, $new);
        }

        return new VendorResource($vendor);
    }

    public function balance(Vendor $vendor): JsonResponse
    {
        return response()->json(['data' => VendorBalance::forVendor($vendor->id)]);
    }

    public function payables(Vendor $vendor): JsonResponse
    {
        return response()->json(['data' => VendorBalance::payables($vendor->id)]);
    }

    /** Items bought from this vendor with their purchase price history (from PO lines; nothing stored separately). */
    public function items(Vendor $vendor): JsonResponse
    {
        $rows = PriceHistory::query()->where('po.vendor_id', $vendor->id)->limit(2000)->get();

        return response()->json(['data' => $rows->groupBy('item_id')->map(fn ($history) => [
            'item' => ['id' => $history[0]->item_id, 'code' => $history[0]->item_code, 'name' => $history[0]->item_name, 'unit' => $history[0]->unit],
            'total_purchased' => $history->reduce(fn ($sum, $r) => Decimal::add($sum, $r->quantity), '0'),
            'last_price' => $history[0]->unit_price,
            'last_purchase_date' => $history[0]->purchase_date,
            'history' => $history->take(20)->map(fn ($r) => PriceHistory::shape($r))->values(),
        ])->values()]);
    }

    public function activities(Request $request, Vendor $vendor): JsonResponse
    {
        $modules = ['vendors' => [$vendor->id], 'purchase_orders' => $vendor->purchaseOrders()->pluck('id')->all()];
        if ($request->user()->can('payments.view')) {
            $modules['payments'] = $vendor->payments()->pluck('id')->all();
        }
        $logs = ActivityLog::query()->with('user:id,name')
            ->where(fn ($q) => collect($modules)->each(fn ($ids, $module) => $q->orWhere(fn ($q) => $q->where('module', $module)->whereIn('record_id', $ids ?: [0]))))
            ->latest('id')->paginate(ListQuery::perPage($request));

        return response()->json(ListQuery::envelope($logs->through(fn ($log) => [
            'id' => $log->id, 'action' => $log->action, 'module' => $log->module, 'record_id' => $log->record_id,
            'description' => $log->description, 'user' => $log->user?->only(['id', 'name']), 'created_at' => $log->created_at->toIso8601String(),
        ])));
    }

    private function validated(Request $request, ?Vendor $vendor = null): array
    {
        $required = $vendor ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$required, 'string', 'max:190'],
            'code' => ['nullable', 'string', 'max:30', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('vendors', 'code')->ignore($vendor?->id)],
            'contact_person' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:40', 'regex:/^[0-9+\-\s()]+$/'],
            'email' => ['nullable', 'email', 'max:190'],
            'address' => ['nullable', 'string', 'max:1000'],
            'company_info' => ['nullable', 'string', 'max:2000'],
            'tax_number' => ['nullable', 'string', 'max:60'],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
    }
}
