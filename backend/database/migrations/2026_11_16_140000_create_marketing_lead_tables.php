<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('marketing_leads')) {
            Schema::create('marketing_leads', function (Blueprint $table): void {
                $table->id();
                $table->ulid('uuid')->unique();
                $table->string('reference', 32)->unique();
                $table->string('submission_token', 64)->unique();
                $table->string('type', 24)->default('contact');
                $table->string('status', 24)->default('new');
                $table->string('business_name')->nullable();
                $table->string('contact_name');
                $table->string('email')->nullable();
                $table->string('phone', 40)->nullable();
                $table->string('industry')->nullable();
                $table->string('town')->nullable();
                $table->string('county')->nullable();
                $table->unsignedInteger('branches_count')->nullable();
                $table->unsignedInteger('catalogue_size')->nullable();
                $table->string('service_requested')->nullable();
                $table->string('stocktake_frequency')->nullable();
                $table->text('message')->nullable();
                $table->string('source', 40)->default('website');
                $table->string('utm_source', 190)->nullable();
                $table->string('utm_medium', 190)->nullable();
                $table->string('utm_campaign', 190)->nullable();
                $table->string('utm_term', 190)->nullable();
                $table->string('utm_content', 190)->nullable();
                $table->string('landing_page', 500)->nullable();
                $table->text('referrer')->nullable();
                $table->boolean('consent_marketing')->default(false);
                $table->boolean('consent_transactional')->default(false);
                $table->string('consent_ip', 45)->nullable();
                $table->timestamp('consent_at')->nullable();
                $table->json('metadata')->nullable();
                $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('last_activity_at')->nullable();
                $table->timestamp('converted_at')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->index(['status', 'created_at']);
                $table->index(['type', 'created_at']);
                $table->index(['assigned_to', 'status']);
                $table->index('email');
                $table->index('phone');
                $table->index('utm_campaign');
            });
        }

        if (! Schema::hasTable('lead_activities')) {
            Schema::create('lead_activities', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('lead_id')->constrained('marketing_leads')->cascadeOnDelete();
                $table->string('type', 24);
                $table->text('body')->nullable();
                $table->json('metadata')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('created_at')->nullable();
                $table->index(['lead_id', 'created_at']);
            });
        }

        if (! Schema::hasTable('lead_assignments')) {
            Schema::create('lead_assignments', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('lead_id')->constrained('marketing_leads')->cascadeOnDelete();
                $table->foreignId('assigned_to')->constrained('users')->cascadeOnDelete();
                $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('assigned_at');
                $table->timestamp('unassigned_at')->nullable();
                $table->string('reason', 255)->nullable();
                $table->index(['lead_id', 'assigned_at']);
                $table->index(['assigned_to', 'unassigned_at']);
            });
        }

        if (! Schema::hasTable('lead_exports')) {
            Schema::create('lead_exports', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
                $table->json('filters')->nullable();
                $table->unsignedInteger('row_count')->default(0);
                $table->string('checksum', 64);
                $table->timestamp('created_at')->nullable();
                $table->index('created_at');
            });
        }

        if (! Schema::hasTable('marketing_consents')) {
            Schema::create('marketing_consents', function (Blueprint $table): void {
                $table->id();
                $table->string('subject_type', 60);
                $table->unsignedBigInteger('subject_id');
                $table->string('channel', 24);
                $table->string('status', 24)->default('granted');
                $table->string('source', 60)->nullable();
                $table->string('policy_version', 40)->nullable();
                $table->string('ip', 45)->nullable();
                $table->string('user_agent', 500)->nullable();
                $table->timestamp('occurred_at')->nullable();
                $table->index(['subject_type', 'subject_id']);
                $table->index(['channel', 'occurred_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_consents');
        Schema::dropIfExists('lead_exports');
        Schema::dropIfExists('lead_assignments');
        Schema::dropIfExists('lead_activities');
        Schema::dropIfExists('marketing_leads');
    }
};
