<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('meta_business_accounts')) {
            Schema::create('meta_business_accounts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('connection_id')->constrained('oauth_connections')->cascadeOnDelete();
                $table->string('external_id');
                $table->string('name');
                $table->timestamp('synced_at')->nullable();
                $table->timestamps();
                $table->unique(['connection_id', 'external_id']);
            });
        }

        if (! Schema::hasTable('meta_pages')) {
            Schema::create('meta_pages', function (Blueprint $table) {
                $table->id();
                $table->foreignId('connection_id')->constrained('oauth_connections')->cascadeOnDelete();
                $table->foreignId('meta_business_account_id')->nullable()->constrained('meta_business_accounts')->nullOnDelete();
                $table->string('external_id');
                $table->string('name');
                $table->string('category')->nullable();
                $table->text('page_token')->nullable();
                $table->string('status', 32)->default('active');
                $table->json('insights')->nullable();
                $table->timestamp('synced_at')->nullable();
                $table->timestamps();
                $table->unique(['connection_id', 'external_id']);
            });
        }

        if (! Schema::hasTable('meta_instagram_accounts')) {
            Schema::create('meta_instagram_accounts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('connection_id')->constrained('oauth_connections')->cascadeOnDelete();
                $table->foreignId('meta_page_id')->nullable()->constrained('meta_pages')->nullOnDelete();
                $table->string('external_id');
                $table->string('username');
                $table->string('account_type', 32)->nullable();
                $table->string('status', 32)->default('active');
                $table->timestamp('synced_at')->nullable();
                $table->timestamps();
                $table->unique(['connection_id', 'external_id']);
            });
        }

        if (! Schema::hasTable('meta_ad_accounts')) {
            Schema::create('meta_ad_accounts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('connection_id')->constrained('oauth_connections')->cascadeOnDelete();
                $table->string('external_id');
                $table->string('name');
                $table->string('currency', 8)->nullable();
                $table->decimal('spend_cap', 14, 2)->nullable();
                $table->string('status', 32)->nullable();
                $table->timestamp('synced_at')->nullable();
                $table->timestamps();
                $table->unique(['connection_id', 'external_id']);
            });
        }

        if (! Schema::hasTable('meta_campaign_records')) {
            Schema::create('meta_campaign_records', function (Blueprint $table) {
                $table->id();
                $table->foreignId('ad_account_id')->constrained('meta_ad_accounts')->cascadeOnDelete();
                $table->string('external_id');
                $table->string('name');
                $table->string('status', 32)->nullable();
                $table->decimal('budget_amount', 14, 2)->nullable();
                $table->decimal('spend_amount', 14, 2)->default(0);
                $table->unsignedBigInteger('impressions')->default(0);
                $table->unsignedBigInteger('clicks')->default(0);
                $table->json('results')->nullable();
                $table->timestamp('synced_at')->nullable();
                $table->timestamps();
                $table->unique(['ad_account_id', 'external_id']);
            });
        }

        if (! Schema::hasTable('social_posts')) {
            Schema::create('social_posts', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('meta_page_id')->nullable()->constrained('meta_pages')->nullOnDelete();
                $table->foreignId('meta_instagram_account_id')->nullable()->constrained('meta_instagram_accounts')->nullOnDelete();
                $table->foreignId('media_asset_id')->nullable()->constrained('media_assets')->nullOnDelete();
                $table->text('caption');
                $table->string('link_url', 1024)->nullable();
                $table->string('status', 20)->default('draft');
                $table->timestamp('scheduled_at')->nullable();
                $table->timestamp('published_at')->nullable();
                $table->string('external_id')->nullable();
                $table->text('error')->nullable();
                $table->json('insights')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index(['status', 'scheduled_at']);
            });
        }

        if (! Schema::hasTable('social_post_approvals')) {
            Schema::create('social_post_approvals', function (Blueprint $table) {
                $table->id();
                $table->foreignId('social_post_id')->constrained('social_posts')->cascadeOnDelete();
                $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('decision', 20);
                $table->text('notes')->nullable();
                $table->timestamp('decided_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('social_post_approvals');
        Schema::dropIfExists('social_posts');
        Schema::dropIfExists('meta_campaign_records');
        Schema::dropIfExists('meta_ad_accounts');
        Schema::dropIfExists('meta_instagram_accounts');
        Schema::dropIfExists('meta_pages');
        Schema::dropIfExists('meta_business_accounts');
    }
};
