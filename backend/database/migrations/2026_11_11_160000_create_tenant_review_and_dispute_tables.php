<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tenant's review of QA-passed count results
        Schema::create('tenant_result_reviews', function (Blueprint $table): void {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_id')->constrained('stocktake_jobs')->cascadeOnDelete();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('in_progress'); // in_progress | accepted | requested_correction | requested_recount | disputed | completed
            $table->timestamp('review_window_expires_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->text('decision_notes')->nullable();
            $table->unsignedInteger('total_items')->default(0);
            $table->unsignedInteger('items_accepted')->default(0);
            $table->unsignedInteger('items_flagged')->default(0);
            $table->timestamps();
            $table->index(['job_id', 'status']);
        });

        // Per-product review items (tenant can accept or flag individual products)
        Schema::create('tenant_result_review_items', function (Blueprint $table): void {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->foreignId('tenant_result_review_id')->constrained('tenant_result_reviews')->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->decimal('counted_quantity', 18, 6)->nullable();
            $table->decimal('expected_quantity', 18, 6)->nullable();
            $table->decimal('variance', 18, 6)->nullable();
            $table->string('status')->default('pending'); // pending | accepted | flagged | disputed
            $table->text('tenant_comment')->nullable();
            $table->timestamps();
            $table->index(['tenant_result_review_id', 'status']);
        });

        // Reconciliation report comparing counted vs expected (where expected exists)
        Schema::create('reconciliation_reports', function (Blueprint $table): void {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_id')->constrained('stocktake_jobs')->cascadeOnDelete();
            $table->foreignId('tenant_result_review_id')->nullable()->constrained('tenant_result_reviews')->nullOnDelete();
            $table->timestamp('generated_at')->nullable();
            $table->unsignedInteger('total_items')->default(0);
            $table->unsignedInteger('items_matched')->default(0);
            $table->unsignedInteger('items_with_variance')->default(0);
            $table->unsignedInteger('items_unknown')->default(0);
            $table->string('status')->default('draft'); // draft | published | superseded
            $table->timestamps();
            $table->index(['job_id', 'status']);
        });

        // Per-product reconciliation line items
        Schema::create('reconciliation_items', function (Blueprint $table): void {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->foreignId('reconciliation_report_id')->constrained('reconciliation_reports')->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->decimal('counted_quantity', 18, 6)->nullable();
            $table->decimal('expected_quantity', 18, 6)->nullable();
            $table->decimal('variance', 18, 6)->nullable();
            $table->string('type')->default('unknown'); // match | variance | unknown
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['reconciliation_report_id', 'type']);
        });

        // Inventory adjustment approvals (write-off, write-in, reclassify)
        Schema::create('reconciliation_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reconciliation_report_id')->constrained('reconciliation_reports')->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->string('adjustment_type'); // write_off | write_in | reclassify
            $table->decimal('quantity', 18, 6);
            $table->decimal('value', 18, 2)->nullable();
            $table->text('reason')->nullable();
            $table->string('status')->default('pending'); // pending | approved | rejected
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->index(['reconciliation_report_id', 'status']);
        });

        // Formal disputes raised by the tenant
        Schema::create('stocktaking_disputes', function (Blueprint $table): void {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_id')->constrained('stocktake_jobs')->cascadeOnDelete();
            $table->foreignId('tenant_result_review_id')->nullable()->constrained('tenant_result_reviews')->nullOnDelete();
            $table->string('status')->default('open'); // open | in_review | resolved | closed
            $table->foreignId('raised_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('category'); // accuracy | completeness | damage | billing | other
            $table->string('priority')->default('normal'); // low | normal | high | urgent
            $table->text('reason');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->index(['job_id', 'status']);
        });

        // Dispute communication thread
        Schema::create('dispute_messages', function (Blueprint $table): void {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->foreignId('dispute_id')->constrained('stocktaking_disputes')->cascadeOnDelete();
            $table->foreignId('sender_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('sender_role')->default('tenant'); // tenant | platform | system
            $table->text('message');
            $table->timestamps();
            $table->index(['dispute_id', 'created_at']);
        });

        // Evidence attached to a dispute
        Schema::create('dispute_evidence', function (Blueprint $table): void {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->foreignId('dispute_id')->constrained('stocktaking_disputes')->cascadeOnDelete();
            $table->string('kind'); // photo | document | note | count_comparison
            $table->text('description')->nullable();
            $table->string('path')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['dispute_id']);
        });

        // Platform decision on a dispute
        Schema::create('dispute_decisions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->foreignId('dispute_id')->constrained('stocktaking_disputes')->cascadeOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decision'); // uphold | overturn | partial | negotiate
            $table->text('reasoning')->nullable();
            $table->json('conditions')->nullable();
            $table->timestamps();
            $table->index(['dispute_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dispute_decisions');
        Schema::dropIfExists('dispute_evidence');
        Schema::dropIfExists('dispute_messages');
        Schema::dropIfExists('stocktaking_disputes');
        Schema::dropIfExists('reconciliation_adjustments');
        Schema::dropIfExists('reconciliation_items');
        Schema::dropIfExists('reconciliation_reports');
        Schema::dropIfExists('tenant_result_review_items');
        Schema::dropIfExists('tenant_result_reviews');
    }
};
