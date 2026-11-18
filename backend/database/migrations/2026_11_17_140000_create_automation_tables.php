<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('automation_workflows')) {
            Schema::create('automation_workflows', function (Blueprint $table): void {
                $table->id();
                $table->ulid('uuid')->unique();
                $table->string('name', 190);
                $table->text('description')->nullable();
                $table->string('trigger', 40);
                $table->string('status', 20)->default('draft');
                $table->json('conditions')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('activated_at')->nullable();
                $table->timestamp('paused_at')->nullable();
                $table->timestamps();
                $table->index(['status', 'trigger']);
            });
        }

        if (! Schema::hasTable('automation_steps')) {
            Schema::create('automation_steps', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('workflow_id')->constrained('automation_workflows')->cascadeOnDelete();
                $table->unsignedInteger('position');
                $table->string('type', 30);
                $table->json('config')->nullable();
                $table->timestamps();
                $table->unique(['workflow_id', 'position']);
            });
        }

        if (! Schema::hasTable('automation_runs')) {
            Schema::create('automation_runs', function (Blueprint $table): void {
                $table->id();
                $table->ulid('uuid')->unique();
                $table->foreignId('workflow_id')->constrained('automation_workflows')->cascadeOnDelete();
                $table->foreignId('lead_id')->nullable()->constrained('marketing_leads')->nullOnDelete();
                $table->string('trigger', 40);
                $table->string('event_key', 64)->unique();
                $table->string('status', 20)->default('queued');
                $table->unsignedInteger('current_position')->default(0);
                $table->timestamp('resume_at')->nullable();
                $table->unsignedInteger('attempts')->default(0);
                $table->text('last_error')->nullable();
                $table->json('context')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();
                $table->index(['status', 'resume_at']);
                $table->index(['workflow_id', 'created_at']);
            });
        }

        if (! Schema::hasTable('automation_run_steps')) {
            Schema::create('automation_run_steps', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('run_id')->constrained('automation_runs')->cascadeOnDelete();
                $table->foreignId('step_id')->nullable()->constrained('automation_steps')->nullOnDelete();
                $table->unsignedInteger('position');
                $table->string('type', 30);
                $table->json('config')->nullable();
                $table->string('status', 20)->default('pending');
                $table->json('output')->nullable();
                $table->unsignedInteger('attempts')->default(0);
                $table->text('last_error')->nullable();
                $table->timestamp('scheduled_for')->nullable();
                $table->timestamp('executed_at')->nullable();
                $table->timestamps();
                $table->unique(['run_id', 'position']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_run_steps');
        Schema::dropIfExists('automation_runs');
        Schema::dropIfExists('automation_steps');
        Schema::dropIfExists('automation_workflows');
    }
};
