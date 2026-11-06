<?php

namespace App\Jobs;

use App\Actions\Requisitions\ApplyExtraction;
use App\DocumentProcessing\DocumentType;
use App\DocumentProcessing\ExtractionFailed;
use App\DocumentProcessing\Extractors\DocumentExtractor;
use App\DocumentProcessing\ReferenceResolver;
use App\Enums\RequisitionStatus;
use App\Models\Requisition;
use App\Models\RequisitionProcessingLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class ProcessRequisitionDocument implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    public array $backoff = [10, 60];

    public function __construct(public int $requisitionId)
    {
        $this->onQueue('documents');
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping((string) $this->requisitionId))->releaseAfter(30)->expireAfter(600)];
    }

    public function handle(DocumentExtractor $extractor, ApplyExtraction $apply): void
    {
        $requisition = Requisition::with('file')->find($this->requisitionId);
        // Cancelled, already verified or reprocessed elsewhere: nothing to do.
        if (! $requisition || ! $requisition->file || ! in_array($requisition->status, [RequisitionStatus::Uploaded, RequisitionStatus::Processing], true)) {
            return;
        }
        $requisition->update(['status' => RequisitionStatus::Processing]);

        $log = RequisitionProcessingLog::create([
            'requisition_id' => $requisition->id,
            'attempt' => RequisitionProcessingLog::where('requisition_id', $requisition->id)->max('attempt') + 1,
            'status' => 'started',
            'started_at' => now(),
        ]);

        try {
            $file = $requisition->file;
            $type = DocumentType::from(pathinfo($file->path, PATHINFO_EXTENSION));
            $references = new ReferenceResolver;
            $result = $extractor->extract(Storage::disk($file->disk)->path($file->path), $type, $references);

            if (! $result->lines) {
                throw new ExtractionFailed('No item lines were recognised in this document. Add the lines manually or upload a clearer file.');
            }
            $stats = $apply->handle($requisition, $result, $references);

            $log->update([
                'status' => 'succeeded',
                'extractor' => $result->extractor,
                'message' => "Extracted {$stats['lines']} lines ({$stats['matched']} matched to items).",
                'context' => $stats + ['skipped_lines' => $result->skipped],
                'finished_at' => now(),
            ]);
        } catch (Throwable $e) {
            // Only infrastructure errors are worth retrying; a bad file reads the same way every time.
            $retryable = $e instanceof QueryException || $e instanceof \PDOException;
            $log->update([
                'status' => 'failed',
                'message' => $retryable ? 'Temporary system error; retrying automatically.' : self::safeMessage($e),
                'finished_at' => now(),
            ]);
            if ($retryable) {
                throw $e;
            }
            $this->markFailed();
            $this->fail($e);
        }
    }

    /** Called by the queue once retries are exhausted. */
    public function failed(?Throwable $e): void
    {
        $this->markFailed();
    }

    private function markFailed(): void
    {
        Requisition::whereKey($this->requisitionId)
            ->whereIn('status', [RequisitionStatus::Uploaded->value, RequisitionStatus::Processing->value])
            ->update(['status' => RequisitionStatus::Failed->value]);
    }

    /** Messages are shown to users: keep them short and free of paths and SQL. */
    private static function safeMessage(Throwable $e): string
    {
        if (! $e instanceof ExtractionFailed) {
            report($e); // full detail goes to the log, not to the user
        }
        $message = Str::limit(str_replace([storage_path(), base_path()], '', $e->getMessage()), 300);

        return $e instanceof ExtractionFailed || $e instanceof \RuntimeException
            ? $message
            : "Could not read the document: {$message}";
    }
}
