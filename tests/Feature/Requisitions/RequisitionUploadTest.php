<?php

use App\Enums\RequisitionStatus;
use App\Models\ActivityLog;
use App\Models\Department;
use App\Models\Item;
use App\Models\Requisition;
use App\Models\RequisitionProcessingLog;
use App\Support\Settings;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Documents;

beforeEach(function () {
    Storage::fake('local');
    $this->paper = Item::factory()->unit('ream')->create(['name' => 'A4 Paper']);
    $this->marker = Item::factory()->unit('pcs')->create(['name' => 'Marker']);
    $this->marker->aliases()->create(['alias' => 'Marker Pen']);
    Department::factory()->create(['code' => 'ACC', 'name' => 'Accounts']);
});

it('uploads a text requisition, stores it privately and extracts matched lines in the background', function () {
    $user = actingWithPermissions(['requisitions.upload', 'requisitions.view']);
    $file = Documents::text("Requisition No: ACC/118\nDate: 05/11/2026\nDepartment: Accounts\n\nA4 Paper — 10 Reams\nMarker Pen - 5 pcs\nBarcode — 100 Pieces\n");

    $res = $this->post('/api/v1/requisitions/uploads', ['files' => [$file]], ['Accept' => 'application/json'])->assertCreated();

    $requisition = Requisition::with('items.unit', 'file', 'department')->sole();
    expect($res->json('data.created.0.reference_no'))->toBe(sprintf('REQ-%s-%06d', now('Asia/Dhaka')->year, $requisition->id))
        ->and($requisition->status)->toBe(RequisitionStatus::Extracted)
        ->and($requisition->external_reference)->toBe('ACC/118')
        ->and($requisition->requisition_date->toDateString())->toBe('2026-11-05')
        ->and($requisition->department->code)->toBe('ACC')
        ->and($requisition->created_by)->toBe($user->id);

    // Stored under a random name on the private disk, never the client's file name.
    Storage::disk('local')->assertExists($requisition->file->path);
    expect(basename($requisition->file->path))->toMatch('/^[0-9a-f-]{36}\.txt$/')->and($requisition->file->original_name)->toBe('requisition.txt');

    $lines = $requisition->items->map(fn ($l) => [$l->original_name, $l->quantity, $l->item_id, $l->match_type?->value, $l->unit?->code]);
    expect($lines->all())->toBe([
        ['A4 Paper', '10.000', $this->paper->id, 'exact', 'ream'],
        ['Marker Pen', '5.000', $this->marker->id, 'alias', 'pcs'],
        ['Barcode', '100.000', null, null, 'pcs'],  // never auto-matched without a catalog entry
    ]);
    expect(RequisitionProcessingLog::sole())->status->toBe('succeeded')->extractor->toBe('text')
        ->and(ActivityLog::where('action', 'requisition.uploaded')->exists())->toBeTrue();
});

it('creates one requisition per file and reports duplicates without failing the batch', function () {
    actingWithPermissions(['requisitions.upload']);
    $content = "A4 Paper — 10 Reams\n";
    $this->post('/api/v1/requisitions/uploads', ['files' => [Documents::text($content, 'a.txt'), Documents::text("Marker - 2 pcs\n", 'b.txt')]], ['Accept' => 'application/json'])
        ->assertCreated()->assertJsonCount(2, 'data.created');

    $first = Requisition::orderBy('id')->first();
    $this->post('/api/v1/requisitions/uploads', ['files' => [Documents::text($content, 'renamed-copy.txt')]], ['Accept' => 'application/json'])
        ->assertOk()
        ->assertJsonCount(0, 'data.created')
        ->assertJsonPath('data.duplicates.0.file', 'renamed-copy.txt')
        ->assertJsonPath('data.duplicates.0.requisition.reference_no', $first->reference_no);
    expect(Requisition::count())->toBe(2);
});

