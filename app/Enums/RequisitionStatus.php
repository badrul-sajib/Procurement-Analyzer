<?php

namespace App\Enums;

enum RequisitionStatus: string
{
    case Draft = 'draft';
    case Uploaded = 'uploaded';
    case Processing = 'processing';
    case Extracted = 'extracted';
    case Failed = 'failed';
    case Verified = 'verified';
    case Approved = 'approved';
    case Cancelled = 'cancelled';

    /** Lines may be edited only before verification. */
    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::Extracted, self::Failed], true);
    }

    /** Statuses whose lines count in the analyzer ("confirmed" requisitions). */
    public static function confirmed(): array
    {
        return [self::Verified->value, self::Approved->value];
    }
}
