<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tracks individual device browser sessions for audit
        Schema::create('count_device_sessions', function (Blueprint $table): void {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_id')->constrained('stocktake_jobs')->cascadeOnDelete();
            $table->foreignId('assignment_id')->nullable()->constrained('job_assignments')->nullOnDelete();
            $table->foreignId('worker_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('device_fingerprint')->nullable();
            $table->string('platform')->nullable();      // browser UA family
            $table->string('app_version')->nullable();
            $table->boolean('offline_capable')->default(false);
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('package_downloaded_at')->nullable();
            $table->timestamps();
            $table->index(['assignment_id']);
            $table->index(['worker_id']);
        });

        // Each time the worker calls the batch sync endpoint, one batch is recorded
        Schema::create('count_sync_batches', function (Blueprint $table): void {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->foreignId('session_id')->constrained('count_sessions')->cascadeOnDelete();
            $table->foreignId('worker_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('device_session_id')->nullable();  // count_device_sessions.uuid
            $table->unsignedInteger('items_total')->default(0);
            $table->unsignedInteger('items_synced')->default(0);
            $table->unsignedInteger('items_conflict')->default(0);
            $table->unsignedInteger('items_rejected')->default(0);
            $table->json('results')->nullable();              // per-item outcome array
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
            $table->index(['session_id']);
        });

        // Conflict records — when an offline entry cannot be auto-merged
        Schema::create('count_sync_conflicts', function (Blueprint $table): void {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->foreignId('session_id')->constrained('count_sessions')->cascadeOnDelete();
            $table->foreignId('entry_id')->nullable()->constrained('count_entries')->nullOnDelete();
            $table->string('idempotency_key');
            $table->json('client_value');      // what the device sent
            $table->json('server_value');      // what the server currently holds
            $table->string('resolution')->nullable(); // client_wins | server_wins | manual
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['session_id', 'resolution']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('count_sync_conflicts');
        Schema::dropIfExists('count_sync_batches');
        Schema::dropIfExists('count_device_sessions');
    }
};
