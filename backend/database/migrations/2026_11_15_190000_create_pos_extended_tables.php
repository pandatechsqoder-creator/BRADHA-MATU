<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pos_returns')) {
            Schema::create('pos_returns', function (Blueprint $table): void {
                $table->id();
                $table->ulid('uuid')->unique();
                $table->string('reference')->unique();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
                $table->foreignId('sale_id')->constrained('pos_sales')->cascadeOnDelete();
                $table->foreignId('shift_id')->nullable()->constrained('pos_shifts')->nullOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('status')->default('completed');
                $table->decimal('subtotal', 14, 2)->default(0);
                $table->decimal('tax_amount', 14, 2)->default(0);
                $table->decimal('total_amount', 14, 2)->default(0);
                $table->string('currency', 3)->default('KES');
                $table->string('refund_method')->default('cash');
                $table->string('reason')->nullable();
                $table->text('notes')->nullable();
                $table->timestamp('returned_at');
                $table->timestamps();
                $table->index(['tenant_id', 'status']);
                $table->index(['sale_id']);
            });
        }

        if (! Schema::hasTable('pos_return_items')) {
            Schema::create('pos_return_items', function (Blueprint $table): void {
                $table->id();
                $table->ulid('uuid')->unique();
                $table->foreignId('return_id')->constrained('pos_returns')->cascadeOnDelete();
                $table->foreignId('sale_item_id')->nullable()->constrained('pos_sale_items')->nullOnDelete();
                $table->foreignId('variant_id')->constrained('product_variants')->cascadeOnDelete();
                $table->foreignId('batch_id')->nullable()->constrained('inventory_batches')->nullOnDelete();
                $table->string('product_name');
                $table->string('sku')->nullable();
                $table->decimal('quantity', 14, 3);
                $table->string('unit')->default('pcs');
                $table->decimal('unit_price', 14, 2);
                $table->decimal('line_total', 14, 2);
                $table->timestamps();
                $table->index(['return_id']);
                $table->index(['variant_id']);
            });
        }

        if (! Schema::hasTable('pos_transfers')) {
            Schema::create('pos_transfers', function (Blueprint $table): void {
                $table->id();
                $table->ulid('uuid')->unique();
                $table->string('reference')->unique();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('from_branch_id')->nullable()->constrained('branches')->nullOnDelete();
                $table->foreignId('to_branch_id')->nullable()->constrained('branches')->nullOnDelete();
                $table->foreignId('from_location_id')->nullable()->constrained('stock_locations')->nullOnDelete();
                $table->foreignId('to_location_id')->nullable()->constrained('stock_locations')->nullOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('status')->default('received');
                $table->text('notes')->nullable();
                $table->timestamp('shipped_at')->nullable();
                $table->timestamp('received_at')->nullable();
                $table->timestamps();
                $table->index(['tenant_id', 'status']);
            });
        }

        if (! Schema::hasTable('pos_transfer_items')) {
            Schema::create('pos_transfer_items', function (Blueprint $table): void {
                $table->id();
                $table->ulid('uuid')->unique();
                $table->foreignId('transfer_id')->constrained('pos_transfers')->cascadeOnDelete();
                $table->foreignId('variant_id')->constrained('product_variants')->cascadeOnDelete();
                $table->foreignId('batch_id')->nullable()->constrained('inventory_batches')->nullOnDelete();
                $table->string('product_name');
                $table->string('sku')->nullable();
                $table->decimal('quantity', 14, 3);
                $table->string('unit')->default('pcs');
                $table->timestamps();
                $table->index(['transfer_id']);
                $table->index(['variant_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_transfer_items');
        Schema::dropIfExists('pos_transfers');
        Schema::dropIfExists('pos_return_items');
        Schema::dropIfExists('pos_returns');
    }
};
