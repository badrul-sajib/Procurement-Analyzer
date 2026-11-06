<?php

namespace App\Actions\Requisitions;

use App\DocumentProcessing\DocumentType;
use App\Enums\RequisitionStatus;
use App\Jobs\ProcessRequisitionDocument;
use App\Models\Requisition;
use App\Models\RequisitionFile;
use App\Support\AuditLogger;
use App\Support\DataVersion;
use App\Support\ReferenceNumber;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** One requisition per file. Duplicate files (same SHA-256) are rejected per file, not per batch. */
class UploadRequisitions
{
    private const DISK = 'local';

    /**
     * @param  array<int, array{file: UploadedFile, type: DocumentType}>  $files
     * @return array{created: Requisition[], duplicates: array<int, array{file: string, requisition: ?Requisition}>}
     */
    public function handle(array $files, array $defaults, int $userId): array
    {
        $created = [];
        $duplicates = [];

        foreach ($files as ['file' => $file, 'type' => $type]) {
            $hash = hash_file('sha256', $file->getRealPath());
            if ($existing = RequisitionFile::where('sha256', $hash)->with('requisition')->first()) {
                $duplicates[] = ['file' => $file->getClientOriginalName(), 'requisition' => $existing->requisition];

                continue;
            }

            // Random name on a private disk: the original name is metadata only, never a path.
            $path = $file->storeAs('requisitions/'.now()->format('Y/m'), Str::uuid().'.'.$type->value, self::DISK);

            try {
                $created[] = DB::transaction(function () use ($file, $type, $hash, $path, $defaults, $userId) {
                    $requisition = Requisition::create([
                        'title' => $defaults['title'] ?? Str::limit(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME), 180, ''),
                        'requisition_date' => $defaults['requisition_date'] ?? null,
                        'department_id' => $defaults['department_id'] ?? null,
                        'status' => RequisitionStatus::Uploaded,
                        'created_by' => $userId,
                    ]);
                    ReferenceNumber::assign($requisition, 'REQ', 'reference_no');
                    $requisition->file()->create([
                        'disk' => self::DISK,
                        'path' => $path,
                        'original_name' => Str::limit($file->getClientOriginalName(), 250, ''),
                        'mime_type' => $type->storedMime(),
                        'size_bytes' => $file->getSize(),
                        'sha256' => $hash,
                        'uploaded_by' => $userId,
                    ]);
                    AuditLogger::log('requisition.uploaded', 'requisitions', $requisition->id,
                        "Uploaded {$file->getClientOriginalName()} as {$requisition->reference_no}");
                    DataVersion::bump();
                    ProcessRequisitionDocument::dispatch($requisition->id)->afterCommit();

                    return $requisition;
                });
            } catch (UniqueConstraintViolationException) {
                // Same file uploaded concurrently by someone else: theirs won.
                Storage::disk(self::DISK)->delete($path);
                $duplicates[] = ['file' => $file->getClientOriginalName(), 'requisition' => RequisitionFile::where('sha256', $hash)->first()?->requisition];
            } catch (\Throwable $e) {
                Storage::disk(self::DISK)->delete($path);
                throw $e;
            }
        }

        return ['created' => $created, 'duplicates' => $duplicates];
    }
}
