<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('procedure_templates', function (Blueprint $table): void {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('category', 60)->default('stocktake');
            $table->string('status', 20)->default('draft'); // draft | active | retired
            $table->unsignedBigInteger('current_version_id')->nullable()->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('procedure_template_versions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->foreignId('template_id')->constrained('procedure_templates')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('title');
            $table->text('summary')->nullable();
            $table->json('steps');
            $table->json('checklists')->nullable();
            $table->boolean('require_acknowledgement')->default(true);
            $table->string('status', 20)->default('published'); // published | superseded
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['template_id', 'version']);
        });

        Schema::create('job_procedures', function (Blueprint $table): void {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_id')->constrained('stocktake_jobs')->cascadeOnDelete();
            $table->foreignId('template_id')->nullable()->constrained('procedure_templates')->nullOnDelete();
            $table->foreignId('template_version_id')->nullable()->constrained('procedure_template_versions')->nullOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->string('title');
            $table->text('summary')->nullable();
            $table->json('steps')->nullable();
            $table->boolean('require_acknowledgement')->default(true);
            $table->string('status', 20)->default('active'); // active | superseded
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamps();
            $table->index(['job_id', 'status']);
        });

        Schema::create('job_procedure_steps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('job_procedure_id')->constrained('job_procedures')->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->string('title');
            $table->text('instructions')->nullable();
            $table->boolean('required')->default(true);
            $table->boolean('evidence_required')->default(false);
            $table->timestamps();
            $table->unique(['job_procedure_id', 'position']);
        });

        Schema::create('job_procedure_acknowledgements', function (Blueprint $table): void {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_procedure_id')->constrained('job_procedures')->cascadeOnDelete();
            $table->foreignId('assignment_id')->constrained('job_assignments')->cascadeOnDelete();
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamps();
            $table->unique(['job_procedure_id', 'assignment_id'], 'job_proc_acks_proc_assignment_unique');
        });

        Schema::create('job_checklists', function (Blueprint $table): void {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_id')->constrained('stocktake_jobs')->cascadeOnDelete();
            $table->foreignId('job_procedure_id')->nullable()->constrained('job_procedures')->cascadeOnDelete();
            $table->string('name');
            $table->string('kind', 30)->default('pre_start'); // pre_start | site | close_out
            $table->boolean('required')->default(true);
            $table->string('status', 20)->default('pending'); // pending | completed
            $table->timestamps();
            $table->index(['job_id', 'required']);
        });

        Schema::create('job_checklist_items', function (Blueprint $table): void {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->foreignId('checklist_id')->constrained('job_checklists')->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->string('label');
            $table->text('instructions')->nullable();
            $table->string('response_type', 20)->default('boolean'); // boolean | text | select
            $table->boolean('required')->default(true);
            $table->json('options')->nullable();
            $table->timestamps();
            $table->unique(['checklist_id', 'position']);
        });

        Schema::create('job_checklist_responses', function (Blueprint $table): void {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('checklist_item_id')->constrained('job_checklist_items')->cascadeOnDelete();
            $table->foreignId('assignment_id')->constrained('job_assignments')->cascadeOnDelete();
            $table->string('value', 1000);
            $table->string('notes', 500)->nullable();
            $table->foreignId('responded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();
            $table->unique(['checklist_item_id', 'assignment_id'], 'job_checklist_responses_item_assignment_unique');
        });

        Schema::create('job_site_checkins', function (Blueprint $table): void {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_id')->constrained('stocktake_jobs')->cascadeOnDelete();
            $table->foreignId('assignment_id')->constrained('job_assignments')->cascadeOnDelete();
            $table->string('site_label')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->decimal('distance_m', 10, 2)->nullable();
            $table->string('method', 20)->default('manual'); // gps | manual
            $table->string('notes', 500)->nullable();
            $table->foreignId('checked_in_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('checked_in_at')->nullable();
            $table->timestamps();
            $table->index(['assignment_id', 'checked_in_at']);
        });

        Schema::create('job_site_checkouts', function (Blueprint $table): void {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_id')->constrained('stocktake_jobs')->cascadeOnDelete();
            $table->foreignId('assignment_id')->constrained('job_assignments')->cascadeOnDelete();
            $table->foreignId('checkin_id')->nullable()->constrained('job_site_checkins')->nullOnDelete();
            $table->string('notes', 500)->nullable();
            $table->foreignId('checked_out_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('checked_out_at')->nullable();
            $table->timestamps();
        });

        Schema::create('job_attendance', function (Blueprint $table): void {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_id')->constrained('stocktake_jobs')->cascadeOnDelete();
            $table->foreignId('assignment_id')->constrained('job_assignments')->cascadeOnDelete();
            $table->string('status', 20)->default('present'); // present | late | checked_out | absent
            $table->foreignId('checkin_id')->nullable()->constrained('job_site_checkins')->nullOnDelete();
            $table->foreignId('checkout_id')->nullable()->constrained('job_site_checkouts')->nullOnDelete();
            $table->timestamp('scheduled_start_at')->nullable();
            $table->timestamp('checked_in_at')->nullable();
            $table->timestamp('checked_out_at')->nullable();
            $table->unsignedInteger('minutes_on_site')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['job_id', 'assignment_id']);
        });

        Schema::create('job_progress_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_id')->constrained('stocktake_jobs')->cascadeOnDelete();
            $table->foreignId('assignment_id')->nullable()->constrained('job_assignments')->nullOnDelete();
            $table->string('stage', 40);
            $table->unsignedTinyInteger('percent')->default(0);
            $table->string('message', 255)->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_type', 20)->default('system'); // platform | worker | tenant | system
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at')->useCurrent();
            $table->index(['job_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_progress_events');
        Schema::dropIfExists('job_attendance');
        Schema::dropIfExists('job_site_checkouts');
        Schema::dropIfExists('job_site_checkins');
        Schema::dropIfExists('job_checklist_responses');
        Schema::dropIfExists('job_checklist_items');
        Schema::dropIfExists('job_checklists');
        Schema::dropIfExists('job_procedure_acknowledgements');
        Schema::dropIfExists('job_procedure_steps');
        Schema::dropIfExists('job_procedures');
        Schema::dropIfExists('procedure_template_versions');
        Schema::dropIfExists('procedure_templates');
    }
};
