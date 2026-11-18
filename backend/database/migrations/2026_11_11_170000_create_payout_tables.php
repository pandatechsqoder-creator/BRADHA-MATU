<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payout_batches', function (Blueprint $table): void {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->string('reference')->unique();
            $table->string('status')->default('draft'); // draft | pending_approval | approved | processing | completed | failed | cancelled
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->string('currency', 3)->default('KES');
            $table->unsignedInteger('payout_count')->default(0);
            $table->foreignId('prepared_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('prepared_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['status']);
        });

        Schema::create('stock_taker_payouts', function (Blueprint $table): void {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->foreignId('profile_id')->constrained('stock_taker_profiles')->cascadeOnDelete();
            $table->foreignId('job_id')->constrained('stocktake_jobs')->cascadeOnDelete();
            $table->foreignId('assignment_id')->nullable()->constrained('job_assignments')->nullOnDelete();
            $table->foreignId('earning_id')->nullable()->constrained('stock_taker_earnings')->nullOnDelete();
            $table->foreignId('batch_id')->nullable()->constrained('payout_batches')->nullOnDelete();
            $table->decimal('amount', 14, 2);
            $table->string('currency', 3)->default('KES');
            $table->string('method'); // mpesa | bank | manual
            $table->string('recipient_identifier')->nullable(); // phone / account number
            $table->string('status')->default('pending'); // pending | processing | completed | failed | cancelled
            $table->string('provider_reference')->nullable();
            $table->string('provider_status')->nullable();
            $table->text('failure_reason')->nullable();
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['profile_id', 'status']);
            $table->index(['batch_id']);
        });

        Schema::create('payout_approvals', function (Blueprint $table): void {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->foreignId('batch_id')->constrained('payout_batches')->cascadeOnDelete();
            $table->foreignId('approver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('decision'); // approve | reject
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payout_approvals');
        Schema::dropIfExists('stock_taker_payouts');
        Schema::dropIfExists('payout_batches');
    }
};
