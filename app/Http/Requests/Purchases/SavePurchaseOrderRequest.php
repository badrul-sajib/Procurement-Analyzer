<?php

namespace App\Http\Requests\Purchases;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SavePurchaseOrderRequest extends FormRequest
{
    public function rules(): array
    {
        $creating = $this->isMethod('post');

        return [
            'vendor_id' => [$creating ? 'required' : 'sometimes', 'integer', Rule::exists('vendors', 'id')],
            'order_date' => [$creating ? 'required' : 'sometimes', 'date_format:Y-m-d'],
            'additional_charges' => ['nullable', 'decimal:0,2', 'min:0', 'max:9999999999'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*.item_id' => ['required', 'integer', 'distinct', Rule::exists('items', 'id')],
            'lines.*.quantity' => ['required', 'decimal:0,3', 'gt:0', 'max:999999999'],
            'lines.*.unit_price' => ['required', 'decimal:0,4', 'min:0', 'max:9999999999'],
            'lines.*.discount_amount' => ['nullable', 'decimal:0,2', 'min:0'],
            'lines.*.tax_amount' => ['nullable', 'decimal:0,2', 'min:0'],
            'lines.*.notes' => ['nullable', 'string', 'max:1000'],
            'lines.*.requisition_item_ids' => ['nullable', 'array'],
            'lines.*.requisition_item_ids.*' => ['integer'],
        ];
    }
}
