<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('image_path')->nullable();
            $table->integer('sort_order')->default(0);
            $table->string('status', 20)->default('ACTIVE')->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('menu_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('image_path')->nullable();
            $table->decimal('base_price', 15, 2)->default(0);
            $table->string('item_type', 20)->default('FOOD')->index();
            $table->unsignedInteger('prep_time_minutes')->default(0);
            $table->boolean('is_available')->default(true)->index();
            $table->string('status', 20)->default('ACTIVE')->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('menu_item_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('menu_item_id')->constrained()->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('name');
            $table->decimal('price', 15, 2)->default(0);
            $table->boolean('is_default')->default(false);
            $table->integer('sort_order')->default(0);
            $table->string('status', 20)->default('ACTIVE');
            $table->timestamps();

            $table->unique(['menu_item_id', 'code']);
        });

        Schema::create('modifier_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('selection_type', 20)->default('SINGLE');
            $table->unsignedSmallInteger('min_selection')->default(0);
            $table->unsignedSmallInteger('max_selection')->default(1);
            $table->boolean('is_required')->default(false);
            $table->integer('sort_order')->default(0);
            $table->string('status', 20)->default('ACTIVE');
            $table->timestamps();
        });

        Schema::create('modifier_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('modifier_group_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->decimal('price', 15, 2)->default(0);
            $table->boolean('is_default')->default(false);
            $table->integer('sort_order')->default(0);
            $table->string('status', 20)->default('ACTIVE');
            $table->timestamps();
        });

        Schema::create('menu_item_modifier_group', function (Blueprint $table) {
            $table->id();
            $table->foreignId('menu_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('modifier_group_id')->constrained()->cascadeOnDelete();
            $table->integer('sort_order')->default(0);

            $table->unique(['menu_item_id', 'modifier_group_id']);
        });

        Schema::create('branch_menu_item_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('menu_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('menu_item_variant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->decimal('price', 15, 2)->nullable();
            $table->boolean('is_available')->default(true);
            $table->timestamps();

            $table->index(['branch_id', 'menu_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_menu_item_prices');
        Schema::dropIfExists('menu_item_modifier_group');
        Schema::dropIfExists('modifier_options');
        Schema::dropIfExists('modifier_groups');
        Schema::dropIfExists('menu_item_variants');
        Schema::dropIfExists('menu_items');
        Schema::dropIfExists('categories');
    }
};
