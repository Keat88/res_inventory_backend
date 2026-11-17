<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingredients', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('sku', 50)->nullable()->unique();
            $table->text('description')->nullable();
            $table->string('unit', 10);
            $table->decimal('cost_per_unit', 15, 4)->default(0);
            $table->string('status', 20)->default('ACTIVE')->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('branch_inventories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 15, 3)->default(0);
            $table->decimal('min_stock', 15, 3)->default(0);
            $table->decimal('max_stock', 15, 3)->nullable();
            $table->timestamp('last_movement_at')->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'ingredient_id']);
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('ingredient_id')->constrained()->restrictOnDelete();
            $table->string('type', 20)->index();
            $table->decimal('quantity', 15, 3);
            $table->decimal('unit_cost', 15, 4)->nullable();
            $table->decimal('balance_after', 15, 3)->nullable();
            $table->string('reference_type', 50)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->foreignId('reversal_of_id')->nullable()->constrained('stock_movements')->nullOnDelete();
            $table->string('idempotency_key')->nullable()->unique();
            $table->string('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['branch_id', 'ingredient_id', 'occurred_at'], 'sm_branch_ing_occurred_idx');
            $table->index(['reference_type', 'reference_id']);
            $table->unique(
                ['branch_id', 'reference_type', 'reference_id', 'ingredient_id', 'type'],
                'sm_reference_unique'
            );
        });

        Schema::create('recipes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('menu_item_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->decimal('yield_quantity', 10, 3)->default(1);
            $table->boolean('is_active')->default(true)->index();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['menu_item_id', 'version']);
        });

        Schema::create('recipe_ingredients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 15, 3);
            $table->string('unit', 10)->nullable();
            $table->decimal('wastage_percent', 6, 2)->default(0);
            $table->timestamps();

            $table->unique(['recipe_id', 'ingredient_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipe_ingredients');
        Schema::dropIfExists('recipes');
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('branch_inventories');
        Schema::dropIfExists('ingredients');
    }
};
