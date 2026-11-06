<?php

namespace App\Queries;

use App\Enums\RequisitionStatus;
use App\Support\DataVersion;
use App\Support\DateRange;
use App\Support\Decimal;
use App\Support\ListQuery;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Item-wise consolidation of confirmed (verified/approved) requisition lines. Unverified
 * requisitions never appear. Quantities are in each item's default unit.
 *
 * Metrics are kept distinct: requisition_count (documents), line_count (requisition lines),
 * unique items (rows), and quantities (physical amounts).
 */
final class Analyzer
{
    public const SORTS = ['name', 'code', 'requisition_count', 'line_count', 'requested', 'approved', 'on_order', 'purchased', 'pending', 'delivered', 'remaining_delivery'];

    public function __construct(private array $filters) {}

    public static function rules(): array
    {
        return DateRange::rules() + [
            'requisition_id' => ['nullable', 'integer'],
            'item_id' => ['nullable', 'integer'],
            'category_id' => ['nullable', 'integer'],
            'department_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:verified,approved'],
            'search' => ['nullable', 'string', 'max:100'],
            'purchase_status' => ['nullable', 'in:not_approved,not_purchased,partially_purchased,purchased'],
            'delivery_status' => ['nullable', 'in:not_delivered,partially_delivered,fully_delivered'],
        ];
    }

    public static function fromRequest(Request $request): self
    {
        $filters = $request->validate(self::rules());
        if ($range = DateRange::fromRequest($request)) {
            $filters['dates'] = $range->dates();
        }

        return new self($filters);
    }

    /** Confirmed requisition lines matching the filters. */
    public function lines(): Builder
    {
        $f = $this->filters;

        return DB::table('requisition_items as ri')
            ->join('requisitions as r', 'r.id', '=', 'ri.requisition_id')
            ->join('items as i', 'i.id', '=', 'ri.item_id')
            ->whereIn('r.status', isset($f['status']) ? [$f['status']] : RequisitionStatus::confirmed())
            ->when($f['dates'] ?? null, fn ($q, $d) => $q->whereBetween('r.requisition_date', $d))
            ->when($f['requisition_id'] ?? null, fn ($q, $v) => $q->where('r.id', $v))
            ->when($f['item_id'] ?? null, fn ($q, $v) => $q->where('ri.item_id', $v))
            ->when($f['category_id'] ?? null, fn ($q, $v) => $q->where('i.category_id', $v))
            ->when($f['department_id'] ?? null, fn ($q, $v) => $q->where('ri.department_id', $v))
            ->when($f['search'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q->where('i.name', 'like', ListQuery::like($s))->orWhere('i.code', 'like', ListQuery::like($s))));
    }

    /** Lines joined with their fulfilment figures. */
    private function linesWithFulfilment(): Builder
    {
        $lines = $this->lines();
        $fulfilment = RequisitionFulfilment::query((clone $lines)->select('ri.id'));
        $approved = RequisitionStatus::Approved->value;

        return $lines
            ->leftJoinSub($fulfilment, 'f', 'f.requisition_item_id', '=', 'ri.id')
            ->select('ri.id', 'ri.item_id', 'ri.requisition_id', 'ri.base_quantity')
            ->selectRaw("CASE WHEN r.status = '{$approved}' THEN COALESCE(ri.approved_quantity, 0) ELSE 0 END AS approved")
            ->selectRaw('COALESCE(f.on_order, 0) AS on_order, COALESCE(f.purchased, 0) AS purchased, COALESCE(f.delivered, 0) AS delivered');
    }

    /** One row per item with consolidated quantities. */
    public function items(): Builder
    {
        $rows = DB::query()->fromSub($this->linesWithFulfilment(), 'l')
            ->join('items as i', 'i.id', '=', 'l.item_id')
            ->join('units as u', 'u.id', '=', 'i.default_unit_id')
            ->leftJoin('item_categories as c', 'c.id', '=', 'i.category_id')
            ->groupBy('i.id', 'i.code', 'i.name', 'u.name', 'c.name')
            ->select('i.id', 'i.code', 'i.name', 'u.name as unit', 'c.name as category')
            ->selectRaw('COUNT(DISTINCT l.requisition_id) AS requisition_count, COUNT(*) AS line_count')
            ->selectRaw('SUM(l.base_quantity) AS requested, SUM(l.approved) AS approved, SUM(l.on_order) AS on_order')
            ->selectRaw('SUM(l.purchased) AS purchased, SUM(l.delivered) AS delivered')
            ->selectRaw('SUM(l.approved) - SUM(l.purchased) - SUM(l.on_order) AS pending')
            ->selectRaw('SUM(l.purchased) - SUM(l.delivered) AS remaining_delivery');

        $query = DB::query()->fromSub($rows, 'x')->select('x.*')
            ->selectRaw("CASE WHEN x.approved = 0 THEN 'not_approved' WHEN x.purchased >= x.approved THEN 'purchased' WHEN x.purchased > 0 THEN 'partially_purchased' ELSE 'not_purchased' END AS purchase_status")
            ->selectRaw("CASE WHEN x.purchased = 0 OR x.delivered = 0 THEN 'not_delivered' WHEN x.delivered >= x.purchased THEN 'fully_delivered' ELSE 'partially_delivered' END AS delivery_status");

        $outer = DB::query()->fromSub($query, 'y')->select('y.*');
        foreach (['purchase_status', 'delivery_status'] as $status) {
            if (! empty($this->filters[$status])) {
                $outer->where("y.{$status}", $this->filters[$status]);
            }
        }

        return $outer;
    }

    public function paginate(Request $request, bool $onlyPending = false)
    {
        $sort = (string) ($request->query('sort') ?: 'name');
        $key = ltrim($sort, '-');
        if (! in_array($key, self::SORTS, true)) {
            throw ValidationException::withMessages(['sort' => 'Allowed sorts: '.implode(', ', self::SORTS)]);
        }

        return $this->items()
            ->when($onlyPending, fn ($q) => $q->where('y.pending', '>', 0))
            ->orderBy("y.{$key}", str_starts_with($sort, '-') ? 'desc' : 'asc')
            ->orderBy('y.id')
            ->paginate(ListQuery::perPage($request))
            ->withQueryString()
            ->through(fn ($row) => self::shapeItem($row));
    }

    /** Totals over the same filtered set. Cached until the next write anywhere (DataVersion). */
    public function summary(): array
    {
        return DataVersion::remember('analyzer.summary', $this->filters, function () {
            $t = DB::query()->fromSub($this->linesWithFulfilment(), 'l')
                ->selectRaw('COUNT(DISTINCT l.requisition_id) AS requisitions, COUNT(DISTINCT l.item_id) AS unique_items, COUNT(*) AS line_count')
                ->selectRaw('COALESCE(SUM(l.base_quantity), 0) AS requested, COALESCE(SUM(l.approved), 0) AS approved')
                ->selectRaw('COALESCE(SUM(l.on_order), 0) AS on_order, COALESCE(SUM(l.purchased), 0) AS purchased, COALESCE(SUM(l.delivered), 0) AS delivered')
                ->first();

            return [
                'total_requisitions' => (int) $t->requisitions,
                'unique_items' => (int) $t->unique_items,
                'requisition_lines' => (int) $t->line_count,
                'requested_quantity' => Decimal::add($t->requested, 0),
                'approved_quantity' => Decimal::add($t->approved, 0),
                'on_order_quantity' => Decimal::add($t->on_order, 0),
                'purchased_quantity' => Decimal::add($t->purchased, 0),
                'pending_quantity' => Decimal::sub(Decimal::sub($t->approved, $t->purchased), $t->on_order),
                'delivered_quantity' => Decimal::add($t->delivered, 0),
                'remaining_delivery_quantity' => Decimal::sub($t->purchased, $t->delivered),
            ];
        });
    }

    /** Count of items in each purchase / delivery status. */
    public function statusSummary(): array
    {
        return DataVersion::remember('analyzer.status', $this->filters, function () {
            $base = $this->items();
            $count = fn (string $column) => DB::query()->fromSub(clone $base, 's')->groupBy($column)->selectRaw("{$column} AS status, COUNT(*) AS items")->pluck('items', 'status')->map(fn ($v) => (int) $v)->all();

            return ['purchase' => $count('purchase_status'), 'delivery' => $count('delivery_status')];
        });
    }

    /** Source requisition lines for one item, with per-line fulfilment. */
    public function sources(int $itemId): array
    {
        $this->filters['item_id'] = $itemId;
        $lines = $this->lines();
        $fulfilment = RequisitionFulfilment::query((clone $lines)->select('ri.id'));

        return $lines
            ->leftJoinSub($fulfilment, 'f', 'f.requisition_item_id', '=', 'ri.id')
            ->leftJoin('units as u', 'u.id', '=', 'ri.unit_id')
            ->leftJoin('departments as d', 'd.id', '=', 'ri.department_id')
            ->select('ri.id as line_id', 'r.id as requisition_id', 'r.reference_no', 'r.title', 'r.requisition_date', 'r.status',
                'ri.original_name', 'ri.quantity', 'u.name as unit', 'ri.base_quantity', 'ri.approved_quantity', 'd.name as department')
            ->selectRaw('COALESCE(f.on_order, 0) AS on_order, COALESCE(f.purchased, 0) AS purchased, COALESCE(f.delivered, 0) AS delivered')
            ->orderBy('r.requisition_date')->orderBy('r.id')
            ->limit(500)
            ->get()
            ->map(fn ($row) => [
                'line_id' => $row->line_id,
                'requisition' => ['id' => $row->requisition_id, 'reference_no' => $row->reference_no, 'title' => $row->title, 'requisition_date' => $row->requisition_date, 'status' => $row->status],
                'original_name' => $row->original_name,
                'quantity' => $row->quantity,
                'unit' => $row->unit,
                'department' => $row->department,
                'requested' => $row->base_quantity,
            ] + RequisitionFulfilment::shape($row->status === RequisitionStatus::Approved->value ? $row->approved_quantity : '0', $row->on_order, $row->purchased, $row->delivered))
            ->all();
    }

    private static function shapeItem(object $row): array
    {
        return [
            'item' => ['id' => $row->id, 'code' => $row->code, 'name' => $row->name, 'unit' => $row->unit, 'category' => $row->category],
            'requisition_count' => (int) $row->requisition_count,
            'line_count' => (int) $row->line_count,
            'requested' => Decimal::add($row->requested, 0),
            'approved' => Decimal::add($row->approved, 0),
            'on_order' => Decimal::add($row->on_order, 0),
            'purchased' => Decimal::add($row->purchased, 0),
            'pending' => Decimal::add($row->pending, 0),
            'delivered' => Decimal::add($row->delivered, 0),
            'remaining_delivery' => Decimal::add($row->remaining_delivery, 0),
            'purchase_status' => $row->purchase_status,
            'delivery_status' => $row->delivery_status,
        ];
    }
}