it('rejects unsupported files and files whose content does not match the extension', function (string $name, string $content) {
    actingWithPermissions(['requisitions.upload']);
    $this->post('/api/v1/requisitions/uploads', ['files' => [Documents::fromContent($content, $name)]], ['Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonValidationErrors('files.0');
    expect(Requisition::count())->toBe(0);
})->with([
    'php script' => ['shell.php', '<?php system($_GET["c"]);'],
    'php renamed to txt' => ['notes.txt', "<?php\nsystem('id');\n"],
    'html renamed to pdf' => ['invoice.pdf', '<html><script>alert(1)</script></html>'],
    'zip renamed to xls' => ['data.xls', "PK\x03\x04".str_repeat("\0", 30)],
]);

it('enforces the configured upload size limit', function () {
    actingWithPermissions(['requisitions.upload', 'settings.manage']);
    Settings::put(['max_upload_mb' => 1], null);
    $big = Documents::text(str_repeat("A4 Paper — 10 Reams\n", 60000)); // ~1.2 MB
    $this->post('/api/v1/requisitions/uploads', ['files' => [$big]], ['Accept' => 'application/json'])
        ->assertUnprocessable()->assertJsonValidationErrors('files.0');
});

it('extracts Excel requisitions by header columns and reads cached formula values', function () {
    actingWithPermissions(['requisitions.upload']);
    $file = Documents::xlsx([
        ['Requisition No:', 'XL-77'],
        ['Department:', 'Accounts'],
        [],
        ['SL', 'Item Description', 'Qty', 'UoM', 'Remarks'],
        [1, 'A4 Paper', 10, 'Ream', 'Urgent'],
        [2, 'Marker', '=2*3', 'Pcs', null],
    ]);

    $this->post('/api/v1/requisitions/uploads', ['files' => [$file]], ['Accept' => 'application/json'])->assertCreated();

    $requisition = Requisition::with('items')->sole();
    expect($requisition->status)->toBe(RequisitionStatus::Extracted)
        ->and($requisition->external_reference)->toBe('XL-77')
        ->and($requisition->items->map(fn ($l) => [$l->original_name, $l->quantity, $l->notes])->all())->toBe([
            ['A4 Paper', '10.000', 'Urgent'],
            ['Marker', '6.000', null],
        ]);
});

it('extracts text-layer PDFs', function () {
    actingWithPermissions(['requisitions.upload']);
    $file = Documents::pdf('<p>Requisition No: PDF-9</p><p>A4 Paper - 12 Reams</p><p>Marker - 4 pcs</p>');

    $this->post('/api/v1/requisitions/uploads', ['files' => [$file]], ['Accept' => 'application/json'])->assertCreated();

    $requisition = Requisition::with('items')->sole();
    expect($requisition->status)->toBe(RequisitionStatus::Extracted)
        ->and($requisition->items->pluck('quantity', 'original_name')->all())->toBe(['A4 Paper' => '12.000', 'Marker' => '4.000']);
});

it('marks documents with no recognisable lines as failed with a readable log', function () {
    actingWithPermissions(['requisitions.upload']);
    $this->post('/api/v1/requisitions/uploads', ['files' => [Documents::text("Dear sir,\nPlease arrange the usual items.\n")]], ['Accept' => 'application/json'])->assertCreated();

    $requisition = Requisition::sole();
    expect($requisition->status)->toBe(RequisitionStatus::Failed)
        ->and(RequisitionProcessingLog::sole())->status->toBe('failed')
        ->message->toContain('No item lines were recognised');
});

it('fails scanned PDFs clearly when OCR is unavailable', function () {
    actingWithPermissions(['requisitions.upload']);
    $png = base64_encode(file_get_contents(__DIR__.'/../../Support/pixel.png'));
    $this->post('/api/v1/requisitions/uploads', ['files' => [Documents::pdf("<img src=\"data:image/png;base64,{$png}\" width=\"200\">")]], ['Accept' => 'application/json'])->assertCreated();

    expect(Requisition::sole()->status)->toBe(RequisitionStatus::Failed)
        ->and(RequisitionProcessingLog::sole()->message)->toContain('OCR is not available');
});

it('requires upload permission', function () {
    actingWithPermissions(['requisitions.view']);
    $this->post('/api/v1/requisitions/uploads', ['files' => [Documents::text('A4 Paper - 1 ream')]], ['Accept' => 'application/json'])->assertForbidden();
});
