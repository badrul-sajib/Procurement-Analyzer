<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('requisitions', function (Blueprint $table) {
            $table->id();
            // Set right after insert from the id (REQ-YYYY-000123); nullable only for that instant.
            $table->string('reference_no', 30)->nullable()->unique();
            $table->string('external_reference', 100)->nullable()->index();
            $table->string('title', 190);
            $table->date('requisition_date')->nullable();
            $table->foreignId('department_id')->nullable()->index()->constrained()->restrictOnDelete();
            $table->string('status', 30);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason', 500)->nullable();
            $table->timestamps();
            $table->index(['status', 'requisition_date']);
            $table->index('requisition_date');
            $table->index('created_at');
        });

        Schema::create('requisition_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requisition_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('disk', 30);
            $table->string('path', 255);
            $table->string('original_name', 255);
            $table->string('mime_type', 100);
            $table->unsignedInteger('size_bytes');
            // Same file can never be imported twice.
            $table->char('sha256', 64)->unique();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('requisition_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requisition_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->foreignId('item_id')->nullable()->index()->constrained()->restrictOnDelete();
            $table->string('original_name', 255);
            $table->text('description')->nullable();
            $table->decimal('quantity', 15, 3)->nullable();
            $table->foreignId('unit_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('original_unit', 50)->nullable();
            // Frozen at verification so later conversion edits never rewrite history.
            $table->decimal('conversion_factor', 18, 6)->nullable();
            $table->decimal('base_quantity', 15, 3)->nullable()->storedAs('ROUND(quantity * conversion_factor, 3)');
            $table->decimal('approved_quantity', 15, 3)->nullable();
            $table->date('requested_date')->nullable();
            $table->foreignId('department_id')->nullable()->index()->constrained()->restrictOnDelete();
            $table->text('notes')->nullable();
            $table->string('match_type', 20)->nullable();
            $table->timestamps();
            $table->index(['requisition_id', 'line_no']);
        });

        DB::statement('ALTER TABLE requisition_items ADD CONSTRAINT chk_requisition_items_quantity CHECK (quantity IS NULL OR quantity >= 0)');
        DB::statement('ALTER TABLE requisition_items ADD CONSTRAINT chk_requisition_items_approved CHECK (approved_quantity IS NULL OR approved_quantity >= 0)');

        Schema::create('requisition_processing_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requisition_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('attempt');
            $table->string('status', 20);
            $table->string('extractor', 30)->nullable();
            $table->text('message')->nullable();
            $table->json('context')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->index(['requisition_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('requisition_processing_logs');
        Schema::dropIfExists('requisition_items');
        Schema::dropIfExists('requisition_files');
        Schema::dropIfExists('requisitions');
    }
};
