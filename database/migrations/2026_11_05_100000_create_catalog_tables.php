<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 150);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 60);
            // Alternative spellings seen in documents, e.g. ["pcs", "pieces", "pc"].
            $table->json('synonyms')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('item_categories', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 150);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('items', function (Blueprint $table) {
            $table->id();
            // Nullable only between insert and auto-assignment (ITM-00001) in the same transaction.
            $table->string('code', 40)->nullable()->unique();
            $table->string('name', 190);
            $table->string('normalized_name', 190)->unique();
            $table->string('sku', 64)->nullable()->unique();
            $table->foreignId('category_id')->nullable()->constrained('item_categories')->restrictOnDelete();
            $table->text('description')->nullable();
            $table->foreignId('default_unit_id')->constrained('units')->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['category_id', 'is_active']);
        });

        Schema::create('item_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained()->cascadeOnDelete();
            $table->string('alias', 190);
            $table->string('normalized_alias', 190)->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // 1 from_unit = factor × to_unit. item_id NULL = global rule; item rule wins over global.
        Schema::create('unit_conversions', function (Blueprint $table) {
            $table->id();
            // RESTRICT: MySQL forbids CASCADE on the base column of a stored generated column (scope_key).
            $table->foreignId('item_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('from_unit_id')->constrained('units')->restrictOnDelete();
            $table->foreignId('to_unit_id')->constrained('units')->restrictOnDelete();
            $table->decimal('factor', 18, 6);
            // NULLs are distinct in unique indexes, so uniqueness of global rules needs a non-null key.
            $table->unsignedBigInteger('scope_key')->storedAs('IFNULL(item_id, 0)');
            $table->timestamps();
            $table->unique(['scope_key', 'from_unit_id', 'to_unit_id']);
        });

        DB::statement('ALTER TABLE unit_conversions ADD CONSTRAINT chk_unit_conversions_factor CHECK (factor > 0)');
        DB::statement('ALTER TABLE unit_conversions ADD CONSTRAINT chk_unit_conversions_units CHECK (from_unit_id <> to_unit_id)');
    }

    public function down(): void
    {
        Schema::dropIfExists('unit_conversions');
        Schema::dropIfExists('item_aliases');
        Schema::dropIfExists('items');
        Schema::dropIfExists('item_categories');
        Schema::dropIfExists('units');
        Schema::dropIfExists('departments');
    }
};
