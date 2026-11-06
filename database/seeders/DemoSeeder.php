<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Database\Seeder;

/** Local development sample master data only. Never runs in production (see DatabaseSeeder). */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['ADM' => 'Administration', 'ACC' => 'Accounts', 'HR' => 'Human Resources', 'BR-DHK' => 'Dhaka Branch', 'BR-CTG' => 'Chattogram Branch'] as $code => $name) {
            Department::firstOrCreate(['code' => $code], ['name' => $name]);
        }

        $categories = collect(['STAT' => 'Stationery', 'PRN' => 'Printing & Labels', 'CLN' => 'Cleaning Supplies', 'IT' => 'IT Accessories'])
            ->map(fn ($name, $code) => ItemCategory::firstOrCreate(['code' => $code], ['name' => $name]));
        $units = Unit::pluck('id', 'code');

        $items = [
            ['ITM-0001', 'A4 Paper', 'STAT', 'ream', ['A4 Size Paper', 'Paper A4', 'Offset Paper A4']],
            ['ITM-0002', 'Marker', 'STAT', 'pcs', ['Marker Pen', 'Permanent Marker']],
            ['ITM-0003', 'Barcode', 'PRN', 'pcs', ['Barcode Sticker', 'Barcode Label']],
            ['ITM-0004', 'Ball Pen', 'STAT', 'pcs', ['Pen', 'Ballpoint Pen']],
            ['ITM-0005', 'Stapler Pin', 'STAT', 'box', ['Staple Pin', 'Stapler Pins']],
            ['ITM-0006', 'Toner Cartridge', 'IT', 'pcs', ['Printer Toner', 'Toner']],
            ['ITM-0007', 'Floor Cleaner', 'CLN', 'ltr', ['Harpic', 'Lizol']],
        ];
        foreach ($items as [$code, $name, $category, $unit, $aliases]) {
            $item = Item::firstOrCreate(['code' => $code], [
                'name' => $name, 'category_id' => $categories[$category]->id, 'default_unit_id' => $units[$unit],
            ]);
            foreach ($aliases as $alias) {
                $item->aliases()->firstOrCreate(['alias' => $alias]);
            }
        }

        foreach ([['VEN-001', 'Rahman Stationery House', 'Abdur Rahman'], ['VEN-002', 'Dhaka Office Supplies Ltd.', 'Nusrat Jahan'], ['VEN-003', 'Chittagong Print & Pack', 'Kamal Hossain']] as [$code, $name, $contact]) {
            Vendor::firstOrCreate(['code' => $code], ['name' => $name, 'contact_person' => $contact, 'phone' => '+8801700000000']);
        }

        $roles = ['officer@example.com' => 'Requisition Officer', 'purchase@example.com' => 'Purchase Manager',
            'store@example.com' => 'Inventory Manager', 'receiving@example.com' => 'Delivery / Receiving Officer',
            'finance@example.com' => 'Finance Manager', 'viewer@example.com' => 'Viewer'];
        foreach ($roles as $email => $role) {
            User::firstOrCreate(['email' => $email], ['name' => $role, 'password' => 'password'])->syncRoles([$role]);
        }
    }
}
