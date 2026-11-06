<?php

use App\Enums\RequisitionStatus;
use App\Models\ActivityLog;
use App\Models\Item;
use App\Models\ItemAlias;
use App\Models\Requisition;
use App\Models\Unit;
use App\Models\UnitConversion;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Documents;

beforeEach(function () {
    Storage::fake('local');
    $this->units = Unit::pluck('id', 'code');
    $this->paper = Item::factory()->unit('ream')->create(['name' => 'A4 Paper']);
    $this->pen = Item::factory()->unit('pcs')->create(['name' => 'Ball Pen']);
});

function extracted(string $text): Requisition
{
    test()->post('/api/v1/requisitions/uploads', ['files' => [Documents::text($text)]], ['Accept' => 'application/json'])->assertCreated();

    return Requisition::with('items')->latest('id')->first();
}

function linePayload(Requisition $r): array
{
    return $r->items()->get()->map(fn ($l) => [
        'id' => $l->id, 'item_id' => $l->item_id, 'quantity' => $l->quantity, 'unit_id' => $l->unit_id,
    ])->all();
}

it('saves manual corrections, keeps the extracted original text and remembers aliases on request', function () {
    actingWithPermissions(['requisitions.upload', 'requisitions.update', 'requisitions.view']);
    $requisition = extracted("Requisition date: 01/11/2026\nA4 Paper — 10 Reams\nBallpoint pen - 20 pcs\n");
    $lines = linePayload($requisition);
    $lines[1]['item_id'] = $this->pen->id;
    $lines[1]['quantity'] = '25';
    $lines[1]['save_alias'] = true;
    $lines[1]['original_name'] = 'Tampered name';   // ignored for existing lines

    $this->putJson("/api/v1/requisitions/{$requisition->id}", ['title' => 'November stationery', 'lines' => $lines])
        ->assertOk()
        ->assertJsonPath('data.title', 'November stationery')
        ->assertJsonPath('data.lines.1.original_name', 'Ballpoint pen')
        ->assertJsonPath('data.lines.1.match_type', 'manual')
        ->assertJsonPath('data.lines.1.quantity', '25.000');

    expect(ItemAlias::where('item_id', $this->pen->id)->where('alias', 'Ballpoint pen')->exists())->toBeTrue()
        ->and(ActivityLog::where('action', 'item.matched')->exists())->toBeTrue()
        ->and(ActivityLog::where('action', 'requisition.updated')->sole()->new_values['lines'][0])->toMatchArray(['line' => 2, 'name' => 'Ballpoint pen']);
});

it('blocks verification until every line is matched, positive and convertible', function () {
    actingWithPermissions(['requisitions.upload', 'requisitions.update', 'requisitions.verify']);
    $requisition = extracted("Date: 01/11/2026\nA4 Paper — 2 Boxes\nStapler - 3 pcs\nBall Pen - 0 pcs\n");

    $res = $this->postJson("/api/v1/requisitions/{$requisition->id}/verify")->assertUnprocessable()->assertJsonPath('code', 'verification_failed');
    expect(array_keys($res->json('errors')))->toBe(['lines.0.unit_id', 'lines.1.item_id', 'lines.2.quantity']);
    expect($res->json('errors')['lines.0.unit_id'][0])->toContain('no conversion from Box to Ream');
    expect($requisition->fresh()->status)->toBe(RequisitionStatus::Extracted);
});

