<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('units_of_measure', function (Blueprint $table): void {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 20);
            $table->string('name');
            $table->string('dimension')->default('count'); // count | weight | volume | length | other
            $table->decimal('factor_to_base', 18, 6)->default(1);
            $table->boolean('is_base')->default(false);
            $table->string('status')->default('active');
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('unit_conversions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_unit_id')->constrained('units_of_measure')->cascadeOnDelete();
            $table->foreignId('to_unit_id')->constrained('units_of_measure')->cascadeOnDelete();
            $table->decimal('factor', 18, 6);
            $table->timestamps();
            $table->unique(['tenant_id', 'from_unit_id', 'to_unit_id']);
        });

        Schema::create('product_categories', function (Blueprint $table): void {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('product_categories')->nullOnDelete();
            $table->string('name');
            $table->string('code')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('brand')->nullable();
            $table->foreignId('category_id')->nullable()->constrained('product_categories')->nullOnDelete();
            $table->text('description')->nullable();
            $table->string('status')->default('active'); // active | inactive | discontinued
            $table->string('tracking_mode')->default('quantity'); // quantity | batch | serial | expiry | combination
            $table->string('supplier_ref')->nullable();
            $table->decimal('cost_price', 18, 2)->nullable();
            $table->string('source_system')->default('manual');
            $table->string('external_ref')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'name']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('product_variants', function (Blueprint $table): void {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->string('sku');
            $table->foreignId('base_unit_id')->constrained('units_of_measure')->restrictOnDelete();
            $table->foreignId('packaging_unit_id')->nullable()->constrained('units_of_measure')->nullOnDelete();
            $table->decimal('packaging_factor', 18, 6)->nullable();
            $table->json('attributes')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
            $table->unique(['tenant_id', 'sku']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('product_barcodes', function (Blueprint $table): void {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->string('barcode');
            $table->string('type')->default('internal'); // ean13 | upc | code128 | internal
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
            $table->unique(['tenant_id', 'barcode']);
        });

        Schema::create('catalogues', function (Blueprint $table): void {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name')->default('Main catalogue');
            $table->string('status')->default('draft'); // draft | active | archived
            $table->foreignId('current_version_id')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('catalogue_versions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('catalogue_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('status')->default('active'); // draft | active | superseded
            $table->unsignedInteger('product_count')->default(0);
            $table->string('source')->default('manual'); // manual | import | brathamatu_pos | integration
            $table->foreignId('import_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['catalogue_id', 'version_number']);
        });

        Schema::create('catalogue_imports', function (Blueprint $table): void {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('catalogue_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('original_name');
            $table->string('path');
            $table->string('disk')->default('local');
            $table->string('extension', 10);
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->string('status')->default('queued'); // queued | processing | ready | failed | committed
            $table->json('headers')->nullable();
            $table->json('mapping')->nullable();
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('error_rows')->default(0);
            $table->unsignedInteger('duplicate_rows')->default(0);
            $table->string('partial_policy')->default('partial'); // partial | all_or_nothing
            $table->foreignId('committed_version_id')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('catalogue_import_rows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('import_id')->constrained('catalogue_imports')->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->json('data');
            $table->string('status')->default('valid'); // valid | error | duplicate
            $table->json('errors')->nullable();
            $table->timestamps();
            $table->index(['import_id', 'status']);
        });

        Schema::create('catalogue_declarations', function (Blueprint $table): void {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('catalogue_id')->constrained()->cascadeOnDelete();
            $table->foreignId('catalogue_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('declared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('declaration_text');
            $table->string('declaration_version')->default('1.0');
            $table->text('exceptions')->nullable();
            $table->json('device')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('accepted_at');
            $table->timestamps();
            $table->index(['tenant_id', 'catalogue_id']);
        });

        Schema::create('catalogue_pricing_policies', function (Blueprint $table): void {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->string('name')->default('Standard catalogue pricing');
            $table->string('pricing_model')->default('banded'); // flat | per_product | banded | minimum | capped | promotional | negotiated
            $table->string('currency', 3)->default('KES');
            $table->string('product_count_basis')->default('active_variants');
            $table->decimal('base_charge', 12, 2)->default(100);
            $table->unsignedInteger('included_products')->default(100);
            $table->unsignedInteger('scale_products')->default(6000);
            $table->decimal('max_charge', 12, 2)->default(2000);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamp('effective_from')->nullable();
            $table->string('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('catalogue_pricing_versions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->foreignId('policy_id')->constrained('catalogue_pricing_policies')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('rules');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('effective_from')->nullable();
            $table->timestamps();
            $table->unique(['policy_id', 'version']);
        });

        Schema::create('catalogue_invoices', function (Blueprint $table): void {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('catalogue_id')->constrained()->cascadeOnDelete();
            $table->foreignId('catalogue_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('pricing_version_id')->nullable()->constrained('catalogue_pricing_versions')->nullOnDelete();
            $table->string('number')->unique();
            $table->unsignedInteger('product_count');
            $table->decimal('subtotal', 12, 2);
            $table->decimal('tax', 12, 2)->default(0);
            $table->decimal('total', 12, 2);
            $table->string('currency', 3)->default('KES');
            $table->string('status')->default('issued'); // draft | issued | paid | waived
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('reference')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalogue_invoices');
        Schema::dropIfExists('catalogue_pricing_versions');
        Schema::dropIfExists('catalogue_pricing_policies');
        Schema::dropIfExists('catalogue_declarations');
        Schema::dropIfExists('catalogue_import_rows');
        Schema::dropIfExists('catalogue_imports');
        Schema::dropIfExists('catalogue_versions');
        Schema::dropIfExists('catalogues');
        Schema::dropIfExists('product_barcodes');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('products');
        Schema::dropIfExists('product_categories');
        Schema::dropIfExists('unit_conversions');
        Schema::dropIfExists('units_of_measure');
    }
};
