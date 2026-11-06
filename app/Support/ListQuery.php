<?php

namespace App\Support;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Server-side sorting (whitelisted) and pagination shared by every list endpoint. */
final class ListQuery
{
    /**
     * @param  array<string, string>  $sortable  public sort key => column/expression
     */
    public static function paginate(Builder $query, Request $request, array $sortable, string $default = '-id'): LengthAwarePaginator
    {
        $sort = (string) ($request->query('sort') ?: $default);
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $key = ltrim($sort, '-');

        if (! array_key_exists($key, $sortable)) {
            throw ValidationException::withMessages(['sort' => "Cannot sort by '{$key}'. Allowed: ".implode(', ', array_keys($sortable))]);
        }

        $query->orderBy($sortable[$key], $direction);
        if ($sortable[$key] !== 'id') {
            $query->orderBy($query->getModel()->getQualifiedKeyName(), $direction); // stable pagination
        }

        return $query->paginate(self::perPage($request))->withQueryString();
    }

    public static function perPage(Request $request): int
    {
        return max(1, min(100, (int) $request->query('per_page', 25)));
    }

    /** Same {data, meta} shape as paginated API Resources, for query-builder results. */
    public static function envelope(LengthAwarePaginator $page): array
    {
        return [
            'data' => $page->items(),
            'meta' => [
                'current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(),
                'last_page' => $page->lastPage(), 'from' => $page->firstItem(), 'to' => $page->lastItem(),
            ],
        ];
    }

    /** Escape LIKE wildcards in user input. */
    public static function like(string $term): string
    {
        return '%'.addcslashes($term, '%_\\').'%';
    }
}
