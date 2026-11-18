<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('count_sessions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_id')->constrained('stocktake_jobs')->cascadeOnDelete();
            $table->foreignId('assignment_id')->nullable()->constrained('job_assignments')->cascadeOnDelete();
            $table->foreignId('catalogue_version_id')->nullable()->constrained('catalogue_versions')->nullOnDelete();
            $table->string('mode')->default('blind'); // blind | guided
            $table->string('status')->default('active'); // active | submitted | cancelled
            $table->json('scope')->nullable();          // frozen scope snapshot at session start
            $table->json('device')->nullable();
            $table->string('idempotency_key')->nullable()->unique();
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['job_id', 'status']);
            $table->index(['assignment_id']);
        });

        Schema::create('count_session_locations', function (Blueprint $table): void {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->foreignId('session_id')->constrained('count_sessions')->cascadeOnDelete();
            $table->foreignId('stock_location_id')->nullable()->constrained('stock_locations')->nullOnDelete();
            $table->string('label')->nullable();
            $table->string('zone')->nullable();
            $table->string('bin')->nullable();
            $table->timestamps();
        });

        Schema::create('count_entries', function (Blueprint $table): void {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('session_id')->constrained('count_sessions')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->foreignId('stock_location_id')->nullable()->constrained('stock_locations')->nullOnDelete();
            $table->decimal('raw_quantity', 18, 6)->default(0);
            $table->foreignId('raw_unit_id')->nullable()->constrained('units_of_measure')->nullOnDelete();
            $table->decimal('normalized_quantity', 18, 6)->default(0);
            $table->json('observations')->nullable();   // condition flags: good/damaged/expired/quarantined/missing_label/inaccessible
            $table->text('notes')->nullable();
            $table->json('evidence')->nullable();        // references to count_evidence rows
            $table->foreignId('worker_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('device_session_id')->nullable();
            $table->string('idempotency_key')->unique();
            $table->string('status')->default('draft');  // draft | submitted
            $table->unsignedInteger('revision_count')->default(0);
            $table->timestamp('captured_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->index(['session_id', 'status']);
        });

        Schema::create('count_entry_revisions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->foreignId('entry_id')->constrained('count_entries')->cascadeOnDelete();
            $table->decimal('raw_quantity', 18, 6);
            $table->foreignId('raw_unit_id')->nullable()->constrained('units_of_measure')->nullOnDelete();
            $table->decimal('normalized_quantity', 18, 6);
            $table->json('observations')->nullable();
            $table->text('notes')->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['entry_id', 'created_at']);
        });

        Schema::create('count_exceptions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('session_id')->constrained('count_sessions')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->string('barcode')->nullable();
            $table->string('type'); // mismatched_barcode | wrong_label | unexpected_variant | packaging | other
            $table->text('description')->nullable();
            $table->string('location')->nullable();
            $table->foreignId('observed_unit_id')->nullable()->constrained('units_of_measure')->nullOnDelete();
            $table->string('status')->default('open'); // open | resolved
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['session_id', 'status']);
        });

        Schema::create('unknown_product_reports', function (Blueprint $table): void {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('session_id')->constrained('count_sessions')->cascadeOnDelete();
            $table->string('barcode')->nullable();
            $table->string('name_guess')->nullable();
            $table->text('description')->nullable();
            $table->string('location')->nullable();
            $table->foreignId('observed_unit_id')->nullable()->constrained('units_of_measure')->nullOnDelete();
            $table->json('evidence')->nullable();
            $table->string('status')->default('open'); // open | resolved | added_to_catalogue
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['session_id', 'status']);
        });

        Schema::create('count_evidence', function (Blueprint $table): void {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('evidenceable_type');
            $table->unsignedBigInteger('evidenceable_id');
            $table->string('kind'); // photo | barcode_scan | signature | document
            $table->string('path');
            $table->string('disk')->default('local');
            $table->string('caption')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['evidenceable_type', 'evidenceable_id']);
        });

        Schema::create('count_submission_locks', function (Blueprint $table): void {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->foreignId('session_id')->constrained('count_sessions')->cascadeOnDelete();
            $table->timestamp('locked_at')->useCurrent();
            $table->foreignId('locked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->foreignId('released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique('session_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('count_submission_locks');
        Schema::dropIfExists('count_evidence');
        Schema::dropIfExists('unknown_product_reports');
        Schema::dropIfExists('count_exceptions');
        Schema::dropIfExists('count_entry_revisions');
        Schema::dropIfExists('count_entries');
        Schema::dropIfExists('count_session_locations');
        Schema::dropIfExists('count_sessions');
    }
};
