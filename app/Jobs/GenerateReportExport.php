<?php

namespace App\Jobs;

use App\Enums\ExportStatus;
use App\Exports\ReportSheet;
use App\Models\ReportExport;
use App\Notifications\ExportReady;
use App\Reports\ReportRunner;
use App\Reports\Reports;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Maatwebsite\Excel\DefaultValueBinder;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use Throwable;

class GenerateReportExport implements ShouldQueue
{
    use Queueable;

    public const PDF_ROW_LIMIT = 5000;

    public int $tries = 2;

    public int $timeout = 600;

    public function __construct(public int $exportId)
    {
        $this->onQueue('exports');
    }

    public function handle(): void
    {
        $export = ReportExport::findOrFail($this->exportId);
        $export->update(['status' => ExportStatus::Processing]);
        $runner = ReportRunner::make(Reports::find($export->report), $export->filters ?? []);
        $path = "exports/{$export->user_id}/".Str::uuid().".{$export->format}";

        if ($export->format === 'pdf') {
            $count = (clone $runner->rows())->count();
            $rows = $runner->rows()->limit(self::PDF_ROW_LIMIT)->get()->map(fn ($r) => $runner->shape($r));
            $pdf = Pdf::loadView('exports.report', [
                'meta' => $runner->describe(), 'rows' => $rows, 'totals' => $runner->totals(),
                'truncated' => $count > self::PDF_ROW_LIMIT, 'total_rows' => $count, 'generated_at' => now(config('app.business_timezone')),
            ])->setPaper('a4', 'landscape');
            Storage::disk('local')->put($path, $pdf->output());
        } else {
            $sheet = new ReportSheet($runner, numericCells: $export->format === 'xlsx');
            try {
                Excel::store($sheet, $path, 'local', $export->format === 'csv' ? ExcelFormat::CSV : ExcelFormat::XLSX);
            } finally {
                // Laravel Excel installs the custom binder globally and never resets it; a long-running
                // worker would otherwise apply it to every later spreadsheet.
                Cell::setValueBinder(new DefaultValueBinder);
            }
            $count = $sheet->rows;
        }

        $export->forceFill([
            'status' => ExportStatus::Completed, 'file_path' => $path, 'row_count' => $count,
            'completed_at' => now(), 'expires_at' => now()->addDay(),
        ])->save();
        $export->user->notify(new ExportReady($export));
    }

    public function failed(?Throwable $e): void
    {
        ReportExport::whereKey($this->exportId)->update(['status' => ExportStatus::Failed->value, 'error' => 'The export could not be generated. Try a smaller date range.']);
    }
}
