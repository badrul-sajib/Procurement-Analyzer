<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Deliveries\ConfirmDelivery;
use App\Actions\Deliveries\CreateDelivery;
use App\Actions\Deliveries\DeliveryCapacity;
use App\Actions\Deliveries\ReverseDelivery;
use App\Enums\DeliveryStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\DeliveryResource;
use App\Models\Delivery;
use App\Models\PurchaseOrder;
use App\Support\DateRange;
use App\Support\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class DeliveryController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(DateRange::rules() + [
            'search' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', 'string'],
            'purchase_order_id' => ['nullable', 'integer'], 'vendor_id' => ['nullable', 'integer'],
        ]);

        $query = Delivery::query()->with(['purchaseOrder.vendor', 'receiver'])->withSum('items as total_quantity', 'quantity')
            ->when($request->query('search'), fn ($q, $s) => $q->where(fn ($q) => $q
                ->where('delivery_number', 'like', ListQuery::like($s))->orWhere('delivery_reference', 'like', ListQuery::like($s))
                ->orWhereHas('purchaseOrder', fn ($p) => $p->where('po_number', 'like', ListQuery::like($s)))))
            ->when($request->query('status'), fn ($q, $s) => $q->whereIn('status', array_intersect(explode(',', $s), array_column(DeliveryStatus::cases(), 'value'))))
            ->when($request->filled('purchase_order_id'), fn ($q) => $q->where('purchase_order_id', $request->integer('purchase_order_id')))
            ->when($request->filled('vendor_id'), fn ($q) => $q->whereHas('purchaseOrder', fn ($p) => $p->where('vendor_id', $request->integer('vendor_id'))));

        if ($range = DateRange::fromRequest($request)) {
            $query->whereBetween('delivery_date', $range->dates());
        }

        return DeliveryResource::collection(ListQuery::paginate($query, $request, [
            'delivery_number' => 'id', 'delivery_date' => 'delivery_date', 'status' => 'status', 'id' => 'id',
        ], '-delivery_number'));
    }

    public function show(Delivery $delivery): DeliveryResource
    {
        return new DeliveryResource($delivery->load(['purchaseOrder.vendor', 'receiver', 'creator', 'items.purchaseOrderItem.item.defaultUnit'])
            ->loadSum('items as total_quantity', 'quantity'));
    }

    public function store(Request $request, CreateDelivery $create): JsonResponse
    {
        $data = $request->validate([
            'purchase_order_id' => ['required', 'integer', Rule::exists('purchase_orders', 'id')],
            'delivery_date' => ['required', 'date_format:Y-m-d'],
            'delivery_reference' => ['nullable', 'string', 'max:100'],
            'received_by' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'notes' => ['nullable', 'string', 'max:2000'],
            'confirm' => ['sometimes', 'boolean'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.purchase_order_item_id' => ['required', 'integer', 'distinct'],
            'lines.*.quantity' => ['required', 'decimal:0,3', 'gt:0'],
            'lines.*.notes' => ['nullable', 'string', 'max:500'],
        ]);
        // Creating and confirming in one step needs the confirm permission as well.
        $confirm = $request->boolean('confirm');
        abort_if($confirm && ! $request->user()->can('deliveries.confirm'), 403, 'You may record deliveries but not confirm them.');

        $delivery = $create->handle($data, $request->user()->id, $confirm);

        return $this->show($delivery)->response()->setStatusCode(201);
    }

    public function confirm(Request $request, Delivery $delivery, ConfirmDelivery $confirm): DeliveryResource
    {
        return $this->show($confirm->handle($delivery, $request->user()->id));
    }

    public function reverse(Request $request, Delivery $delivery, ReverseDelivery $reverse): DeliveryResource
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        return $this->show($reverse->handle($delivery, $data['reason'], $request->user()->id));
    }

    /** What can still be delivered on a PO (purchased − confirmed − pending), for the delivery form. */
    public function deliverable(PurchaseOrder $purchaseOrder): JsonResponse
    {
        $remaining = DeliveryCapacity::remaining($purchaseOrder->id, includePending: true);
        $lines = $purchaseOrder->items()->with('item.defaultUnit')->get()->map(fn ($l) => [
            'purchase_order_item_id' => $l->id,
            'item' => ['id' => $l->item->id, 'name' => $l->item->name, 'unit' => $l->item->defaultUnit->name],
            'purchased_quantity' => $l->purchased_quantity,
            'remaining' => $remaining[$l->id] ?? '0.000',
        ]);

        return response()->json(['data' => $lines]);
    }
}
