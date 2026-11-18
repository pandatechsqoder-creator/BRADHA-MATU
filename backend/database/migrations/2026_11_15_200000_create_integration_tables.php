<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('integration_connections')) {
            Schema::create('integration_connections', function (Blueprint $table): void {
                $table->id();
                $table->ulid('uuid')->unique();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('name');
                $table->string('provider')->default('custom');
                $table->string('type')->default('csv');
                $table->string('status')->default('active');
                $table->text('credentials')->nullable();
                $table->json('settings')->nullable();
                $table->text('webhook_secret')->nullable();
                $table->timestamp('last_synced_at')->nullable();
                $table->text('last_error')->nullable();
                $table->timestamps();
                $table->unique(['tenant_id', 'name']);
                $table->index(['tenant_id', 'status']);
            });
        }

        if (! Schema::hasTable('integration_mappings')) {
            Schema::create('integration_mappings', function (Blueprint $table): void {
                $table->id();
                $table->ulid('uuid')->unique();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('connection_id')->constrained('integration_connections')->cascadeOnDelete();
                $table->string('entity');
                $table->json('field_map');
                $table->json('options')->nullable();
                $table->timestamps();
                $table->unique(['connection_id', 'entity']);
            });
        }

        if (! Schema::hasTable('integration_sync_runs')) {
            Schema::create('integration_sync_runs', function (Blueprint $table): void {
                $table->id();
                $table->ulid('uuid')->unique();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('connection_id')->constrained('integration_connections')->cascadeOnDelete();
                $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('entity');
                $table->string('direction')->default('import');
                $table->string('status')->default('running');
                $table->unsignedInteger('records_in')->default(0);
                $table->unsignedInteger('records_upserted')->default(0);
                $table->unsignedInteger('records_skipped')->default(0);
                $table->unsignedInteger('conflict_count')->default(0);
                $table->text('message')->nullable();
                $table->json('summary')->nullable();
                $table->timestamp('started_at');
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();
                $table->index(['connection_id', 'status']);
                $table->index(['tenant_id', 'entity']);
            });
        }

        if (! Schema::hasTable('integration_conflicts')) {
            Schema::create('integration_conflicts', function (Blueprint $table): void {
                $table->id();
                $table->ulid('uuid')->unique();
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('connection_id')->constrained('integration_connections')->cascadeOnDelete();
                $table->foreignId('run_id')->nullable()->constrained('integration_sync_runs')->nullOnDelete();
                $table->string('entity');
                $table->string('reference')->nullable();
                $table->string('reason');
                $table->json('payload')->nullable();
                $table->string('resolution')->default('pending');
                $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('resolved_at')->nullable();
                $table->timestamps();
                $table->index(['connection_id', 'resolution']);
                $table->index(['tenant_id', 'entity']);
            });
        }

        if (! Schema::hasTable('integration_webhook_events')) {
            Schema::create('integration_webhook_events', function (Blueprint $table): void {
                $table->id();
                $table->ulid('uuid')->unique();
                $table->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();
                $table->foreignId('connection_id')->constrained('integration_connections')->cascadeOnDelete();
                $table->string('event');
                $table->json('payload')->nullable();
                $table->boolean('signature_valid')->default(false);
                $table->string('status')->default('received');
                $table->unsignedInteger('attempts')->default(0);
                $table->text('last_error')->nullable();
                $table->timestamp('processed_at')->nullable();
                $table->timestamps();
                $table->index(['connection_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_webhook_events');
        Schema::dropIfExists('integration_conflicts');
        Schema::dropIfExists('integration_sync_runs');
        Schema::dropIfExists('integration_mappings');
        Schema::dropIfExists('integration_connections');
    }
};
