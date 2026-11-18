<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('registration_attempts', function (Blueprint $table): void {
            $table->dropUnique('registration_attempts_owner_email_unique');
            $table->string('request_hash', 255)->nullable()->after('idempotency_key');
            $table->timestamp('domain_accepted_at')->nullable()->after('requested_slug');
            $table->timestamp('expires_at')->nullable()->after('verification_expires_at');
            $table->string('active_owner_email')->nullable()
                ->storedAs("CASE WHEN status = 'verification_pending' THEN owner_email ELSE NULL END")
                ->unique();
            $table->index(['status', 'expires_at'], 'registration_attempts_status_expires_idx');
        });
    }

    public function down(): void
    {
        $hasDuplicateEmails = DB::table('registration_attempts')
            ->select('owner_email')
            ->groupBy('owner_email')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasDuplicateEmails) {
            throw new RuntimeException('Cannot restore unique registration emails while registration history contains repeated addresses.');
        }

        Schema::table('registration_attempts', function (Blueprint $table): void {
            $table->dropIndex('registration_attempts_status_expires_idx');
            $table->dropUnique('registration_attempts_active_owner_email_unique');
            $table->dropColumn('active_owner_email');
            $table->dropColumn(['request_hash', 'domain_accepted_at', 'expires_at']);
            $table->unique('owner_email');
        });
    }
};
