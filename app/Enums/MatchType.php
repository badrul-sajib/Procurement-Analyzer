<?php

namespace App\Enums;

/** How a requisition line was linked to a catalog item. */
enum MatchType: string
{
    case Exact = 'exact';
    case Alias = 'alias';
    case Manual = 'manual';
}
