<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_taker_profiles', function (Blueprint $table): void {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('legal_name');
            $table->string('phone', 30)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('county', 120)->nullable();
            $table->string('country', 80)->default('Kenya');
            $table->json('languages')->nullable();
            $table->string('transport', 120)->nullable();
            $table->unsignedInteger('experience_years')->default(0);
            $table->text('bio')->nullable();
            $table->string('profile_photo_path')->nullable();
            $table->string('level', 30)->default('applicant'); // applicant | trainee | verified | professional | expert | senior_supervisor
            $table->string('status', 20)->default('active'); // active | suspended | inactive
            $table->string('verification_status', 20)->default('unverified'); // unverified | pending | verified | rejected
            $table->timestamp('phone_verified_at')->nullable();
            $table->timestamp('identity_verified_at')->nullable();
            $table->timestamp('training_completed_at')->nullable();
            $table->timestamp('terms_accepted_at')->nullable();
            $table->unsignedInteger('completed_jobs_count')->default(0);
            $table->decimal('verified_accuracy', 5, 2)->nullable();
            $table->decimal('reliability_score', 5, 2)->nullable();
            $table->timestamps();
            $table->index('level');
            $table->index('status');
            $table->index('verification_status');
        });

        Schema::create('stock_taker_verifications', function (Blueprint $table): void {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->foreignId('profile_id')->constrained('stock_taker_profiles')->cascadeOnDelete();
            $table->string('check_type', 30); // identity | phone | background | reference | training
            $table->string('status', 20)->default('pending'); // pending | verified | rejected
            $table->string('reference', 120)->nullable();
            $table->string('notes', 500)->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->index(['profile_id', 'check_type']);
        });

        Schema::create('stock_taker_skills', function (Blueprint $table): void {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->foreignId('profile_id')->constrained('stock_taker_profiles')->cascadeOnDelete();
            $table->string('skill', 80);
            $table->string('level', 20)->default('intermediate'); // basic | intermediate | advanced
            $table->unsignedTinyInteger('years_experience')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->unique(['profile_id', 'skill']);
        });

        Schema::create('stock_taker_service_areas', function (Blueprint $table): void {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->foreignId('profile_id')->constrained('stock_taker_profiles')->cascadeOnDelete();
            $table->string('area', 120);
            $table->string('city', 120)->nullable();
            $table->string('county', 120)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
            $table->unique(['profile_id', 'area']);
        });

        Schema::create('stock_taker_availability', function (Blueprint $table): void {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->foreignId('profile_id')->constrained('stock_taker_profiles')->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday'); // 0 = Sunday .. 6 = Saturday
            $table->time('start_time');
            $table->time('end_time');
            $table->string('timezone', 60)->default('Africa/Nairobi');
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['profile_id', 'weekday', 'start_time']);
        });

        Schema::create('stock_taker_training_completions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->foreignId('profile_id')->constrained('stock_taker_profiles')->cascadeOnDelete();
            $table->string('module', 80);
            $table->string('status', 20)->default('completed'); // completed | failed
            $table->decimal('score', 5, 2)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('notes', 500)->nullable();
            $table->timestamps();
            $table->unique(['profile_id', 'module']);
        });

        Schema::create('job_assignments', function (Blueprint $table): void {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->foreignId('job_id')->constrained('stocktake_jobs')->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('profile_id')->constrained('stock_taker_profiles')->cascadeOnDelete();
            $table->string('role', 20)->default('counter'); // lead | counter | supervisor
            $table->string('status', 20)->default('offered'); // offered | accepted | declined | expired | withdrawn | reassigned | cancelled | completed
            $table->decimal('compensation_amount', 14, 2)->default(0);
            $table->string('currency', 3)->default('KES');
            $table->timestamp('offer_expires_at')->nullable();
            $table->foreignId('offered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('offered_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->string('declined_reason', 500)->nullable();
            $table->foreignId('replaced_assignment_id')->nullable()->constrained('job_assignments')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['job_id', 'status']);
            $table->index(['profile_id', 'status']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('job_assignment_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('assignment_id')->constrained('job_assignments')->cascadeOnDelete();
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_type', 20)->default('platform'); // platform | worker | system
            $table->string('reason', 500)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['assignment_id', 'created_at']);
        });

        Schema::create('stock_taker_earnings', function (Blueprint $table): void {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->foreignId('profile_id')->constrained('stock_taker_profiles')->cascadeOnDelete();
            $table->foreignId('assignment_id')->nullable()->constrained('job_assignments')->nullOnDelete();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference', 40)->unique();
            $table->decimal('amount', 14, 2);
            $table->string('currency', 3)->default('KES');
            $table->string('status', 20)->default('accrued'); // accrued | payable | paid | reversed
            $table->string('description', 255)->nullable();
            $table->timestamp('earned_at')->nullable();
            $table->timestamps();
            $table->index(['profile_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_taker_earnings');
        Schema::dropIfExists('job_assignment_events');
        Schema::dropIfExists('job_assignments');
        Schema::dropIfExists('stock_taker_training_completions');
        Schema::dropIfExists('stock_taker_availability');
        Schema::dropIfExists('stock_taker_service_areas');
        Schema::dropIfExists('stock_taker_skills');
        Schema::dropIfExists('stock_taker_verifications');
        Schema::dropIfExists('stock_taker_profiles');
    }
};
