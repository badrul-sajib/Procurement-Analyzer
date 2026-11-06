<?php

namespace App\Models;

use App\Support\ItemName;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['item_id', 'alias', 'created_by'])]
class ItemAlias extends Model
{
    protected static function booted(): void
    {
        static::saving(fn (ItemAlias $alias) => $alias->normalized_alias = ItemName::normalize($alias->alias));
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
