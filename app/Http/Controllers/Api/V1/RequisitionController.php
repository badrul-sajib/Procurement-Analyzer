<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Requisitions\ApproveRequisition;
use App\Actions\Requisitions\CancelRequisition;
use App\Actions\Requisitions\ReopenRequisition;
use App\Actions\Requisitions\ReprocessRequisition;
use App\Actions\Requisitions\SaveRequisition;
use App\Actions\Requisitions\UploadRequisitions;
use App\Actions\Requisitions\VerifyRequisition;
use App\DocumentProcessing\DocumentType;
use App\DocumentProcessing\ItemMatcher;
use App\Enums\RequisitionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Requisitions\SaveRequisitionRequest;
use App\Http\Resources\ItemResource;
use App\Http\Resources\RequisitionResource;
use App\Models\Requisition;
use App\Models\RequisitionItem;
use App\Queries\RequisitionFulfilment;
use App\Support\DateRange;
use App\Support\ListQuery;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RequisitionController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate(DateRange::rules() + [
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string'],
            'department_id' => ['nullable', 'integer'],
            'date_basis' => ['nullable', 'in:requisition_date,uploaded_at'],
        ]);

        $query = Requisition::query()
            ->with(['department', 'creator'])
            ->withCount(['items as line_count', 'items as item_count' => fn ($q) => $q->select(DB::raw('COUNT(DISTINCT item_id)'))])
            ->withSum('items as total_requested_quantity', 'base_quantity')
            ->when($request->query('search'), fn ($q, $s) => $q->where(fn ($q) => $q
                ->where('reference_no', 'like', ListQuery::like($s))->orWhere('title', 'like', ListQuery::like($s))
                ->orWhere('external_reference', 'like', ListQuery::like($s))))
            ->when($request->query('status'), fn ($q, $s) => $q->whereIn('status', array_intersect(explode(',', $s), array_column(RequisitionStatus::cases(), 'value'))))
            ->when($request->filled('department_id'), fn ($q) => $q->where('department_id', $request->integer('department_id')));

        if ($range = DateRange::fromRequest($request)) {
            $request->query('date_basis') === 'uploaded_at'
                ? $query->whereBetween('created_at', $range->utcBounds())
                : $query->whereBetween('requisition_date', $range->dates());
        }

        return RequisitionResource::collection(ListQuery::paginate($query, $request, [
            'requisition_date' => 'requisition_date', 'uploaded_at' => 'created_at', 'reference_no' => 'id',
            'title' => 'title', 'status' => 'status', 'id' => 'id',
        ], '-uploaded_at'));
    }

    public function show(Requisition $requisition): RequisitionResource
    {
        $requisition->load(['items.item.defaultUnit', 'items.unit', 'items.department', 'file', 'department', 'creator', 'verifier', 'approver', 'canceller', 'processingLogs'])
            ->loadCount(['items as line_count', 'items as item_count' => fn ($q) => $q->select(DB::raw('COUNT(DISTINCT item_id)'))])
            ->loadSum('items as total_requested_quantity', 'base_quantity');

        if (in_array($requisition->status, [RequisitionStatus::Approved], true)) {
            $fulfilment = RequisitionFulfilment::forRequisition($requisition->id);
            $requisition->items->each(fn ($line) => $line->fulfilment = $fulfilment[$line->id] ?? null);
        }

        if ($requisition->external_reference) {
            $requisition->possible_duplicates = Requisition::query()
                ->whereKeyNot($requisition->id)
                ->where('external_reference', $requisition->external_reference)
                ->where('status', '!=', RequisitionStatus::Cancelled)
                ->limit(5)->get(['id', 'reference_no', 'title', 'status'])
                ->map(fn ($r) => ['id' => $r->id, 'reference_no' => $r->reference_no, 'title' => $r->title, 'status' => $r->status->value]);
        }

        return new RequisitionResource($requisition);
    }

    public function store(SaveRequisitionRequest $request, SaveRequisition $save): JsonResponse
    {
        $requisition = $save->handle($request->validated(), null, $request->user()->id);

        return $this->show($requisition)->response()->setStatusCode(201);
    }

    public function update(SaveRequisitionRequest $request, Requisition $requisition, SaveRequisition $save): RequisitionResource
    {
        return $this->show($save->handle($request->validated(), $requisition, $request->user()->id));
    }

    public function upload(Request $request, UploadRequisitions $upload): JsonResponse
    {
        $maxKb = Settings::get('max_upload_mb') * 1024;
        $request->validate([
            'files' => ['required', 'array', 'min:1', 'max:20'],
            'files.*' => ['required', 'file', "max:{$maxKb}", 'extensions:pdf,xlsx,xls,txt'],
            'title' => ['nullable', 'string', 'max:190'],
            'requisition_date' => ['nullable', 'date_format:Y-m-d'],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')],
        ], ['files.*.extensions' => 'Only PDF, Excel (.xlsx, .xls) and text (.txt) files are accepted.']);

        // The extension says what the file claims to be; the content must agree.
        $files = [];
        foreach ($request->file('files') as $i => $file) {
            $type = DocumentType::detect($file);
            if (! $type) {
                throw ValidationException::withMessages([
                    "files.{$i}" => "{$file->getClientOriginalName()}: the file content does not match its extension.",
                ]);
            }
            $files[] = ['file' => $file, 'type' => $type];
        }

        $result = $upload->handle($files, $request->only(['title', 'requisition_date', 'department_id']), $request->user()->id);

        return response()->json(['data' => [
            'created' => RequisitionResource::collection(collect($result['created'])),
            'duplicates' => array_map(fn ($d) => [
                'file' => $d['file'],
                'requisition' => $d['requisition'] ? ['id' => $d['requisition']->id, 'reference_no' => $d['requisition']->reference_no, 'status' => $d['requisition']->status->value] : null,
            ], $result['duplicates']),
        ]], $result['created'] ? 201 : 200);
    }

    public function process(Requisition $requisition, ReprocessRequisition $reprocess): RequisitionResource
    {
        return $this->show($reprocess->handle($requisition));
    }

    public function verify(Request $request, Requisition $requisition, VerifyRequisition $verify): RequisitionResource
    {
        return $this->show($verify->handle($requisition, $request->user()->id));
    }

    public function reopen(Request $request, Requisition $requisition, ReopenRequisition $reopen): RequisitionResource
    {
        return $this->show($reopen->handle($requisition, $request->user()->id));
    }

    public function approve(Request $request, Requisition $requisition, ApproveRequisition $approve): RequisitionResource
    {
        $data = $request->validate([
            'lines' => ['sometimes', 'array'],
            'lines.*.id' => ['required', 'integer', 'distinct'],
            'lines.*.approved_quantity' => ['required', 'decimal:0,3', 'min:0'],
        ]);
        $approved = collect($data['lines'] ?? [])->pluck('approved_quantity', 'id')->map(fn ($q) => (string) $q)->all();

        return $this->show($approve->handle($requisition, $approved, $request->user()->id));
    }

    public function cancel(Request $request, Requisition $requisition, CancelRequisition $cancel): RequisitionResource
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        return $this->show($cancel->handle($requisition, $data['reason'], $request->user()->id));
    }

    public function document(Requisition $requisition): StreamedResponse
    {
        $file = $requisition->file;
        abort_unless($file && Storage::disk($file->disk)->exists($file->path), 404, 'Original document not found.');

        // Always an attachment with the sniffed type: an uploaded file is never rendered inline.
        return Storage::disk($file->disk)->download($file->path, $file->original_name, [
            'Content-Type' => $file->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'",
        ]);
    }

    public function matchSuggestions(RequisitionItem $requisitionItem, ItemMatcher $matcher): JsonResponse
    {
        $suggestions = $matcher->suggestions($requisitionItem->original_name);
        $suggestions->each(fn ($s) => $s['item']->load('defaultUnit'));

        return response()->json(['data' => $suggestions->map(fn ($s) => [
            'score' => $s['score'],
            'item' => (new ItemResource($s['item']))->resolve(),
        ])]);
    }
}
