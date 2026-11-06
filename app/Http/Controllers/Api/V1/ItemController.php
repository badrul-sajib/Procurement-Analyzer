<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Items\AddItemAlias;
use App\Actions\Items\SaveItem;
use App\Http\Controllers\Controller;
use App\Http\Requests\Items\ItemRequest;
use App\Http\Resources\ItemResource;
use App\Models\Item;
use App\Models\ItemAlias;
use App\Queries\PriceHistory;
use App\Support\AuditLogger;
use App\Support\ItemName;
use App\Support\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ItemController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'category_id' => ['nullable', 'integer'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $query = Item::query()->with(['category', 'defaultUnit'])
            ->when($request->query('search'), function ($q, $search) {
                $like = ListQuery::like($search);
                $normalized = ListQuery::like(ItemName::normalize($search));
                $q->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('code', 'like', $like)
                    ->orWhere('sku', 'like', $like)
                    ->orWhereHas('aliases', fn ($a) => $a->where('normalized_alias', 'like', $normalized)));
            })
            ->when($request->filled('category_id'), fn ($q) => $q->where('category_id', $request->integer('category_id')))
            ->when($request->has('is_active'), fn ($q) => $q->where('is_active', $request->boolean('is_active')));

        return ItemResource::collection(ListQuery::paginate($query, $request, [
            'name' => 'name', 'code' => 'code', 'created_at' => 'created_at', 'updated_at' => 'updated_at', 'id' => 'id',
        ], 'name'));
    }

    public function show(Item $item): ItemResource
    {
        return new ItemResource($item->load(['category', 'defaultUnit', 'aliases', 'conversions.fromUnit', 'conversions.toUnit']));
    }

    public function purchaseHistory(Item $item): JsonResponse
    {
        return response()->json(['data' => PriceHistory::query()->where('poi.item_id', $item->id)->limit(200)->get()
            ->map(fn ($r) => PriceHistory::shape($r))]);
    }

    public function store(ItemRequest $request, SaveItem $save): JsonResponse
    {
        $item = $save->handle($request->validated(), null, $request->user()->id);

        return $this->show($item)->response()->setStatusCode(201);
    }

    public function update(ItemRequest $request, Item $item, SaveItem $save): ItemResource
    {
        return $this->show($save->handle($request->validated(), $item, $request->user()->id));
    }

    public function addAlias(Request $request, Item $item, AddItemAlias $add): JsonResponse
    {
        $data = $request->validate(['alias' => ['required', 'string', 'max:190']]);
        $alias = $add->handle($item, $data['alias'], $request->user()->id);

        return response()->json(['data' => ['id' => $alias->id, 'alias' => $alias->alias]], 201);
    }

    public function removeAlias(Item $item, ItemAlias $alias): JsonResponse
    {
        abort_unless($alias->item_id === $item->id, 404);
        $alias->delete();
        AuditLogger::log('item.alias_removed', 'items', $item->id, "Alias \"{$alias->alias}\" removed from {$item->name}", ['alias' => $alias->alias]);

        return response()->json(null, 204);
    }
}
