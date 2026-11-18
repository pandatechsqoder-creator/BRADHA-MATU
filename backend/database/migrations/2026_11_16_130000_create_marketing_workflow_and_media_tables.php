<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cms_page_versions')) {
            Schema::create('cms_page_versions', function (Blueprint $table): void {
                $table->id();
                $table->ulid('uuid')->unique();
                $table->foreignId('page_id')->constrained('cms_pages')->cascadeOnDelete();
                $table->unsignedInteger('version_no');
                $table->string('status')->default('draft');
                $table->json('snapshot');
                $table->string('change_summary')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('published_at')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->unique(['page_id', 'version_no']);
                $table->index(['page_id', 'created_at']);
            });
        }

        if (! Schema::hasColumn('cms_pages', 'draft_slug')) {
            Schema::table('cms_pages', function (Blueprint $table): void {
                $table->string('draft_slug')->nullable()->after('slug');
            });
        }

        if (! Schema::hasTable('media_folders')) {
            Schema::create('media_folders', function (Blueprint $table): void {
                $table->id();
                $table->ulid('uuid')->unique();
                $table->foreignId('parent_id')->nullable()->constrained('media_folders')->nullOnDelete();
                $table->string('name');
                $table->string('slug');
                $table->string('path');
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->unique(['parent_id', 'slug']);
                $table->index('path');
            });
        }

        if (! Schema::hasTable('media_assets')) {
            Schema::create('media_assets', function (Blueprint $table): void {
                $table->id();
                $table->ulid('uuid')->unique();
                $table->foreignId('folder_id')->nullable()->constrained('media_folders')->nullOnDelete();
                $table->string('disk')->default('public');
                $table->string('path');
                $table->string('filename');
                $table->string('mime', 120);
                $table->string('extension', 12);
                $table->unsignedBigInteger('size_bytes');
                $table->unsignedInteger('width')->nullable();
                $table->unsignedInteger('height')->nullable();
                $table->string('checksum', 64)->unique();
                $table->unsignedInteger('version')->default(1);
                $table->string('alt_text')->nullable();
                $table->string('caption')->nullable();
                $table->text('description')->nullable();
                $table->json('focal_point')->nullable();
                $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();
                $table->index('folder_id');
                $table->index('mime');
                $table->index('created_at');
            });
        }

        if (! Schema::hasTable('media_asset_versions')) {
            Schema::create('media_asset_versions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('media_asset_id')->constrained('media_assets')->cascadeOnDelete();
                $table->unsignedInteger('version');
                $table->string('path');
                $table->unsignedBigInteger('size_bytes');
                $table->string('checksum', 64);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('created_at')->nullable();
                $table->unique(['media_asset_id', 'version']);
            });
        }

        if (! Schema::hasTable('media_usages')) {
            Schema::create('media_usages', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('media_asset_id')->constrained('media_assets')->cascadeOnDelete();
                $table->string('usable_type');
                $table->unsignedBigInteger('usable_id');
                $table->string('field');
                $table->timestamp('created_at')->nullable();
                $table->index(['usable_type', 'usable_id']);
                $table->index('media_asset_id');
            });
        }

        if (! Schema::hasTable('cms_banners')) {
            Schema::create('cms_banners', function (Blueprint $table): void {
                $table->id();
                $table->ulid('uuid')->unique();
                $table->string('key')->default('site');
                $table->string('title');
                $table->text('body')->nullable();
                $table->foreignId('media_asset_id')->nullable()->constrained('media_assets')->nullOnDelete();
                $table->string('link_url')->nullable();
                $table->timestamp('starts_at')->nullable();
                $table->timestamp('ends_at')->nullable();
                $table->boolean('is_active')->default(false);
                $table->timestamps();
                $table->index(['key', 'is_active']);
            });
        }

        if (! Schema::hasTable('cms_ctas')) {
            Schema::create('cms_ctas', function (Blueprint $table): void {
                $table->id();
                $table->ulid('uuid')->unique();
                $table->string('key')->unique();
                $table->string('label');
                $table->string('url');
                $table->string('form_type')->nullable();
                $table->string('style')->default('primary');
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('cms_faqs')) {
            Schema::create('cms_faqs', function (Blueprint $table): void {
                $table->id();
                $table->ulid('uuid')->unique();
                $table->foreignId('page_id')->nullable()->constrained('cms_pages')->nullOnDelete();
                $table->string('category')->nullable();
                $table->string('question');
                $table->text('answer');
                $table->unsignedInteger('sort_order')->default(0);
                $table->boolean('is_visible')->default(true);
                $table->timestamps();
                $table->index(['page_id', 'sort_order']);
            });
        }

        if (! Schema::hasTable('cms_testimonials')) {
            Schema::create('cms_testimonials', function (Blueprint $table): void {
                $table->id();
                $table->ulid('uuid')->unique();
                $table->foreignId('page_id')->nullable()->constrained('cms_pages')->nullOnDelete();
                $table->text('quote');
                $table->string('author_name');
                $table->string('author_role')->nullable();
                $table->string('business_name')->nullable();
                $table->foreignId('media_asset_id')->nullable()->constrained('media_assets')->nullOnDelete();
                $table->boolean('is_approved')->default(false);
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
                $table->index(['page_id', 'sort_order']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cms_testimonials');
        Schema::dropIfExists('cms_faqs');
        Schema::dropIfExists('cms_ctas');
        Schema::dropIfExists('cms_banners');
        Schema::dropIfExists('media_usages');
        Schema::dropIfExists('media_asset_versions');
        Schema::dropIfExists('media_assets');
        Schema::dropIfExists('media_folders');
        Schema::dropIfExists('cms_page_versions');

        if (Schema::hasColumn('cms_pages', 'draft_slug')) {
            Schema::table('cms_pages', function (Blueprint $table): void {
                $table->dropColumn('draft_slug');
            });
        }
    }
};
