<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_delivery_logs', function (Blueprint $table): void {
            $table->longText('payload')->nullable()->after('provider_response');
            $table->timestamp('payload_expires_at')->nullable()->after('retry_at');
            $table->index(['status', 'retry_at'], 'email_delivery_status_retry_idx');
        });
    }

    public function down(): void
    {
        Schema::table('email_delivery_logs', function (Blueprint $table): void {
            $table->dropIndex('email_delivery_status_retry_idx');
            $table->dropColumn(['payload', 'payload_expires_at']);
        });
    }
};
