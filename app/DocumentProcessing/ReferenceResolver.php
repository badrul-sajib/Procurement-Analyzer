<?php

namespace App\DocumentProcessing;

use App\Models\Department;
use App\Models\Unit;
use App\Support\ItemName;

/** Maps free-text units and departments from documents to master data. Loaded once per job. */
final class ReferenceResolver
{
    /** @var array<string, int> */
    private array $units = [];

    /** @var array<string, int> */
    private array $departments = [];

    public function __construct()
    {
        foreach (Unit::where('is_active', true)->get(['id', 'code', 'name', 'synonyms']) as $unit) {
            foreach ([$unit->code, $unit->name, ...($unit->synonyms ?? [])] as $label) {
                $this->units[self::key($label)] ??= $unit->id;
            }
        }
        foreach (Department::where('is_active', true)->get(['id', 'code', 'name']) as $department) {
            $this->departments[self::key($department->code)] ??= $department->id;
            $this->departments[self::key($department->name)] ??= $department->id;
        }
    }

    public function unitId(?string $text): ?int
    {
        if ($text === null || $text === '') {
            return null;
        }
        $key = self::key($text);

        // Simple plural fallback: "reams" → "ream", "boxes" → "box".
        return $this->units[$key] ?? $this->units[rtrim($key, 's')] ?? $this->units[preg_replace('/es$/', '', $key)] ?? null;
    }

    public function isUnit(string $text): bool
    {
        return $this->unitId($text) !== null;
    }

    public function departmentId(?string $text): ?int
    {
        return $text ? ($this->departments[self::key($text)] ?? null) : null;
    }

    private static function key(string $text): string
    {
        return str_replace(' ', '', ItemName::normalize($text));
    }
}
