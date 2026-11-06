<?php

namespace App\DocumentProcessing;

final class ExtractedLine
{
    public function __construct(
        public string $name,
        public ?string $quantity = null,
        public ?string $unit = null,
        public ?string $description = null,
        public ?string $requestedDate = null,
        public ?string $department = null,
        public ?string $notes = null,
    ) {}
}