it('freezes the conversion factor at verification so base quantities consolidate in the default unit', function () {
    actingWithPermissions(['requisitions.upload', 'requisitions.verify', 'requisitions.view']);
    UnitConversion::create(['item_id' => $this->paper->id, 'from_unit_id' => $this->units['box'], 'to_unit_id' => $this->units['ream'], 'factor' => 5]);
    $requisition = extracted("Date: 01/11/2026\nA4 Paper — 2 Boxes\nBall Pen - 1 dozen\n");

    $this->postJson("/api/v1/requisitions/{$requisition->id}/verify")->assertOk()
        ->assertJsonPath('data.status', 'verified')
        ->assertJsonPath('data.lines.0.base_quantity', '10.000')    // 2 boxes × 5 reams
        ->assertJsonPath('data.lines.1.base_quantity', '12.000')    // global dozen → pcs
        ->assertJsonPath('data.total_requested_quantity', '22.000')
        ->assertJsonPath('data.line_count', 2)
        ->assertJsonPath('data.item_count', 2);

    // Changing the rule later never rewrites a verified requisition.
    UnitConversion::where('item_id', $this->paper->id)->update(['factor' => 10]);
    expect($requisition->items()->first()->base_quantity)->toBe('10.000');
});

it('lets an approver lower but never raise approved quantities', function () {
    actingWithPermissions(['requisitions.upload', 'requisitions.verify', 'requisitions.approve', 'requisitions.view']);
    $requisition = extracted("Date: 01/11/2026\nA4 Paper — 10 Reams\nBall Pen - 20 pcs\n");
    $this->postJson("/api/v1/requisitions/{$requisition->id}/verify")->assertOk();
    [$paperLine, $penLine] = $requisition->items()->get()->all();

    $this->postJson("/api/v1/requisitions/{$requisition->id}/approve", ['lines' => [['id' => $paperLine->id, 'approved_quantity' => 11]]])
        ->assertUnprocessable()->assertJsonPath('code', 'invalid_approved_quantity');

    $this->postJson("/api/v1/requisitions/{$requisition->id}/approve", ['lines' => [['id' => $paperLine->id, 'approved_quantity' => 6]]])
        ->assertOk()
        ->assertJsonPath('data.status', 'approved')
        ->assertJsonPath('data.lines.0.approved_quantity', '6.000')
        ->assertJsonPath('data.lines.1.approved_quantity', '20.000')
        ->assertJsonPath('data.lines.0.fulfilment.pending', '6.000');

    expect(ActivityLog::where('action', 'requisition.approved')->sole()->new_values['reduced_lines'][0])
        ->toEqual(['line' => 1, 'requested' => '10.000', 'approved' => '6.000']); // MySQL JSON reorders keys
    $this->putJson("/api/v1/requisitions/{$requisition->id}", ['title' => 'x'])->assertForbidden(); // no update permission
});

it('locks approved requisitions against edits and re-verification', function () {
    actingWithPermissions(['requisitions.upload', 'requisitions.update', 'requisitions.verify', 'requisitions.approve']);
    $requisition = extracted("Date: 01/11/2026\nA4 Paper — 10 Reams\n");
    $this->postJson("/api/v1/requisitions/{$requisition->id}/verify")->assertOk();
    $this->postJson("/api/v1/requisitions/{$requisition->id}/approve")->assertOk();

    $this->putJson("/api/v1/requisitions/{$requisition->id}", ['title' => 'Changed'])->assertUnprocessable()->assertJsonPath('code', 'invalid_status');
    $this->postJson("/api/v1/requisitions/{$requisition->id}/reopen")->assertUnprocessable()->assertJsonPath('code', 'invalid_status');
});

it('reopens a verified requisition for correction', function () {
    actingWithPermissions(['requisitions.upload', 'requisitions.verify']);
    $requisition = extracted("Date: 01/11/2026\nA4 Paper — 10 Reams\n");
    $this->postJson("/api/v1/requisitions/{$requisition->id}/verify")->assertOk();

    $this->postJson("/api/v1/requisitions/{$requisition->id}/reopen")->assertOk()->assertJsonPath('data.status', 'extracted')
        ->assertJsonPath('data.lines.0.base_quantity', null);
});

