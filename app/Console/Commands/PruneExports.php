<?php

namespace App\Console\Commands;

use App\Models\ReportExport;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

#[Signature('exports:prune')]
#[Description('Delete export files past their expiry')]
class PruneExports extends Command
{
    public function handle(): int
    {
        $count = 0;
        ReportExport::where('expires_at', '<', now())->whereNotNull('file_path')->chunkById(200, function ($exports) use (&$count) {
            foreach ($exports as $export) {
                Storage::disk('local')->delete($export->file_path);
                $export->forceFill(['file_path' => null])->save();
                $count++;
            }
        });
        $this->info("Pruned {$count} export file(s).");

        return self::SUCCESS;
    }
}
