<?php

namespace App\DocumentProcessing;

final class ExtractionResult
{
    /** @var ExtractedLine[] */
    public array $lines = [];

    /** @var string[] raw lines that looked like content but could not be parsed */
    public array $skipped = [];

    public ?string $externalReference = null;

    public ?string $requisitionDate = null;

    public ?string $department = null;

    public ?string $title = null;

    public function __construct(public string $extractor) {}

    public function skip(string $line): void
    {
        if (count($this->skipped) < 50) {
            $this->skipped[] = mb_substr($line, 0, 200);
        }
    }
}
