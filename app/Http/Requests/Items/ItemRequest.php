<?php

namespace App\Http\Requests\Items;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ItemRequest extends FormRequest
{
    public function rules(): array
    {
        $id = $this->route('item')?->id;
        $required = $id ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'max:190'],
            'code' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('items', 'code')->ignore($id)],
            'sku' => ['nullable', 'string', 'max:64', Rule::unique('items', 'sku')->ignore($id)],
            'category_id' => ['nullable', 'integer', Rule::exists('item_categories', 'id')],
            'default_unit_id' => [$required, 'integer', Rule::exists('units', 'id')->where('is_active', true)],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
            'aliases' => [$id ? 'prohibited' : 'sometimes', 'array', 'max:20'],
            'aliases.*' => ['string', 'max:190', 'distinct:ignore_case'],
        ];
    }
}
