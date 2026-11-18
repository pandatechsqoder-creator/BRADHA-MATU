<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pos_terminals')) {
            Schema::create('pos_terminals', function (Blueprint $table): void {
                $table->id();
                $table->ulid('uuid')->unique();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
                $table->string('name');
                $table->string('device_id')->nullable();
                $table->string('status')->default('active');
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->index(['tenant_id', 'status']);
            });
        }

        if (! Schema::hasTable('pos_shifts')) {
            Schema::create('pos_shifts', function (Blueprint $table): void {
                $table->id();
                $table->ulid('uuid')->unique();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('terminal_id')->constrained('pos_terminals')->cascadeOnDelete();
                $table->foreignId('opened_by')->constrained('users')->cascadeOnDelete();
                $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->decimal('opening_float', 14, 2)->default(0);
                $table->decimal('expected_cash', 14, 2)->default(0);
                $table->decimal('actual_cash', 14, 2)->nullable();
                $table->decimal('cash_variance', 14, 2)->nullable();
                $table->string('status')->default('open');
                $table->timestamp('opened_at');
                $table->timestamp('closed_at')->nullable();
                $table->timestamp('reconciled_at')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->index(['tenant_id', 'status']);
                $table->index(['terminal_id', 'status']);
            });
        }

        if (! Schema::hasTable('pos_customers')) {
            Schema::create('pos_customers', function (Blueprint $table): void {
                $table->id();
                $table->ulid('uuid')->unique();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->string('name');
                $table->string('phone')->nullable();
                $table->string('email')->nullable();
                $table->string('loyalty_id')->nullable();
                $table->text('notes')->nullable();
                $table->boolean('active')->default(true);
                $table->timestamps();
                $table->index(['tenant_id', 'active']);
                $table->index(['phone']);
            });
        }

        if (! Schema::hasTable('pos_sales')) {
            Schema::create('pos_sales', function (Blueprint $table): void {
                $table->id();
                $table->ulid('uuid')->unique();
                $table->string('reference')->unique();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
                $table->foreignId('terminal_id')->constrained('pos_terminals')->cascadeOnDelete();
                $table->foreignId('shift_id')->constrained('pos_shifts')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('customer_id')->nullable()->constrained('pos_customers')->nullOnDelete();
                $table->string('status')->default('completed');
                $table->decimal('subtotal', 14, 2)->default(0);
                $table->decimal('tax_amount', 14, 2)->default(0);
                $table->decimal('discount_amount', 14, 2)->default(0);
                $table->decimal('total_amount', 14, 2)->default(0);
                $table->string('currency', 3)->default('KES');
                $table->string('payment_method')->nullable();
                $table->decimal('cash_tendered', 14, 2)->nullable();
                $table->decimal('cash_change', 14, 2)->nullable();
                $table->string('receipt_number')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->index(['tenant_id', 'status']);
                $table->index(['shift_id']);
                $table->index(['reference']);
                $table->index(['created_at']);
            });
        }

        if (! Schema::hasTable('pos_sale_items')) {
            Schema::create('pos_sale_items', function (Blueprint $table): void {
                $table->id();
                $table->ulid('uuid')->unique();
                $table->foreignId('sale_id')->constrained('pos_sales')->cascadeOnDelete();
                $table->foreignId('variant_id')->constrained('product_variants')->cascadeOnDelete();
                $table->foreignId('batch_id')->nullable()->constrained('inventory_batches')->nullOnDelete();
                $table->string('product_name');
                $table->string('sku')->nullable();
                $table->string('barcode')->nullable();
                $table->decimal('quantity', 14, 3);
                $table->string('unit')->default('pcs');
                $table->decimal('unit_price', 14, 2);
                $table->decimal('line_total', 14, 2);
                $table->decimal('tax_amount', 14, 2)->default(0);
                $table->decimal('discount_amount', 14, 2)->default(0);
                $table->timestamps();
                $table->index(['sale_id']);
                $table->index(['variant_id']);
            });
        }

        if (! Schema::hasTable('pos_receipts')) {
            Schema::create('pos_receipts', function (Blueprint $table): void {
                $table->id();
                $table->ulid('uuid')->unique();
                $table->string('receipt_number')->unique();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('sale_id')->nullable()->constrained('pos_sales')->nullOnDelete();
                $table->foreignId('shift_id')->constrained('pos_shifts')->cascadeOnDelete();
                $table->string('type')->default('sale');
                $table->decimal('amount', 14, 2)->default(0);
                $table->string('currency', 3)->default('KES');
                $table->text('content')->nullable();
                $table->boolean('printed')->default(false);
                $table->timestamp('printed_at')->nullable();
                $table->timestamps();
                $table->index(['tenant_id']);
                $table->index(['sale_id']);
                $table->index(['receipt_number']);
            });
        }

        if (! Schema::hasTable('pos_suppliers')) {
            Schema::create('pos_suppliers', function (Blueprint $table): void {
                $table->id();
                $table->ulid('uuid')->unique();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->string('name');
                $table->string('contact_person')->nullable();
                $table->string('phone')->nullable();
                $table->string('email')->nullable();
                $table->string('address')->nullable();
                $table->string('tax_pin')->nullable();
                $table->text('notes')->nullable();
                $table->boolean('active')->default(true);
                $table->timestamps();
                $table->index(['tenant_id', 'active']);
            });
        }

        if (! Schema::hasTable('pos_purchases')) {
            Schema::create('pos_purchases', function (Blueprint $table): void {
                $table->id();
                $table->ulid('uuid')->unique();
                $table->string('reference')->unique();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
                $table->foreignId('supplier_id')->constrained('pos_suppliers')->cascadeOnDelete();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('status')->default('received');
                $table->decimal('subtotal', 14, 2)->default(0);
                $table->decimal('tax_amount', 14, 2)->default(0);
                $table->decimal('total_amount', 14, 2)->default(0);
                $table->string('currency', 3)->default('KES');
                $table->text('notes')->nullable();
                $table->timestamps();
                $table->index(['tenant_id', 'status']);
                $table->index(['supplier_id']);
                $table->index(['reference']);
            });
        }

        if (! Schema::hasTable('pos_purchase_items')) {
            Schema::create('pos_purchase_items', function (Blueprint $table): void {
                $table->id();
                $table->ulid('uuid')->unique();
                $table->foreignId('purchase_id')->constrained('pos_purchases')->cascadeOnDelete();
                $table->foreignId('variant_id')->constrained('product_variants')->cascadeOnDelete();
                $table->foreignId('batch_id')->nullable()->constrained('inventory_batches')->nullOnDelete();
                $table->string('product_name');
                $table->string('sku')->nullable();
                $table->decimal('quantity', 14, 3);
                $table->string('unit')->default('pcs');
                $table->decimal('unit_cost', 14, 2);
                $table->decimal('line_total', 14, 2);
                $table->timestamps();
                $table->index(['purchase_id']);
                $table->index(['variant_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_purchase_items');
        Schema::dropIfExists('pos_purchases');
        Schema::dropIfExists('pos_suppliers');
        Schema::dropIfExists('pos_customers');
        Schema::dropIfExists('pos_receipts');
        Schema::dropIfExists('pos_sale_items');
        Schema::dropIfExists('pos_sales');
        Schema::dropIfExists('pos_shifts');
        Schema::dropIfExists('pos_terminals');
    }
};
