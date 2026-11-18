<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('marketing_campaigns')) {
            Schema::create('marketing_campaigns', function (Blueprint $table) {
                $table->id();
                $table->ulid('uuid')->unique();
                $table->string('name', 160);
                $table->string('objective', 40);
                $table->string('channel', 40);
                $table->string('status', 20)->default('draft');
                $table->json('audience')->nullable();
                $table->decimal('budget_amount', 12, 2)->nullable();
                $table->string('budget_currency', 3)->default('GBP');
                $table->timestamp('starts_at')->nullable();
                $table->timestamp('ends_at')->nullable();
                $table->foreignId('landing_page_id')->nullable()->constrained('cms_pages')->nullOnDelete();
                $table->json('utm')->nullable();
                $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('approver_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('approved_at')->nullable();
                $table->timestamp('activated_at')->nullable();
                $table->json('results')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index(['status', 'starts_at']);
                $table->index('channel');
                $table->index('owner_id');
            });
        }

        if (! Schema::hasTable('campaign_assets')) {
            Schema::create('campaign_assets', function (Blueprint $table) {
                $table->id();
                $table->foreignId('campaign_id')->constrained('marketing_campaigns')->cascadeOnDelete();
                $table->foreignId('media_asset_id')->nullable()->constrained('media_assets')->nullOnDelete();
                $table->string('type', 20);
                $table->json('content')->nullable();
                $table->string('status', 20)->default('draft');
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index(['campaign_id', 'type']);
                $table->index(['campaign_id', 'status']);
            });
        }

        if (! Schema::hasTable('campaign_approvals')) {
            Schema::create('campaign_approvals', function (Blueprint $table) {
                $table->id();
                $table->foreignId('campaign_id')->constrained('marketing_campaigns')->cascadeOnDelete();
                $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('decision', 12)->default('pending');
                $table->text('notes')->nullable();
                $table->timestamp('decided_at')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->index(['campaign_id', 'created_at']);
                $table->index(['campaign_id', 'decision']);
            });
        }

        if (! Schema::hasTable('campaign_revisions')) {
            Schema::create('campaign_revisions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('campaign_id')->constrained('marketing_campaigns')->cascadeOnDelete();
                $table->unsignedInteger('revision_no');
                $table->json('snapshot');
                $table->string('change_summary', 255)->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('created_at')->nullable();
                $table->unique(['campaign_id', 'revision_no']);
                $table->index(['campaign_id', 'created_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_revisions');
        Schema::dropIfExists('campaign_approvals');
        Schema::dropIfExists('campaign_assets');
        Schema::dropIfExists('marketing_campaigns');
    }
};
