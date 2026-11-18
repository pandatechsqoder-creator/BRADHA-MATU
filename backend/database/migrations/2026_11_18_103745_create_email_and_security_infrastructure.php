<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_provider_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('provider')->default('smtp');
            $table->string('host')->nullable();
            $table->unsignedInteger('port')->nullable();
            $table->string('encryption')->nullable();
            $table->string('username')->nullable();
            $table->text('password')->nullable();
            $table->string('from_address')->nullable();
            $table->string('from_name')->nullable();
            $table->string('reply_to_address')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('email_delivery_logs', function (Blueprint $table): void {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->string('recipient')->index();
            $table->string('template');
            $table->string('status')->default('queued')->index();
            $table->string('provider_message_id')->nullable()->index();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->json('provider_response')->nullable();
            $table->text('failure_reason')->nullable();
            $table->timestamp('queued_at')->useCurrent();
            $table->timestamp('processing_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('retry_at')->nullable();
            $table->timestamps();
        });

        Schema::create('email_provider_events', function (Blueprint $table): void {
            $table->id();
            $table->string('event_id')->unique();
            $table->string('provider_message_id')->nullable()->index();
            $table->string('event_type');
            $table->json('payload');
            $table->timestamp('received_at')->useCurrent();
            $table->timestamps();
        });

        Schema::create('password_change_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('reason');
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_id', 'created_at']);
        });

        Schema::table('invitations', function (Blueprint $table): void {
            $table->unsignedSmallInteger('resend_count')->default(0);
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('invitations', function (Blueprint $table): void {
            $table->dropColumn(['resend_count', 'last_sent_at', 'revoked_at']);
        });

        Schema::dropIfExists('password_change_events');
        Schema::dropIfExists('email_provider_events');
        Schema::dropIfExists('email_delivery_logs');
        Schema::dropIfExists('email_provider_settings');
    }
};
