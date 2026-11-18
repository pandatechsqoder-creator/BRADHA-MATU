<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stocktake_request_declarations', function (Blueprint $table): void {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_id')->constrained('stocktake_jobs')->cascadeOnDelete();
            $table->unsignedInteger('revision');
            $table->foreignId('catalogue_version_id')->constrained('catalogue_versions')->restrictOnDelete();
            $table->foreignId('declared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('declaration_text');
            $table->text('exceptions')->nullable();
            $table->json('request_snapshot');
            $table->json('device')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('accepted_at');
            $table->timestamps();
            $table->unique(['job_id', 'revision']);
            $table->index(['tenant_id', 'job_id', 'accepted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stocktake_request_declarations');
    }
};
