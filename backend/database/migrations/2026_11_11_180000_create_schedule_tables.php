<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stocktake_schedules', function (Blueprint $table): void {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('mode')->default('reminder'); // reminder | proposed | prepaid
            $table->string('frequency'); // on_demand | weekly | fortnightly | monthly | quarterly | half_yearly | annual | custom
            $table->string('scope_type')->default('all'); // all | category | branch | custom
            $table->json('scope_config')->nullable();
            $table->json('recurrence_config')->nullable(); // day_of_week, day_of_month, month, custom_dates
            $table->boolean('active')->default(true);
            $table->timestamp('next_occurrence_at')->nullable();
            $table->timestamp('last_occurrence_at')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'active']);
            $table->index(['next_occurrence_at']);
        });

        Schema::create('schedule_occurrences', function (Blueprint $table): void {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->foreignId('schedule_id')->constrained('stocktake_schedules')->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_id')->nullable()->constrained('stocktake_jobs')->nullOnDelete();
            $table->timestamp('scheduled_at');
            $table->string('status')->default('pending'); // pending | skipped | job_created | cancelled
            $table->string('skip_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['schedule_id', 'status']);
            $table->index(['tenant_id', 'scheduled_at']);
            $table->unique(['schedule_id', 'scheduled_at']); // no duplicate occurrences
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_occurrences');
        Schema::dropIfExists('stocktake_schedules');
    }
};
