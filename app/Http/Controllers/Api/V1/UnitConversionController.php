<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UnitConversionResource;
use App\Models\UnitConversion;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Conversion rules only affect requisitions verified after the change: verified lines keep the
 * factor frozen at verification, so editing or deleting a rule never rewrites history.
 */
class UnitConversionController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(['item_id' => ['nullable', 'integer'], 'scope' => ['nullable', 'in:global,item']]);

        $conversions = UnitConversion::query()->with(['item:id,name', 'fromUnit', 'toUnit'])
            ->when($request->filled('item_id'), fn ($q) => $q->where('item_id', $request->integer('item_id')))
            ->when($request->query('scope') === 'global', fn ($q) => $q->whereNull('item_id'))
            ->when($request->query('scope') === 'item', fn ($q) => $q->whereNotNull('item_id'))
            ->orderBy('item_id')->orderBy('from_unit_id')
            ->paginate(100);

        return UnitConversionResource::collection($conversions);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'item_id' => ['nullable', 'integer', Rule::exists('items', 'id')],
            'from_unit_id' => ['required', 'integer', Rule::exists('units', 'id'), 'different:to_unit_id',
                Rule::unique('unit_conversions')->where(fn ($q) => $q->where('to_unit_id', $request->input('to_unit_id'))
                    ->where('scope_key', (int) $request->input('item_id')))],
            'to_unit_id' => ['required', 'integer', Rule::exists('units', 'id')],
            'factor' => ['required', 'decimal:0,6', 'gt:0', 'max:999999999'],
        ], ['from_unit_id.unique' => 'A conversion between these units already exists for this scope.']);

        $conversion = UnitConversion::create($data)->load(['item:id,name', 'fromUnit', 'toUnit']);
        AuditLogger::log('unit_conversion.created', 'unit_conversions', $conversion->id, $this->describe($conversion), null, $data);

        return (new UnitConversionResource($conversion))->response()->setStatusCode(201);
    }

    public function update(Request $request, UnitConversion $unitConversion): UnitConversionResource
    {
        $data = $request->validate(['factor' => ['required', 'decimal:0,6', 'gt:0', 'max:999999999']]);
        $old = ['factor' => $unitConversion->factor];
        $unitConversion->update($data);
        AuditLogger::log('unit_conversion.updated', 'unit_conversions', $unitConversion->id, null, $old, $data);

        return new UnitConversionResource($unitConversion->load(['item:id,name', 'fromUnit', 'toUnit']));
    }

    public function destroy(UnitConversion $unitConversion): JsonResponse
    {
        $unitConversion->load(['fromUnit', 'toUnit', 'item:id,name']);
        AuditLogger::log('unit_conversion.deleted', 'unit_conversions', $unitConversion->id, $this->describe($unitConversion), $unitConversion->only(['item_id', 'from_unit_id', 'to_unit_id', 'factor']));
        $unitConversion->delete();

        return response()->json(null, 204);
    }

    private function describe(UnitConversion $c): string
    {
        return sprintf('1 %s = %s %s%s', $c->fromUnit->name, rtrim(rtrim($c->factor, '0'), '.'), $c->toUnit->name, $c->item ? " ({$c->item->name})" : '');
    }
}
