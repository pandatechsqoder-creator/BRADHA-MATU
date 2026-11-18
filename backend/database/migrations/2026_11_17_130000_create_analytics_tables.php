<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('analytics_events')) {
            Schema::create('analytics_events', function (Blueprint $table) {
                $table->id();
                $table->string('event_name', 60);
                $table->timestamp('occurred_at');
                $table->string('session_id', 64)->nullable();
                $table->foreignId('campaign_id')->nullable()->constrained('marketing_campaigns')->nullOnDelete();
                $table->string('path', 512)->nullable();
                $table->string('referrer', 2048)->nullable();
                $table->json('utm')->nullable();
                $table->string('device', 20)->nullable();
                $table->json('consent_state')->nullable();
                $table->json('payload')->nullable();
                $table->string('ip_hash', 64)->nullable();
                $table->timestamp('created_at')->nullable();
                $table->index(['event_name', 'occurred_at']);
                $table->index('session_id');
                $table->index('campaign_id');
                $table->index('path');
            });
        }

        if (! Schema::hasTable('conversion_events')) {
            Schema::create('conversion_events', function (Blueprint $table) {
                $table->id();
                $table->string('event_name', 60);
                $table->foreignId('lead_id')->nullable()->constrained('marketing_leads')->nullOnDelete();
                $table->foreignId('campaign_id')->nullable()->constrained('marketing_campaigns')->nullOnDelete();
                $table->decimal('value', 12, 2)->nullable();
                $table->string('currency', 3)->nullable();
                $table->timestamp('occurred_at');
                $table->json('consent_state')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->index(['event_name', 'occurred_at']);
                $table->index('campaign_id');
                $table->index('lead_id');
            });
        }

        if (! Schema::hasTable('tracking_preferences')) {
            Schema::create('tracking_preferences', function (Blueprint $table) {
                $table->id();
                $table->string('session_id', 64)->unique();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->boolean('necessary')->default(true);
                $table->boolean('analytics')->default(false);
                $table->boolean('marketing')->default(false);
                $table->string('policy_version', 32)->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tracking_preferences');
        Schema::dropIfExists('conversion_events');
        Schema::dropIfExists('analytics_events');
    }
};
