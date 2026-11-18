<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('marketing_redirects')) {
            Schema::create('marketing_redirects', function (Blueprint $table): void {
                $table->id();
                $table->ulid('uuid')->unique();
                $table->string('from_path', 500)->unique();
                $table->string('to_path', 500);
                $table->unsignedSmallInteger('status_code')->default(301);
                $table->foreignId('page_id')->nullable()->constrained('cms_pages')->nullOnDelete();
                $table->string('reason', 255)->nullable();
                $table->boolean('is_active')->default(true);
                $table->unsignedBigInteger('hits')->default(0);
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index('is_active');
                $table->index('page_id');
            });
        }

        if (! Schema::hasTable('seo_core_web_vitals')) {
            Schema::create('seo_core_web_vitals', function (Blueprint $table): void {
                $table->id();
                $table->string('path', 500);
                $table->string('url', 500);
                $table->string('device', 20)->default('unknown');
                $table->decimal('lcp', 9, 2)->nullable();
                $table->decimal('cls', 9, 4)->nullable();
                $table->decimal('inp', 9, 2)->nullable();
                $table->decimal('ttfb', 9, 2)->nullable();
                $table->json('metric')->nullable();
                $table->string('user_agent', 500)->nullable();
                $table->string('ip', 45)->nullable();
                $table->timestamp('measured_at');
                $table->timestamp('created_at')->nullable();
                $table->index(['path', 'measured_at']);
                $table->index('measured_at');
                $table->index('device');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_core_web_vitals');
        Schema::dropIfExists('marketing_redirects');
    }
};