it('creates manual requisitions as drafts and verifies them', function () {
    actingWithPermissions(['requisitions.upload', 'requisitions.verify']);
    $id = $this->postJson('/api/v1/requisitions', [
        'title' => 'Phone request', 'requisition_date' => '2026-11-02',
        'lines' => [['original_name' => 'A4 Paper', 'item_id' => $this->paper->id, 'quantity' => '3', 'unit_id' => $this->units['ream']]],
    ])->assertCreated()->assertJsonPath('data.status', 'draft')->assertJsonPath('data.lines.0.match_type', 'exact')->json('data.id');

    $this->postJson("/api/v1/requisitions/{$id}/verify")->assertOk()->assertJsonPath('data.status', 'verified');
});

it('rejects line ids from another requisition', function () {
    actingWithPermissions(['requisitions.upload', 'requisitions.update']);
    $a = extracted("A4 Paper — 1 Ream\n");
    $b = extracted("Ball Pen - 1 pcs\n");
    $this->putJson("/api/v1/requisitions/{$a->id}", ['lines' => [['id' => $b->items->first()->id, 'quantity' => 99]]])
        ->assertUnprocessable()->assertJsonPath('code', 'unknown_line');
    expect($b->items()->first()->quantity)->toBe('1.000');
});

it('reprocesses failed documents and records each attempt', function () {
    actingWithPermissions(['requisitions.upload', 'requisitions.update']);
    $requisition = extracted("Please send the usual.\n");
    expect($requisition->status)->toBe(RequisitionStatus::Failed);

    $this->postJson("/api/v1/requisitions/{$requisition->id}/process")->assertOk();
    expect($requisition->fresh()->status)->toBe(RequisitionStatus::Failed)
        ->and($requisition->processingLogs()->pluck('attempt')->all())->toBe([2, 1]);
});

it('cancels with a reason and refuses to cancel twice', function () {
    actingWithPermissions(['requisitions.upload', 'requisitions.cancel']);
    $requisition = extracted("A4 Paper — 1 Ream\n");
    $this->postJson("/api/v1/requisitions/{$requisition->id}/cancel", ['reason' => ''])->assertUnprocessable();
    $this->postJson("/api/v1/requisitions/{$requisition->id}/cancel", ['reason' => 'Duplicate request'])->assertOk()
        ->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.cancellation_reason', 'Duplicate request');
    $this->postJson("/api/v1/requisitions/{$requisition->id}/cancel", ['reason' => 'again'])->assertUnprocessable();
});

it('downloads the original document as an attachment only', function () {
    actingWithPermissions(['requisitions.upload', 'requisitions.view']);
    $requisition = extracted("A4 Paper — 1 Ream\n");
    $res = $this->get("/api/v1/requisitions/{$requisition->id}/document")->assertOk();
    expect($res->headers->get('content-disposition'))->toContain('attachment')->toContain('requisition.txt')
        ->and($res->headers->get('x-content-type-options'))->toBe('nosniff');
});

it('filters the requisition list by status, search and date basis', function () {
    actingWithPermissions(['requisitions.upload', 'requisitions.view']);
    extracted("Requisition No: ALPHA-1\nDate: 01/10/2026\nA4 Paper — 1 Ream\n");
    extracted("Date: 15/11/2026\nBall Pen - 1 pcs\n");

    $this->getJson('/api/v1/requisitions?search=ALPHA')->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/requisitions?period=custom&date_from=2026-11-01&date_to=2026-11-30')->assertJsonCount(1, 'data');
    $this->getJson('/api/v1/requisitions?period=today&date_basis=uploaded_at')->assertJsonCount(2, 'data');
    $this->getJson('/api/v1/requisitions?status=failed')->assertJsonCount(0, 'data');
});

it('suggests similar catalog items for unmatched lines without auto-linking them', function () {
    actingWithPermissions(['requisitions.upload', 'requisitions.update']);
    $requisition = extracted("A4 Papers — 1 Ream\n");
    $line = $requisition->items->first();
    expect($line->item_id)->toBeNull();

    $this->getJson("/api/v1/requisition-items/{$line->id}/match-suggestions")->assertOk()
        ->assertJsonPath('data.0.item.id', $this->paper->id);
});
