<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A supervisor's QA review of a submitted count session
        Schema::create('qa_reviews', function (Blueprint $table): void {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_id')->constrained('stocktake_jobs')->cascadeOnDelete();
            $table->foreignId('session_id')->nullable()->constrained('count_sessions')->cascadeOnDelete();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('in_progress'); // in_progress | passed | failed | recount_required
            $table->string('decision')->nullable();            // pass | fail | recount
            $table->text('summary')->nullable();
            $table->unsignedInteger('findings_count')->default(0);
            $table->unsignedInteger('entries_reviewed')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['job_id', 'status']);
        });

        // Individual discrepancies found during QA review
        Schema::create('qa_findings', function (Blueprint $table): void {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('qa_review_id')->constrained('qa_reviews')->cascadeOnDelete();
            $table->foreignId('entry_id')->nullable()->constrained('count_entries')->nullOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->string('type'); // missing | excess | damaged | mismatched | wrong_unit | other
            $table->decimal('counted_quantity', 18, 6)->nullable();
            $table->decimal('verified_quantity', 18, 6)->nullable();
            $table->decimal('variance', 18, 6)->nullable();
            $table->string('severity')->default('minor');  // minor | moderate | major | critical
            $table->string('classification')->nullable(); // worker_error | system_error | expected_variance | unknown
            $table->text('notes')->nullable();
            $table->string('resolution')->default('none');  // none | accepted | recount | disputed
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['qa_review_id']);
        });

        // Independent sampling / verification task assigned to a second worker
        Schema::create('verification_tasks', function (Blueprint $table): void {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_id')->constrained('stocktake_jobs')->cascadeOnDelete();
            $table->foreignId('session_id')->nullable()->constrained('count_sessions')->cascadeOnDelete();
            $table->foreignId('qa_review_id')->nullable()->constrained('qa_reviews')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('pending'); // pending | in_progress | completed | cancelled
            $table->unsignedInteger('sample_size')->default(0);
            $table->unsignedInteger('items_verified')->default(0);
            $table->unsignedInteger('items_matched')->default(0);
            $table->unsignedInteger('items_mismatched')->default(0);
            $table->text('summary')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['job_id', 'status']);
        });

        // Individual items selected for independent verification
        Schema::create('verification_samples', function (Blueprint $table): void {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->foreignId('verification_task_id')->constrained('verification_tasks')->cascadeOnDelete();
            $table->foreignId('entry_id')->nullable()->constrained('count_entries')->nullOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->decimal('original_quantity', 18, 6)->nullable();
            $table->decimal('verified_quantity', 18, 6)->nullable();
            $table->decimal('variance', 18, 6)->nullable();
            $table->string('result')->default('pending'); // pending | match | mismatch | not_counted
            $table->text('notes')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->index(['verification_task_id', 'result']);
        });

        // Formal recount request with cost attribution
        Schema::create('recount_requests', function (Blueprint $table): void {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_id')->constrained('stocktake_jobs')->cascadeOnDelete();
            $table->foreignId('qa_review_id')->nullable()->constrained('qa_reviews')->nullOnDelete();
            $table->text('reason');
            $table->string('cost_responsibility')->default('undetermined'); // platform | worker_error | tenant_request | split | undetermined
            $table->string('status')->default('pending'); // pending | approved | rejected | completed | cancelled
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->timestamps();
            $table->index(['job_id', 'status']);
        });

        // Aggregated discrepancies between original and verified/recounted counts
        Schema::create('discrepancies', function (Blueprint $table): void {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_id')->constrained('stocktake_jobs')->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->foreignId('qa_finding_id')->nullable()->constrained('qa_findings')->nullOnDelete();
            $table->decimal('original_quantity', 18, 6)->nullable();
            $table->decimal('verified_quantity', 18, 6)->nullable();
            $table->decimal('variance', 18, 6)->nullable();
            $table->string('type')->default('unknown'); // worker_error | system_error | expected_variance | unknown
            $table->string('status')->default('open');   // open | investigated | resolved
            $table->text('resolution')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['job_id', 'status']);
            $table->index(['type']);
        });

        // Evidence attached to a discrepancy
        Schema::create('discrepancy_evidence', function (Blueprint $table): void {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->foreignId('discrepancy_id')->constrained('discrepancies')->cascadeOnDelete();
            $table->string('kind'); // photo | note | document | supervisor_verification
            $table->text('description')->nullable();
            $table->string('path')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['discrepancy_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discrepancy_evidence');
        Schema::dropIfExists('discrepancies');
        Schema::dropIfExists('recount_requests');
        Schema::dropIfExists('verification_samples');
        Schema::dropIfExists('verification_tasks');
        Schema::dropIfExists('qa_findings');
        Schema::dropIfExists('qa_reviews');
    }
};
