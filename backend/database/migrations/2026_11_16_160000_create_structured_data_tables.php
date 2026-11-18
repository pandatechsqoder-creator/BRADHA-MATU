<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cms_page_structured_data', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('page_id')->unique()->constrained('cms_pages')->cascadeOnDelete();
            $table->json('types')->nullable();
            $table->json('overrides')->nullable();
            $table->timestamps();
        });

        Schema::create('structured_data_validation_runs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('page_id')->constrained('cms_pages')->cascadeOnDelete();
            $table->string('status', 20);
            $table->json('types');
            $table->json('issues');
            $table->longText('json_ld');
            $table->foreignId('validated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->index(['page_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('structured_data_validation_runs');
        Schema::dropIfExists('cms_page_structured_data');
    }
};
