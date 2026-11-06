<?php

use App\DocumentProcessing\ExtractedLine;
use App\DocumentProcessing\LineParser;

function parser(): LineParser
{
    $units = ['ream', 'reams', 'pcs', 'piece', 'pieces', 'box', 'boxes', 'pkt', 'dozen', 'kg', 'ltr', 'nos'];

    return new LineParser(fn (string $u) => in_array(strtolower(rtrim($u, '.')), $units, true));
}

function lines(string $text): array
{
    return array_map(fn (ExtractedLine $l) => [$l->name, $l->quantity, $l->unit], parser()->parse($text, 'text')->lines);
}

it('reads the spec example formats', function () {
    expect(lines("A4 Paper — 10 Reams\nMarker - 5 Pieces\nBarcode — 100 Pieces"))->toBe([
        ['A4 Paper', '10.000', 'Reams'],
        ['Marker', '5.000', 'Pieces'],
        ['Barcode', '100.000', 'Pieces'],
    ]);
});

it('handles numbering, bullets, colons, quantity-first and missing units', function () {
    expect(lines("1. A4 Paper: 10 reams\n2) Ball Pen 50 pcs\n• Stapler Pin - 3 box\n10 Reams Legal Paper\n5 x Marker\n01 - Toner 12A - 2 pcs\nFile Cover 25"))->toBe([
        ['A4 Paper', '10.000', 'reams'],
        ['Ball Pen', '50.000', 'pcs'],
        ['Stapler Pin', '3.000', 'box'],
        ['Legal Paper', '10.000', 'Reams'],
        ['Marker', '5.000', null],
        ['Toner 12A', '2.000', 'pcs'],
        ['File Cover', '25.000', null],
    ]);
});

it('keeps unknown words after a quantity in the name when they are not units', function () {
    expect(lines('10 Blue Pens'))->toBe([['Blue Pens', '10.000', null]]);
});

it('captures trailing notes and thousands separators', function () {
    $line = parser()->parse('Barcode Sticker — 1,500 pcs (urgent)', 'text')->lines[0];
    expect([$line->name, $line->quantity, $line->unit, $line->notes])->toBe(['Barcode Sticker', '1500.000', 'pcs', 'urgent']);
});

it('reads table-like text from PDFs using header columns', function () {
    $text = "SL   Item Name        Qty    Unit   Remarks\n1    A4 Paper         10     Ream   For accounts\n2    Marker Pen       5      Pcs\n";
    $result = parser()->parse($text, 'pdf_text');
    expect(array_map(fn ($l) => [$l->name, $l->quantity, $l->unit, $l->notes], $result->lines))->toBe([
        ['A4 Paper', '10.000', 'Ream', 'For accounts'],
        ['Marker Pen', '5.000', 'Pcs', null],
    ]);
});

it('reads CSV-like text with or without a header', function () {
    expect(lines("Item,Qty,Unit\nA4 Paper,10,Ream"))->toBe([['A4 Paper', '10.000', 'Ream']])
        ->and(lines('Marker, 5, pcs'))->toBe([['Marker', '5.000', 'pcs']]);
});

it('extracts header fields with day-first dates and Bengali digits', function () {
    $result = parser()->parse("Requisition No: REQ/ACC/2026/118\nDate: 05/11/2026\nDepartment: Accounts\nSubject: Monthly stationery\nA4 Paper — ১০ Reams", 'text');
    expect($result->externalReference)->toBe('REQ/ACC/2026/118')
        ->and($result->requisitionDate)->toBe('2026-11-05')
        ->and($result->department)->toBe('Accounts')
        ->and($result->title)->toBe('Monthly stationery')
        ->and($result->lines[0]->quantity)->toBe('10.000');
});

it('ignores totals, signatures and page footers, and reports unreadable lines as skipped', function () {
    $result = parser()->parse("A4 Paper — 10 Reams\nTotal: 10\nApproved by: Manager\nPage 1 of 2\nPlease deliver soon", 'text');
    expect($result->lines)->toHaveCount(1)
        ->and($result->skipped)->toBe(['Please deliver soon']);
});

it('reads a pdftotext -layout table with wrapped names, a missing serial and misspelled headers', function () {
    $text = <<<'TXT'
                                     Equipment Requisition Form
Hub Name:     Store Need                   Contact Number :                     Date :05/11/2026
  New Hub              Hub Change            Extra                Replacement         Service / Maintainace

   SL          Product Namc            Quentity       Estimated                   Reason
   1             2 Pair Cable          40 Coil
   2           6A SP Circuit           120 Pcs
           Braker(ACI/SuperSter)
              10A SP Circuit           96 Pcs
           Braker(ACI/SuperSter)
   11     1/2" Flaxiable Pipe(RFL)     100 Coil

   16        3.0Rm Cable Black         20 Coil
  26       14mm Cable Clipe           50 Packet
               (VIGO)
Notc: "All products will be reviewed in a well-planned manner by the team and necessary updates or changes will be
     made as needed."
         Signature                                     Signature                                      Approved By
         Operation                             Electrical Department                                   STOR
TXT;
    $result = parser()->parse($text, 'pdf_text');

    expect(array_map(fn ($l) => [$l->name, $l->quantity, $l->unit], $result->lines))->toBe([
        ['2 Pair Cable', '40.000', 'Coil'],
        ['6A SP Circuit Braker(ACI/SuperSter)', '120.000', 'Pcs'],
        ['10A SP Circuit Braker(ACI/SuperSter)', '96.000', 'Pcs'],
        ['1/2" Flaxiable Pipe(RFL)', '100.000', 'Coil'],
        ['3.0Rm Cable Black', '20.000', 'Coil'],
        ['14mm Cable Clipe (VIGO)', '50.000', 'Packet'],
    ])->and($result->requisitionDate)->toBe('2026-11-05')
        ->and($result->skipped)->toContain('New Hub              Hub Change            Extra                Replacement         Service / Maintainace');
});
