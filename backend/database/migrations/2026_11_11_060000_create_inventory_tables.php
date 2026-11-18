<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_batches', function (Blueprint $table): void {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->string('batch_number');
            $table->date('manufactured_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->string('status')->default('active'); // active | expired | recalled
            $table->timestamps();
            $table->unique(['tenant_id', 'variant_id', 'batch_number']);
            $table->index(['tenant_id', 'expires_on']);
        });

        Schema::create('inventory_serials', function (Blueprint $table): void {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained('inventory_batches')->nullOnDelete();
            $table->string('serial_number');
            $table->string('status')->default('in_stock'); // in_stock | sold | returned | scrapped | missing
            $table->foreignId('stock_location_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'serial_number']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('inventory_balances', function (Blueprint $table): void {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('stock_location_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained('inventory_batches')->nullOnDelete();
            $table->string('batch_key', 80)->default('');
            $table->decimal('quantity', 18, 3)->default(0);
            $table->decimal('reserved_quantity', 18, 3)->default(0);
            $table->timestamp('last_movement_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'stock_location_id', 'variant_id', 'batch_key'], 'inventory_balance_unique');
            $table->index(['tenant_id', 'variant_id']);
        });

        Schema::create('inventory_movements', function (Blueprint $table): void {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('stock_location_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type'); // opening | purchase | sale | return | transfer_in | transfer_out | adjustment | production | damage | expiry | consumption | other
            $table->string('source')->default('manual'); // manual | import | brathamatu_pos | external
            $table->string('external_id')->nullable();
            $table->string('reference')->nullable();
            $table->timestamp('occurred_at');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('cutoff_id')->nullable();
            $table->string('status')->default('posted'); // posted | voided
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->unsignedInteger('line_count')->default(0);
            $table->timestamps();
            $table->index(['tenant_id', 'occurred_at']);
            $table->index(['tenant_id', 'stock_location_id']);
            $table->index(['tenant_id', 'source', 'external_id']);
        });

        Schema::create('inventory_movement_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('movement_id')->constrained('inventory_movements')->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained('inventory_batches')->nullOnDelete();
            $table->foreignId('serial_id')->nullable()->constrained('inventory_serials')->nullOnDelete();
            $table->string('batch_key', 80)->default('');
            $table->string('serial_number')->nullable();
            $table->decimal('quantity', 18, 3);
            $table->decimal('unit_cost', 18, 2)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'variant_id']);
            $table->index('movement_id');
        });

        Schema::create('inventory_cutoffs', function (Blueprint $table): void {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('stock_location_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('cut_off_at');
            $table->string('status')->default('open'); // open | closed
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('inventory_cutoff_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cutoff_id')->constrained('inventory_cutoffs')->cascadeOnDelete();
            $table->foreignId('movement_id')->constrained('inventory_movements')->cascadeOnDelete();
            $table->boolean('included')->default(true);
            $table->string('reason')->nullable();
            $table->timestamps();
            $table->unique(['cutoff_id', 'movement_id']);
        });

        Schema::create('inventory_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('stock_location_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('catalogue_version_id')->nullable()->constrained('catalogue_versions')->nullOnDelete();
            $table->foreignId('cutoff_id')->nullable()->constrained('inventory_cutoffs')->nullOnDelete();
            $table->string('job_id')->nullable();
            $table->string('reference')->nullable();
            $table->string('status')->default('frozen'); // frozen | superseded
            $table->timestamp('snapshot_at');
            $table->unsignedInteger('item_count')->default(0);
            $table->decimal('total_quantity', 18, 3)->default(0);
            $table->string('checksum', 64);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'stock_location_id']);
        });

        Schema::create('inventory_snapshot_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('snapshot_id')->constrained('inventory_snapshots')->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->string('sku');
            $table->string('product_name');
            $table->string('unit', 20)->nullable();
            $table->foreignId('batch_id')->nullable()->constrained('inventory_batches')->nullOnDelete();
            $table->string('batch_number')->nullable();
            $table->date('expires_on')->nullable();
            $table->decimal('expected_quantity', 18, 3)->default(0);
            $table->decimal('unit_cost', 18, 2)->nullable();
            $table->decimal('value', 18, 2)->nullable();
            $table->json('attributes')->nullable();
            $table->timestamps();
            $table->index(['snapshot_id', 'sku']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_snapshot_items');
        Schema::dropIfExists('inventory_snapshots');
        Schema::dropIfExists('inventory_cutoff_movements');
        Schema::dropIfExists('inventory_cutoffs');
        Schema::dropIfExists('inventory_movement_lines');
        Schema::dropIfExists('inventory_movements');
        Schema::dropIfExists('inventory_balances');
        Schema::dropIfExists('inventory_serials');
        Schema::dropIfExists('inventory_batches');
    }
};
