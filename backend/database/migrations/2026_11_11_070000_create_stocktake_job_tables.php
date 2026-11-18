<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stocktake_policies', function (Blueprint $table): void {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->string('currency', 3)->default('KES');
            $table->decimal('base_charge', 14, 2)->default(2500);
            $table->decimal('per_sku_charge', 14, 2)->default(5);
            $table->decimal('per_location_charge', 14, 2)->default(500);
            $table->decimal('per_branch_charge', 14, 2)->default(1000);
            $table->json('priority_multipliers')->nullable();
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->string('deposit_type')->default('percentage'); // none | fixed | percentage
            $table->decimal('deposit_value', 14, 2)->default(30);
            $table->decimal('deposit_minimum', 14, 2)->default(1000);
            $table->decimal('deposit_maximum', 14, 2)->default(100000);
            $table->unsignedInteger('deposit_due_days')->default(3);
            $table->unsignedInteger('balance_due_days')->default(7);
            $table->decimal('cancellation_fee_percent', 5, 2)->default(10);
            $table->json('deposit_policy_overrides')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('stocktake_jobs', function (Blueprint $table): void {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('reference')->unique();
            $table->string('job_type')->default('full'); // full | cycle | spot | recurring | on_demand
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status')->default('draft');
            $table->string('priority')->default('normal'); // low | normal | high | critical
            $table->string('business_criticality')->nullable();
            $table->string('count_method')->default('quantity');
            $table->unsignedInteger('expected_duration_minutes')->nullable();
            $table->timestamp('preferred_start_at')->nullable();
            $table->timestamp('deadline_at')->nullable();
            $table->json('scope')->nullable();
            $table->string('product_scope')->default('all'); // all | category | variant
            $table->json('product_scope_payload')->nullable();
            $table->text('industry_requirements')->nullable();
            $table->text('safety_requirements')->nullable();
            $table->text('access_requirements')->nullable();
            $table->text('stock_movement_cutoff')->nullable();
            $table->unsignedInteger('required_team_size')->default(1);
            $table->json('required_skills')->nullable();
            $table->text('required_qualifications')->nullable();
            $table->json('required_evidence')->nullable();
            $table->text('signoff_requirements')->nullable();
            $table->string('sop_template')->nullable();
            $table->string('sop_version')->nullable();
            $table->text('custom_instructions')->nullable();
            $table->json('attachments')->nullable();
            $table->foreignId('catalogue_id')->nullable()->constrained('catalogues')->nullOnDelete();
            $table->foreignId('catalogue_version_id')->nullable()->constrained('catalogue_versions')->nullOnDelete();
            $table->unsignedInteger('catalogue_product_count')->default(0);
            $table->foreignId('inventory_snapshot_id')->nullable()->constrained('inventory_snapshots')->nullOnDelete();
            $table->json('provisional_estimate')->nullable();
            $table->boolean('deposit_required')->default(false);
            $table->decimal('deposit_amount', 14, 2)->default(0);
            $table->decimal('deposit_paid_amount', 14, 2)->default(0);
            $table->timestamp('deposit_due_at')->nullable();
            $table->decimal('quote_total', 14, 2)->nullable();
            $table->string('currency', 3)->default('KES');
            $table->unsignedBigInteger('accepted_quote_id')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('review_started_at')->nullable();
            $table->timestamp('quoted_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancellation_reason')->nullable();
            $table->json('cancellation')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'created_at']);
        });

        Schema::create('stocktake_job_status_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_id')->constrained('stocktake_jobs')->cascadeOnDelete();
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->string('actor_type')->default('tenant'); // tenant | platform | worker | system
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_role')->nullable();
            $table->text('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['job_id', 'created_at']);
        });

        Schema::create('stocktake_job_quotes', function (Blueprint $table): void {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_id')->constrained('stocktake_jobs')->cascadeOnDelete();
            $table->string('number')->unique();
            $table->unsignedInteger('version')->default(1);
            $table->string('status')->default('issued'); // issued | accepted | declined | superseded | expired
            $table->string('currency', 3)->default('KES');
            $table->decimal('base_service_charge', 14, 2)->default(0);
            $table->decimal('scope_complexity_charge', 14, 2)->default(0);
            $table->decimal('travel_expenses', 14, 2)->default(0);
            $table->decimal('other_expenses', 14, 2)->default(0);
            $table->decimal('discount', 14, 2)->default(0);
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('tax', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);
            $table->string('deposit_type')->nullable();
            $table->decimal('deposit_value', 14, 2)->nullable();
            $table->decimal('deposit_amount', 14, 2)->default(0);
            $table->timestamp('deposit_due_at')->nullable();
            $table->decimal('balance_due', 14, 2)->default(0);
            $table->decimal('worker_compensation_budget', 14, 2)->default(0);
            $table->json('worker_compensation_breakdown')->nullable();
            $table->decimal('platform_fee', 14, 2)->default(0);
            $table->decimal('margin_amount', 14, 2)->default(0);
            $table->text('notes')->nullable();
            $table->text('internal_notes')->nullable();
            $table->timestamp('valid_until')->nullable();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at')->nullable();
            $table->foreignId('accepted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('declined_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('declined_at')->nullable();
            $table->text('decline_reason')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['job_id', 'version']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('stocktake_deposits', function (Blueprint $table): void {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_id')->constrained('stocktake_jobs')->cascadeOnDelete();
            $table->foreignId('quote_id')->nullable()->constrained('stocktake_job_quotes')->nullOnDelete();
            $table->string('status')->default('required'); // required | pending | partially_paid | paid | failed | expired | refund_pending | refunded | waived
            $table->decimal('amount', 14, 2)->default(0);
            $table->decimal('paid_amount', 14, 2)->default(0);
            $table->string('currency', 3)->default('KES');
            $table->timestamp('due_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->json('policy')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique('job_id');
        });

        Schema::create('stocktake_deposit_payments', function (Blueprint $table): void {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('deposit_id')->constrained('stocktake_deposits')->cascadeOnDelete();
            $table->decimal('amount', 14, 2);
            $table->string('method'); // mpesa | card | bank | manual
            $table->string('reference')->nullable();
            $table->string('status')->default('pending'); // pending | confirmed | failed
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['deposit_id', 'status']);
        });

        Schema::create('stocktake_job_amendments', function (Blueprint $table): void {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_id')->constrained('stocktake_jobs')->cascadeOnDelete();
            $table->string('status')->default('pending'); // pending | approved | rejected | withdrawn
            $table->text('reason');
            $table->json('changes');
            $table->json('before')->nullable();
            $table->decimal('financial_impact', 14, 2)->default(0);
            $table->timestamp('tenant_consent_at')->nullable();
            $table->boolean('worker_consent_required')->default(false);
            $table->timestamp('worker_consent_at')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
            $table->index(['job_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stocktake_job_amendments');
        Schema::dropIfExists('stocktake_deposit_payments');
        Schema::dropIfExists('stocktake_deposits');
        Schema::dropIfExists('stocktake_job_quotes');
        Schema::dropIfExists('stocktake_job_status_history');
        Schema::dropIfExists('stocktake_jobs');
        Schema::dropIfExists('stocktake_policies');
    }
};
