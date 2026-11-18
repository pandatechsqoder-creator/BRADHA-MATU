<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'must_change_password')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->boolean('must_change_password')->default(false)->after('password');
            });
        }

        if (! Schema::hasTable('registration_attempts')) {
            Schema::create('registration_attempts', function (Blueprint $table): void {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->string('idempotency_key')->nullable()->unique();
            $table->string('business_name');
            $table->string('display_name')->nullable();
            $table->string('industry')->nullable();
            $table->string('country', 2)->nullable();
            $table->string('county')->nullable();
            $table->string('owner_name');
            $table->string('owner_email')->unique();
            $table->string('owner_phone')->nullable();
            $table->string('password_hash');
            $table->string('requested_slug', 63);
            $table->string('status')->default('verification_pending');
            $table->timestamp('verification_expires_at')->nullable();
            $table->timestamp('last_code_sent_at')->nullable();
            $table->unsignedSmallInteger('verification_attempts')->default(0);
            $table->unsignedSmallInteger('resend_count')->default(0);
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'created_at']);
            });
        }

        if (! Schema::hasTable('registration_verification_codes')) {
            Schema::create('registration_verification_codes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('registration_attempt_id')->constrained('registration_attempts')->cascadeOnDelete();
            $table->string('code_hash');
            $table->string('status')->default('pending');
            $table->timestamp('expires_at');
            $table->unsignedSmallInteger('failed_attempts')->default(0);
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
            $table->index(['registration_attempt_id', 'status'], 'reg_verification_attempt_status_idx');
            });
        }

        if (! Schema::hasTable('registration_events')) {
            Schema::create('registration_events', function (Blueprint $table): void {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->foreignId('registration_attempt_id')->nullable()->constrained('registration_attempts')->cascadeOnDelete();
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('event');
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['registration_attempt_id', 'event']);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('registration_verification_codes')) {
            Schema::table('registration_verification_codes', function (Blueprint $table): void {
                $table->dropIndex('reg_verification_attempt_status_idx');
            });
        }

        Schema::dropIfExists('registration_events');
        Schema::dropIfExists('registration_verification_codes');
        Schema::dropIfExists('registration_attempts');

        if (Schema::hasColumn('users', 'must_change_password')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropColumn('must_change_password');
            });
        }
    }
};
