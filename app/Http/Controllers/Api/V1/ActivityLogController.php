<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Support\DateRange;
use App\Support\ListQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Read-only: there are no write endpoints for the audit trail. */
class ActivityLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate(DateRange::rules() + [
            'module' => ['nullable', 'string', 'max:40'], 'action' => ['nullable', 'string', 'max:60'],
            'user_id' => ['nullable', 'integer'], 'record_id' => ['nullable', 'integer'], 'search' => ['nullable', 'string', 'max:100'],
        ]);

        $query = ActivityLog::query()->with('user:id,name')
            ->when($request->query('module'), fn ($q, $m) => $q->where('module', $m))
            ->when($request->query('action'), fn ($q, $a) => $q->where('action', $a))
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->integer('user_id')))
            ->when($request->filled('record_id'), fn ($q) => $q->where('record_id', $request->integer('record_id')))
            ->when($request->query('search'), fn ($q, $s) => $q->where('description', 'like', ListQuery::like($s)));
        if ($range = DateRange::fromRequest($request)) {
            $query->whereBetween('created_at', $range->utcBounds());
        }

        $page = $query->orderByDesc('id')->paginate(ListQuery::perPage($request))->withQueryString();

        return response()->json(ListQuery::envelope($page->through(fn (ActivityLog $l) => [
            'id' => $l->id, 'action' => $l->action, 'module' => $l->module, 'record_id' => $l->record_id, 'description' => $l->description,
            'old_values' => $l->old_values, 'new_values' => $l->new_values, 'ip_address' => $l->ip_address,
            'user' => $l->user?->only(['id', 'name']), 'created_at' => $l->created_at->toIso8601String(),
        ])) + ['modules' => ActivityLog::query()->distinct()->orderBy('module')->pluck('module')]);
    }
}
