<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendors', function (Blueprint $table) {
            $table->id();
            // Nullable only between insert and auto-assignment (VEN-0001) in the same transaction.
            $table->string('code', 30)->nullable()->unique();
            $table->string('name', 190)->index();
            $table->string('contact_person', 120)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('email', 190)->nullable();
            $table->text('address')->nullable();
            $table->text('company_info')->nullable();
            $table->string('tax_number', 60)->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('po_number', 30)->nullable()->unique();
            $table->foreignId('vendor_id')->constrained()->restrictOnDelete();
            $table->date('order_date');
            // Actual purchase (bill) date, set by "Mark Purchased". Purchase reports filter on this.
            $table->date('purchase_date')->nullable();
            $table->string('status', 30);
            $table->decimal('additional_charges', 15, 2)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            foreach (['submitted', 'approved', 'purchased', 'completed', 'cancelled'] as $event) {
                $table->foreignId("{$event}_by")->nullable()->constrained('users')->restrictOnDelete();
                $table->timestamp("{$event}_at")->nullable();
            }
            $table->string('cancellation_reason', 500)->nullable();
            $table->timestamps();
            $table->index(['vendor_id', 'status']);
            $table->index(['status', 'purchase_date']);
            $table->index('order_date');
        });

        DB::statement('ALTER TABLE purchase_orders ADD CONSTRAINT chk_purchase_orders_charges CHECK (additional_charges >= 0)');

        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id')->index()->constrained()->restrictOnDelete();
            // Quantities are in the item's default unit.
            $table->decimal('ordered_quantity', 15, 3);
            $table->decimal('purchased_quantity', 15, 3)->nullable();
            $table->decimal('unit_price', 15, 4);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('line_total', 15, 2)->storedAs(
                'ROUND(COALESCE(purchased_quantity, ordered_quantity) * unit_price, 2) - discount_amount + tax_amount'
            );
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['purchase_order_id', 'item_id']);
        });

        foreach ([
            'chk_poi_ordered' => 'ordered_quantity > 0',
            'chk_poi_purchased' => 'purchased_quantity IS NULL OR (purchased_quantity >= 0 AND purchased_quantity <= ordered_quantity)',
            'chk_poi_amounts' => 'unit_price >= 0 AND discount_amount >= 0 AND tax_amount >= 0',
        ] as $name => $expr) {
            DB::statement("ALTER TABLE purchase_order_items ADD CONSTRAINT {$name} CHECK ({$expr})");
        }

        // Which requisition lines each PO line fulfils, and how much of each.
        Schema::create('purchase_order_item_requisitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requisition_item_id')->index()->constrained()->restrictOnDelete();
            $table->decimal('ordered_quantity', 15, 3);
            $table->decimal('purchased_quantity', 15, 3)->nullable();
            $table->timestamps();
            $table->unique(['purchase_order_item_id', 'requisition_item_id'], 'poir_line_requisition_unique');
        });

        DB::statement('ALTER TABLE purchase_order_item_requisitions ADD CONSTRAINT chk_poir_qty CHECK (ordered_quantity > 0 AND (purchased_quantity IS NULL OR (purchased_quantity >= 0 AND purchased_quantity <= ordered_quantity)))');
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_item_requisitions');
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('vendors');
    }
};
