<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Purchases\ApprovePurchaseOrder;
use App\Actions\Purchases\CancelPurchaseOrder;
use App\Actions\Purchases\CompletePurchaseOrder;
use App\Actions\Purchases\MarkPurchaseOrderPurchased;
use App\Actions\Purchases\SavePurchaseOrder;
use App\Actions\Purchases\SubmitPurchaseOrder;
use App\Enums\DeliveryStatus;
use App\Enums\PurchaseOrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Purchases\SavePurchaseOrderRequest;
use App\Http\Resources\PurchaseOrderResource;
use App\Models\Item;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Queries\Analyzer;
use App\Support\DateRange;
use App\Support\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PurchaseOrderController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(DateRange::rules() + [
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string'],
            'vendor_id' => ['nullable', 'integer'],
            'item_id' => ['nullable', 'integer'],
            'date_basis' => ['nullable', 'in:order_date,purchase_date'],
        ]);

        $query = PurchaseOrder::query()->withTotals()->with(['vendor', 'creator'])
            ->when($request->query('search'), fn ($q, $s) => $q->where(fn ($q) => $q
                ->where('po_number', 'like', ListQuery::like($s))
                ->orWhereHas('vendor', fn ($v) => $v->where('name', 'like', ListQuery::like($s)))))
            ->when($request->query('status'), fn ($q, $s) => $q->whereIn('status', array_intersect(explode(',', $s), array_column(PurchaseOrderStatus::cases(), 'value'))))
            ->when($request->filled('vendor_id'), fn ($q) => $q->where('vendor_id', $request->integer('vendor_id')))
            ->when($request->filled('item_id'), fn ($q) => $q->whereHas('items', fn ($i) => $i->where('item_id', $request->integer('item_id'))));

        if ($range = DateRange::fromRequest($request)) {
            $query->whereBetween($request->query('date_basis') === 'purchase_date' ? 'purchase_date' : 'order_date', $range->dates());
        }

        return PurchaseOrderResource::collection(ListQuery::paginate($query, $request, [
            'po_number' => 'id', 'order_date' => 'order_date', 'purchase_date' => 'purchase_date', 'status' => 'status',
            'grand_total' => 'subtotal', 'id' => 'id',
        ], '-po_number'));
    }

    public function show(PurchaseOrder $purchaseOrder): PurchaseOrderResource
    {
        $po = PurchaseOrder::withTotals()->with([
            'vendor', 'creator', 'items.item.defaultUnit', 'items.requisitionAllocations.requisitionItem.requisition',
            'items' => fn ($q) => $q->withSum(['deliveryItems as delivered_quantity' => fn ($d) => $d
                ->whereHas('delivery', fn ($x) => $x->where('status', DeliveryStatus::Confirmed->value))], 'quantity'),
        ])->findOrFail($purchaseOrder->id);

        $ids = collect(['submitted', 'approved', 'purchased', 'completed', 'cancelled'])->map(fn ($e) => $po->{"{$e}_by"})->filter()->unique();
        $po->people = User::whereIn('id', $ids)->pluck('name', 'id')->all();

        return new PurchaseOrderResource($po);
    }

    public function store(SavePurchaseOrderRequest $request, SavePurchaseOrder $save): JsonResponse
    {
        $po = $save->handle($request->validated(), null, $request->user()->id);

        return $this->show($po)->response()->setStatusCode(201);
    }

    public function update(SavePurchaseOrderRequest $request, PurchaseOrder $purchaseOrder, SavePurchaseOrder $save): PurchaseOrderResource
    {
        return $this->show($save->handle($request->validated(), $purchaseOrder, $request->user()->id));
    }

    public function submit(Request $request, PurchaseOrder $purchaseOrder, SubmitPurchaseOrder $submit): PurchaseOrderResource
    {
        return $this->show($submit->handle($purchaseOrder, $request->user()->id));
    }

    public function approve(Request $request, PurchaseOrder $purchaseOrder, ApprovePurchaseOrder $approve): PurchaseOrderResource
    {
        return $this->show($approve->handle($purchaseOrder, $request->user()->id));
    }

    public function markPurchased(Request $request, PurchaseOrder $purchaseOrder, MarkPurchaseOrderPurchased $mark): PurchaseOrderResource
    {
        $data = $request->validate([
            'purchase_date' => ['required', 'date_format:Y-m-d'],
            'additional_charges' => ['nullable', 'decimal:0,2', 'min:0'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.id' => ['required', 'integer', 'distinct'],
            'lines.*.purchased_quantity' => ['required', 'decimal:0,3', 'min:0'],
            'lines.*.unit_price' => ['nullable', 'decimal:0,4', 'min:0'],
            'lines.*.discount_amount' => ['nullable', 'decimal:0,2', 'min:0'],
            'lines.*.tax_amount' => ['nullable', 'decimal:0,2', 'min:0'],
        ]);
        $data['lines'] = array_map(fn ($l) => array_map(fn ($v) => is_numeric($v) ? (string) $v : $v, array_filter($l, fn ($v) => $v !== null)), $data['lines']);

        return $this->show($mark->handle($purchaseOrder, $data, $request->user()->id));
    }

    public function complete(Request $request, PurchaseOrder $purchaseOrder, CompletePurchaseOrder $complete): PurchaseOrderResource
    {
        return $this->show($complete->handle($purchaseOrder, $request->user()->id));
    }

    public function cancel(Request $request, PurchaseOrder $purchaseOrder, CancelPurchaseOrder $cancel): PurchaseOrderResource
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        return $this->show($cancel->handle($purchaseOrder, $data['reason'], $request->user()->id));
    }

    /** Items with approved quantity still pending purchase (the PO builder's source list). */
    public function availableItems(Request $request): JsonResponse
    {
        $request->merge(['status' => 'approved']);
        $analyzer = Analyzer::fromRequest($request);
        $page = $analyzer->paginate($request->merge(['sort' => $request->query('sort', 'name')]), onlyPending: true);

        return response()->json(ListQuery::envelope($page));
    }

    /** Approved requisition lines of one item that still have pending quantity, oldest first. */
    public function availableLines(Request $request, Item $item): JsonResponse
    {
        $request->merge(['status' => 'approved']);
        $lines = collect(Analyzer::fromRequest($request)->sources($item->id))->filter(fn ($l) => bccomp($l['pending'], '0', 3) > 0)->values();

        return response()->json(['data' => $lines]);
    }
}
