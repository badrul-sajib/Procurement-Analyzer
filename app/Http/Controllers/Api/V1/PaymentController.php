<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Payments\AllocatePayment;
use App\Actions\Payments\RecordPayment;
use App\Actions\Payments\ReversePayment;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentResource;
use App\Models\Payment;
use App\Support\DateRange;
use App\Support\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class PaymentController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(DateRange::rules() + [
            'search' => ['nullable', 'string', 'max:100'], 'vendor_id' => ['nullable', 'integer'],
            'method' => ['nullable', Rule::enum(PaymentMethod::class)], 'status' => ['nullable', Rule::enum(PaymentStatus::class)],
        ]);

        $query = Payment::query()->with(['vendor', 'creator'])->withSum('allocations as allocated_amount', 'amount')
            ->when($request->query('search'), fn ($q, $s) => $q->where(fn ($q) => $q
                ->where('payment_reference', 'like', ListQuery::like($s))->orWhere('transaction_reference', 'like', ListQuery::like($s))
                ->orWhereHas('vendor', fn ($v) => $v->where('name', 'like', ListQuery::like($s)))))
            ->when($request->filled('vendor_id'), fn ($q) => $q->where('vendor_id', $request->integer('vendor_id')))
            ->when($request->query('method'), fn ($q, $m) => $q->where('method', $m))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s));

        if ($range = DateRange::fromRequest($request)) {
            $query->whereBetween('payment_date', $range->dates());
        }

        return PaymentResource::collection(ListQuery::paginate($query, $request, [
            'payment_reference' => 'id', 'payment_date' => 'payment_date', 'amount' => 'amount', 'id' => 'id',
        ], '-payment_date'));
    }

    public function show(Payment $payment): PaymentResource
    {
        return new PaymentResource($payment->load(['vendor', 'creator', 'allocations.purchaseOrder'])->loadSum('allocations as allocated_amount', 'amount'));
    }

    public function store(Request $request, RecordPayment $record): JsonResponse
    {
        $data = $request->validate([
            'vendor_id' => ['required', 'integer', Rule::exists('vendors', 'id')],
            'payment_date' => ['required', 'date_format:Y-m-d'],
            'amount' => ['required', 'decimal:0,2', 'gt:0', 'max:9999999999'],
            'method' => ['required', Rule::enum(PaymentMethod::class)],
            'transaction_reference' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'allocations' => ['sometimes', 'array', 'max:100'],
            'allocations.*.purchase_order_id' => ['required', 'integer'],
            'allocations.*.amount' => ['required', 'decimal:0,2', 'gt:0'],
        ]);
        // Recording and allocating are separate permissions.
        abort_if(! empty($data['allocations']) && ! $request->user()->can('payments.allocate'), 403, 'You may record payments but not allocate them.');

        return $this->show($record->handle($data, $request->user()->id))->response()->setStatusCode(201);
    }

    public function allocate(Request $request, Payment $payment, AllocatePayment $allocate): PaymentResource
    {
        $data = $request->validate([
            'allocations' => ['required', 'array', 'min:1', 'max:100'],
            'allocations.*.purchase_order_id' => ['required', 'integer'],
            'allocations.*.amount' => ['required', 'decimal:0,2', 'gt:0'],
        ]);

        return $this->show($allocate->handle($payment, $data['allocations'], $request->user()->id));
    }

    public function reverse(Request $request, Payment $payment, ReversePayment $reverse): PaymentResource
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        return $this->show($reverse->handle($payment, $data['reason'], $request->user()->id));
    }
}
