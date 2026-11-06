<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\LookupResource;
use App\Models\Department;
use App\Models\ItemCategory;
use App\Models\Unit;
use App\Support\AuditLogger;
use App\Support\ListQuery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Departments, units and item categories share one shape (code, name, active), so one controller
 * serves all three. The route supplies the {lookup} default.
 */
class LookupController extends Controller
{
    private const MODELS = [
        'departments' => Department::class,
        'units' => Unit::class,
        'item-categories' => ItemCategory::class,
    ];

    public function index(Request $request): AnonymousResourceCollection
    {
        $model = $this->model($request);
        $query = $model::query()
            ->when($request->query('search'), fn ($q, $s) => $q->where(fn ($q) => $q
                ->where('name', 'like', ListQuery::like($s))->orWhere('code', 'like', ListQuery::like($s))))
            ->when($request->has('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')));

        // Lookups feed dropdowns: ?all=1 returns every row (they are small reference tables).
        if ($request->boolean('all')) {
            return LookupResource::collection($query->orderBy('name')->get());
        }

        return LookupResource::collection(ListQuery::paginate($query, $request, ['name' => 'name', 'code' => 'code', 'id' => 'id'], 'name'));
    }

    public function store(Request $request): LookupResource
    {
        $model = $this->model($request);
        $record = $model::create($this->validated($request));
        AuditLogger::log($this->module($request).'.created', $this->module($request), $record->id, "Created {$record->name}", null, $record->only($record->getFillable()));

        return new LookupResource($record);
    }

    public function update(Request $request, int $id): LookupResource
    {
        $record = $this->model($request)::findOrFail($id);
        $before = $record->only($record->getFillable());
        $record->update($this->validated($request, $id));
        [$old, $new] = AuditLogger::diff($before, $record->only($record->getFillable()));
        if ($new) {
            AuditLogger::log($this->module($request).'.updated', $this->module($request), $record->id, "Updated {$record->name}", $old, $new);
        }

        return new LookupResource($record);
    }

    private function validated(Request $request, ?int $id = null): array
    {
        $table = (new ($this->model($request)))->getTable();
        $rules = [
            'code' => [$id ? 'sometimes' : 'required', 'string', 'max:30', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique($table, 'code')->ignore($id)],
            'name' => [$id ? 'sometimes' : 'required', 'string', 'max:150'],
            'is_active' => ['sometimes', 'boolean'],
        ];
        if ($table === 'units') {
            $rules['code'][2] = 'max:20';
            $rules['synonyms'] = ['sometimes', 'array', 'max:20'];
            $rules['synonyms.*'] = ['string', 'max:30', 'distinct'];
        }

        return $request->validate($rules);
    }

    private function model(Request $request): string
    {
        return self::MODELS[$request->route('lookup')];
    }

    private function module(Request $request): string
    {
        return str_replace('-', '_', $request->route('lookup'));
    }
}
