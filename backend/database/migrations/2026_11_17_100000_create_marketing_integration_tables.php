<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('oauth_connections')) {
            Schema::create('oauth_connections', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->string('provider', 32);
                $table->string('account_label')->nullable();
                $table->text('credentials')->nullable();
                $table->json('scopes')->nullable();
                $table->string('status', 20)->default('connected');
                $table->foreignId('connected_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('last_synced_at')->nullable();
                $table->text('last_error')->nullable();
                $table->timestamps();
                $table->index(['provider', 'status']);
            });
        }

        if (! Schema::hasTable('google_business_accounts')) {
            Schema::create('google_business_accounts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('connection_id')->constrained('oauth_connections')->cascadeOnDelete();
                $table->string('external_id');
                $table->string('name');
                $table->json('metadata')->nullable();
                $table->timestamp('synced_at')->nullable();
                $table->timestamps();
                $table->unique(['connection_id', 'external_id']);
            });
        }

        if (! Schema::hasTable('google_business_locations')) {
            Schema::create('google_business_locations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('connection_id')->constrained('oauth_connections')->cascadeOnDelete();
                $table->foreignId('account_id')->constrained('google_business_accounts')->cascadeOnDelete();
                $table->string('external_id');
                $table->string('name');
                $table->json('address')->nullable();
                $table->string('phone')->nullable();
                $table->string('status', 32)->nullable();
                $table->json('insights')->nullable();
                $table->timestamp('synced_at')->nullable();
                $table->timestamps();
                $table->unique(['account_id', 'external_id']);
            });
        }

        if (! Schema::hasTable('google_search_properties')) {
            Schema::create('google_search_properties', function (Blueprint $table) {
                $table->id();
                $table->foreignId('connection_id')->constrained('oauth_connections')->cascadeOnDelete();
                $table->string('site_url', 512);
                $table->string('permission_level', 32)->nullable();
                $table->timestamp('synced_at')->nullable();
                $table->timestamps();
                $table->unique(['connection_id', 'site_url'], 'google_search_properties_connection_site_unique');
            });
        }

        if (! Schema::hasTable('google_search_metrics')) {
            Schema::create('google_search_metrics', function (Blueprint $table) {
                $table->id();
                $table->foreignId('property_id')->constrained('google_search_properties')->cascadeOnDelete();
                $table->date('date');
                $table->string('query', 512)->nullable();
                $table->string('page', 1024)->nullable();
                $table->string('dimension_hash', 64);
                $table->unsignedBigInteger('clicks')->default(0);
                $table->unsignedBigInteger('impressions')->default(0);
                $table->decimal('ctr', 8, 6)->default(0);
                $table->decimal('position', 8, 2)->default(0);
                $table->timestamps();
                $table->unique(['property_id', 'date', 'dimension_hash'], 'google_search_metrics_unique_row');
            });
        }

        if (! Schema::hasTable('ga4_properties')) {
            Schema::create('ga4_properties', function (Blueprint $table) {
                $table->id();
                $table->foreignId('connection_id')->constrained('oauth_connections')->cascadeOnDelete();
                $table->string('property_id', 64);
                $table->string('display_name');
                $table->timestamp('synced_at')->nullable();
                $table->timestamps();
                $table->unique(['connection_id', 'property_id']);
            });
        }

        if (! Schema::hasTable('ga4_metrics')) {
            Schema::create('ga4_metrics', function (Blueprint $table) {
                $table->id();
                $table->foreignId('property_id')->constrained('ga4_properties')->cascadeOnDelete();
                $table->date('date');
                $table->unsignedBigInteger('sessions')->default(0);
                $table->unsignedBigInteger('total_users')->default(0);
                $table->decimal('conversions', 12, 2)->default(0);
                $table->timestamps();
                $table->unique(['property_id', 'date']);
            });
        }

        if (! Schema::hasTable('gtm_containers')) {
            Schema::create('gtm_containers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('connection_id')->constrained('oauth_connections')->cascadeOnDelete();
                $table->string('container_id', 64);
                $table->string('public_id', 32)->nullable();
                $table->string('name');
                $table->string('workspace_id', 64)->nullable();
                $table->timestamp('last_published_at')->nullable();
                $table->timestamps();
                $table->unique(['connection_id', 'container_id']);
            });
        }

        if (! Schema::hasTable('marketing_integration_sync_runs')) {
            Schema::create('marketing_integration_sync_runs', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('connection_id')->constrained('oauth_connections')->cascadeOnDelete();
                $table->string('provider', 32)->default('google');
                $table->string('type', 32);
                $table->string('status', 20)->default('queued');
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->json('counts')->nullable();
                $table->text('error')->nullable();
                $table->timestamps();
                $table->index(['connection_id', 'type', 'started_at'], 'marketing_integration_runs_lookup');
            });
        }

        if (! Schema::hasTable('marketing_integration_logs')) {
            Schema::create('marketing_integration_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('connection_id')->nullable()->constrained('oauth_connections')->nullOnDelete();
                $table->string('provider', 32)->default('google');
                $table->string('level', 16)->default('info');
                $table->string('event', 80);
                $table->text('message');
                $table->json('context')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->index(['provider', 'level']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_integration_logs');
        Schema::dropIfExists('marketing_integration_sync_runs');
        Schema::dropIfExists('gtm_containers');
        Schema::dropIfExists('ga4_metrics');
        Schema::dropIfExists('ga4_properties');
        Schema::dropIfExists('google_search_metrics');
        Schema::dropIfExists('google_search_properties');
        Schema::dropIfExists('google_business_locations');
        Schema::dropIfExists('google_business_accounts');
        Schema::dropIfExists('oauth_connections');
    }
};
