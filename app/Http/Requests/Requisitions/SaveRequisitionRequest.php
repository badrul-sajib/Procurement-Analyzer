<?php

namespace App\Http\Requests\Requisitions;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveRequisitionRequest extends FormRequest
{
    public function rules(): array
    {
        $creating = $this->isMethod('post');

        // shortcut: per-line exists rules run one query each; fine for typical requisitions (<500 lines).
        return [
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'max:190'],
            'external_reference' => ['nullable', 'string', 'max:100'],
            'requisition_date' => ['nullable', 'date_format:Y-m-d'],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')],
            'notes' => ['nullable', 'string', 'max:5000'],
            'lines' => [$creating ? 'sometimes' : 'sometimes', 'array', 'max:2000'],
            'lines.*.id' => ['nullable', 'integer', 'distinct'],
            'lines.*.original_name' => ['required_without:lines.*.id', 'nullable', 'string', 'max:255'],
            'lines.*.original_unit' => ['nullable', 'string', 'max:50'],
            'lines.*.description' => ['nullable', 'string', 'max:2000'],
            'lines.*.quantity' => ['nullable', 'decimal:0,3', 'min:0', 'max:999999999'],
            'lines.*.unit_id' => ['nullable', 'integer', Rule::exists('units', 'id')],
            'lines.*.item_id' => ['nullable', 'integer', Rule::exists('items', 'id')],
            'lines.*.requested_date' => ['nullable', 'date_format:Y-m-d'],
            'lines.*.department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')],
            'lines.*.notes' => ['nullable', 'string', 'max:1000'],
            'lines.*.save_alias' => ['sometimes', 'boolean'],
        ];
    }
}
