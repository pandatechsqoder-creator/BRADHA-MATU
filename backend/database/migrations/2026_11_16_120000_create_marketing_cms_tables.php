<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('marketing_settings')) {
            Schema::create('marketing_settings', function (Blueprint $table): void {
                $table->id();
                $table->string('group')->default('general');
                $table->string('key')->unique();
                $table->json('value')->nullable();
                $table->boolean('is_public')->default(false);
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index(['group', 'key']);
                $table->index('is_public');
            });
        }

        if (! Schema::hasTable('cms_pages')) {
            Schema::create('cms_pages', function (Blueprint $table): void {
                $table->id();
                $table->ulid('uuid')->unique();
                $table->string('slug')->unique();
                $table->string('title');
                $table->string('type')->default('page');
                $table->string('template')->default('public.page');
                $table->string('status')->default('draft');
                $table->string('locale', 12)->default('en');
                $table->boolean('is_home')->default(false);
                $table->boolean('requires_auth')->default(false);
                $table->timestamp('published_at')->nullable();
                $table->timestamp('scheduled_at')->nullable();
                $table->boolean('noindex')->default(false);
                $table->unsignedBigInteger('current_version_id')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();
                $table->index(['status', 'published_at']);
                $table->index(['type', 'status']);
                $table->index('is_home');
            });
        }

        if (! Schema::hasTable('cms_page_sections')) {
            Schema::create('cms_page_sections', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('page_id')->constrained('cms_pages')->cascadeOnDelete();
                $table->string('type');
                $table->unsignedInteger('sort_order')->default(0);
                $table->json('content')->nullable();
                $table->json('settings')->nullable();
                $table->boolean('is_visible')->default(true);
                $table->timestamps();
                $table->index(['page_id', 'sort_order']);
            });
        }

        if (! Schema::hasTable('cms_menus')) {
            Schema::create('cms_menus', function (Blueprint $table): void {
                $table->id();
                $table->string('key')->unique();
                $table->string('name');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('cms_menu_items')) {
            Schema::create('cms_menu_items', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('menu_id')->constrained('cms_menus')->cascadeOnDelete();
                $table->foreignId('parent_id')->nullable()->constrained('cms_menu_items')->nullOnDelete();
                $table->string('label');
                $table->foreignId('page_id')->nullable()->constrained('cms_pages')->nullOnDelete();
                $table->string('url')->nullable();
                $table->string('target', 8)->default('_self');
                $table->unsignedInteger('sort_order')->default(0);
                $table->boolean('is_visible')->default(true);
                $table->timestamps();
                $table->index(['menu_id', 'sort_order']);
            });
        }

        if (! Schema::hasTable('seo_metadata')) {
            Schema::create('seo_metadata', function (Blueprint $table): void {
                $table->id();
                $table->string('seoable_type');
                $table->unsignedBigInteger('seoable_id');
                $table->string('title')->nullable();
                $table->text('description')->nullable();
                $table->string('canonical_url', 2048)->nullable();
                $table->string('og_title')->nullable();
                $table->text('og_description')->nullable();
                $table->unsignedBigInteger('og_asset_id')->nullable();
                $table->string('twitter_card', 40)->default('summary_large_image');
                $table->boolean('robots_index')->default(true);
                $table->boolean('robots_follow')->default(true);
                $table->string('schema_type')->nullable();
                $table->decimal('priority', 2, 1)->default(0.5);
                $table->string('change_frequency', 20)->default('weekly');
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->unique(['seoable_type', 'seoable_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_metadata');
        Schema::dropIfExists('cms_menu_items');
        Schema::dropIfExists('cms_menus');
        Schema::dropIfExists('cms_page_sections');
        Schema::dropIfExists('cms_pages');
        Schema::dropIfExists('marketing_settings');
    }
};
