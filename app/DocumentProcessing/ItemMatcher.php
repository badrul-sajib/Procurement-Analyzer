<?php

namespace App\DocumentProcessing;

use App\Enums\MatchType;
use App\Models\Item;
use App\Models\ItemAlias;
use App\Support\ItemName;
use Illuminate\Support\Collection;

/**
 * Exact normalized name or alias matches are linked automatically; anything fuzzier is only
 * ever offered as a suggestion for a human to confirm.
 */
final class ItemMatcher
{
    /** @return array{0: Item, 1: MatchType}|null */
    public function match(string $name): ?array
    {
        $normalized = ItemName::normalize($name);
        if ($normalized === '') {
            return null;
        }
        if ($item = Item::where('normalized_name', $normalized)->where('is_active', true)->first()) {
            return [$item, MatchType::Exact];
        }
        $alias = ItemAlias::where('normalized_alias', $normalized)->with('item')->first();
        if ($alias && $alias->item->is_active) {
            return [$alias->item, MatchType::Alias];
        }

        return null;
    }

    /**
     * Batched exact/alias matching for many names (2 queries total).
     *
     * @param  string[]  $names
     * @return array<string, array{0: Item, 1: MatchType}> keyed by normalized name
     */
    public function matchMany(array $names): array
    {
        $keys = array_values(array_unique(array_filter(array_map([ItemName::class, 'normalize'], $names))));
        if (! $keys) {
            return [];
        }
        $matches = [];
        foreach (Item::whereIn('normalized_name', $keys)->where('is_active', true)->get() as $item) {
            $matches[$item->normalized_name] = [$item, MatchType::Exact];
        }
        foreach (ItemAlias::whereIn('normalized_alias', $keys)->with('item')->get() as $alias) {
            if ($alias->item->is_active) {
                $matches[$alias->normalized_alias] ??= [$alias->item, MatchType::Alias];
            }
        }

        return $matches;
    }

    /**
     * Up to $limit candidates ranked by text similarity (0–100).
     * shortcut: LIKE prefilter + PHP similar_text; fine for catalogs of ~10k items. Upgrade to a
     * FULLTEXT/ngram index if the catalog grows far beyond that.
     *
     * @return Collection<int, array{item: Item, score: int}>
     */
    public function suggestions(string $name, int $limit = 5): Collection
    {
        $normalized = ItemName::normalize($name);
        // Prefix of each word, plural-stripped, so "papers" still finds "paper".
        $tokens = array_values(array_unique(array_map(
            fn ($t) => mb_substr(preg_replace('/(es|s)$/u', '', $t), 0, 6),
            array_filter(explode(' ', $normalized), fn ($t) => mb_strlen($t) >= 3),
        )));
        if (! $tokens) {
            $tokens = array_filter([$normalized]);
        }
        if (! $tokens) {
            return collect();
        }

        $like = fn ($q, string $column) => $q->where(fn ($q) => collect($tokens)->each(fn ($t) => $q->orWhere($column, 'like', '%'.addcslashes($t, '%_\\').'%')));

        $byName = Item::where('is_active', true)->where(fn ($q) => $like($q, 'normalized_name'))->limit(50)->get();
        $byAlias = ItemAlias::query()->where(fn ($q) => $like($q, 'normalized_alias'))->with('item')->limit(50)->get();

        $scores = [];
        foreach ($byName as $item) {
            $scores[$item->id] = ['item' => $item, 'score' => self::score($normalized, $item->normalized_name)];
        }
        foreach ($byAlias as $alias) {
            if (! $alias->item->is_active) {
                continue;
            }
            $score = self::score($normalized, $alias->normalized_alias);
            if ($score > ($scores[$alias->item_id]['score'] ?? -1)) {
                $scores[$alias->item_id] = ['item' => $alias->item, 'score' => $score];
            }
        }

        return collect($scores)->filter(fn ($s) => $s['score'] >= 40)->sortByDesc('score')->take($limit)->values();
    }

    private static function score(string $a, string $b): int
    {
        similar_text($a, $b, $percent);

        return (int) round($percent);
    }
}
