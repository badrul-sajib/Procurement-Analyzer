<?php

namespace Database\Seeders;

use App\Models\Unit;
use App\Models\UnitConversion;
use Illuminate\Database\Seeder;

class UnitSeeder extends Seeder
{
    private const UNITS = [
        'pcs' => ['Piece', ['pc', 'piece', 'pieces', 'nos', 'no', 'each', 'ea', 'unit', 'units']],
        'ream' => ['Ream', ['reams', 'rm']],
        'box' => ['Box', ['boxes', 'bx']],
        'pkt' => ['Packet', ['packet', 'packets', 'pack', 'packs']],
        'dozen' => ['Dozen', ['dz', 'doz', 'dozens']],
        'kg' => ['Kilogram', ['kgs', 'kilogram', 'kilograms']],
        'g' => ['Gram', ['gm', 'gram', 'grams']],
        'ltr' => ['Litre', ['l', 'litre', 'litres', 'liter', 'liters']],
        'm' => ['Metre', ['mtr', 'metre', 'metres', 'meter', 'meters']],
        'set' => ['Set', ['sets']],
        'roll' => ['Roll', ['rolls']],
        'bottle' => ['Bottle', ['bottles', 'btl']],
        'carton' => ['Carton', ['ctn', 'cartons']],
        'pair' => ['Pair', ['pairs']],
        'bag' => ['Bag', ['bags']],
    ];

    private const GLOBAL_CONVERSIONS = [
        ['dozen', 'pcs', '12'],
        ['pair', 'pcs', '2'],
        ['kg', 'g', '1000'],
        ['g', 'kg', '0.001'],
    ];

    public function run(): void
    {
        foreach (self::UNITS as $code => [$name, $synonyms]) {
            Unit::firstOrCreate(['code' => $code], ['name' => $name, 'synonyms' => $synonyms]);
        }

        $ids = Unit::pluck('id', 'code');
        foreach (self::GLOBAL_CONVERSIONS as [$from, $to, $factor]) {
            UnitConversion::firstOrCreate(
                ['item_id' => null, 'from_unit_id' => $ids[$from], 'to_unit_id' => $ids[$to]],
                ['factor' => $factor],
            );
        }
    }
}
