<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('phone', 30)->nullable()->unique();
            $table->string('email')->nullable();
            $table->date('birthday')->nullable();
            $table->unsignedInteger('total_orders')->default(0);
            $table->decimal('total_spending', 15, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('dining_tables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->string('table_number', 30);
            $table->string('name')->nullable();
            $table->unsignedSmallInteger('capacity')->default(4);
            $table->string('area')->nullable();
            $table->string('status', 20)->default('AVAILABLE')->index();
            $table->unsignedBigInteger('current_order_id')->nullable()->index();
            $table->timestamps();

            $table->unique(['branch_id', 'table_number']);
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->string('order_number', 40);
            $table->string('type', 20)->default('DINE_IN');
            $table->string('status', 30)->default('DRAFT')->index();
            $table->string('payment_status', 20)->default('UNPAID')->index();
            $table->string('kitchen_status', 20)->default('NEW')->index();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('cashier_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('dining_table_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('cashier_shift_id')->nullable()->constrained()->nullOnDelete();
            $table->string('currency', 3)->default('USD');
            $table->decimal('exchange_rate', 15, 6)->default(1);
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('discount_total', 15, 2)->default(0);
            $table->decimal('tax_rate_snapshot', 8, 4)->default(0);
            $table->decimal('tax_total', 15, 2)->default(0);
            $table->decimal('service_charge_rate_snapshot', 8, 4)->default(0);
            $table->decimal('service_charge_total', 15, 2)->default(0);
            $table->decimal('grand_total', 15, 2)->default(0);
            $table->decimal('paid_total', 15, 2)->default(0);
            $table->decimal('change_total', 15, 2)->default(0);
            $table->text('notes')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->timestamp('placed_at')->nullable();
            $table->timestamp('sent_to_kitchen_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'order_number']);
            $table->index(['branch_id', 'created_at']);
            $table->index(['branch_id', 'status', 'payment_status']);
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('menu_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('menu_item_variant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name_snapshot');
            $table->string('variant_name_snapshot')->nullable();
            $table->decimal('unit_price', 15, 2);
            $table->decimal('quantity', 10, 3)->default(1);
            $table->decimal('line_subtotal', 15, 2)->default(0);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('tax_amount', 15, 2)->default(0);
            $table->decimal('line_total', 15, 2)->default(0);
            $table->string('kitchen_status', 20)->default('NEW')->index();
            $table->text('special_instruction')->nullable();
            $table->timestamps();

            $table->index('order_id');
        });

        Schema::create('order_item_modifiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('modifier_option_id')->nullable()->constrained()->nullOnDelete();
            $table->string('group_name_snapshot');
            $table->string('option_name_snapshot');
            $table->decimal('unit_price', 15, 2)->default(0);
            $table->decimal('quantity', 10, 3)->default(1);
            $table->decimal('total', 15, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('order_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'created_at']);
        });

        Schema::create('discounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('code', 50)->nullable()->unique();
            $table->string('name');
            $table->string('type', 20);
            $table->decimal('value', 15, 2);
            $table->decimal('min_order_total', 15, 2)->default(0);
            $table->decimal('max_discount_amount', 15, 2)->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->unsignedInteger('per_customer_limit')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('order_discounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('discount_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20);
            $table->decimal('value', 15, 2)->default(0);
            $table->decimal('amount', 15, 2)->default(0);
            $table->string('reason')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('order_id')->constrained()->restrictOnDelete();
            $table->foreignId('cashier_shift_id')->nullable()->constrained()->nullOnDelete();
            $table->string('method', 20);
            $table->string('status', 20)->default('PENDING')->index();
            $table->decimal('amount', 15, 2);
            $table->string('currency', 3)->default('USD');
            $table->decimal('exchange_rate', 15, 6)->default(1);
            $table->decimal('cash_received', 15, 2)->nullable();
            $table->decimal('change_given', 15, 2)->nullable();
            $table->string('provider', 50)->nullable();
            $table->string('provider_reference')->nullable()->index();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'status']);
            $table->index('cashier_shift_id');
        });

        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 50)->nullable();
            $table->string('provider_transaction_id')->nullable()->unique();
            $table->string('type', 20);
            $table->string('status', 20);
            $table->decimal('amount', 15, 2);
            $table->string('currency', 3)->default('USD');
            $table->string('idempotency_key')->nullable()->unique();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->restrictOnDelete();
            $table->string('receipt_number', 50)->unique();
            $table->string('branch_name');
            $table->string('branch_address')->nullable();
            $table->string('cashier_name')->nullable();
            $table->string('customer_name')->nullable();
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('discount', 15, 2)->default(0);
            $table->decimal('tax', 15, 2)->default(0);
            $table->decimal('service_charge', 15, 2)->default(0);
            $table->decimal('total_paid', 15, 2)->default(0);
            $table->decimal('change', 15, 2)->default(0);
            $table->json('payment_summary')->nullable();
            $table->json('items_snapshot')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->unsignedInteger('printed_count')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receipts');
        Schema::dropIfExists('payment_transactions');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('order_discounts');
        Schema::dropIfExists('discounts');
        Schema::dropIfExists('order_status_histories');
        Schema::dropIfExists('order_item_modifiers');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('dining_tables');
        Schema::dropIfExists('customers');
    }
};
