<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ExportStatus;
use App\Http\Controllers\Controller;
use App\Jobs\GenerateReportExport;
use App\Models\ReportExport;
use App\Reports\Report;
use App\Reports\ReportRunner;
use App\Reports\Reports;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportController extends Controller
{
    /** Reports this user may open. */
    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => collect(Reports::all())
            ->filter(fn (Report $r) => $request->user()->can($r->permission()))
            ->map(fn (Report $r, $key) => ['key' => $key, 'title' => $r->title(), 'date_basis' => $r->dateBasis([]), 'financial' => $r->permission() === 'reports.financial'])
            ->values()]);
    }

    public function show(Request $request, string $report): JsonResponse
    {
        $definition = $this->authorizedReport($request, $report);

        return response()->json(ReportRunner::make($definition, $request->query())->page($request));
    }

    public function export(Request $request, string $report): JsonResponse
    {
        $definition = $this->authorizedReport($request, $report);
        $data = $request->validate(['format' => ['required', 'in:xlsx,csv,pdf'], 'filters' => ['nullable', 'array']]);
        $runner = ReportRunner::make($definition, $data['filters'] ?? []); // validate filters now, not in the worker

        $export = ReportExport::create([
            'user_id' => $request->user()->id, 'report' => $report, 'format' => $data['format'],
            'filters' => $runner->filters, 'status' => ExportStatus::Queued,
        ]);
        AuditLogger::log('report.exported', 'reports', $export->id, "Exported {$definition->title()} as {$data['format']}", null, ['filters' => $runner->filters]);
        GenerateReportExport::dispatch($export->id);

        return response()->json(['data' => $this->exportPayload($export->fresh())], 202);
    }

    public function exportStatus(ReportExport $export): JsonResponse
    {
        Gate::authorize('view', $export);

        return response()->json(['data' => $this->exportPayload($export)]);
    }

    public function download(ReportExport $export): StreamedResponse
    {
        Gate::authorize('view', $export);
        abort_unless($export->status === ExportStatus::Completed && $export->expires_at?->isFuture() && Storage::disk('local')->exists($export->file_path), 404, 'Export is not available.');

        return Storage::disk('local')->download($export->file_path, "{$export->report}-{$export->created_at->format('Y-m-d-His')}.{$export->format}", ['X-Content-Type-Options' => 'nosniff']);
    }

    private function authorizedReport(Request $request, string $key): Report
    {
        $report = Reports::find($key) ?? abort(404, 'Unknown report.');
        abort_unless($request->user()->can($report->permission()), 403);

        return $report;
    }

    private function exportPayload(ReportExport $export): array
    {
        return [
            'id' => $export->id, 'report' => $export->report, 'format' => $export->format, 'status' => $export->status->value,
            'row_count' => $export->row_count, 'error' => $export->error,
            'completed_at' => $export->completed_at?->toIso8601String(), 'expires_at' => $export->expires_at?->toIso8601String(),
        ];
    }
}
