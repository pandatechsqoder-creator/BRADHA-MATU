<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const ACCOUNTS = [
        ['1000', 'M-Pesa clearing', 'asset', 'debit'],
        ['1010', 'Card clearing', 'asset', 'debit'],
        ['1020', 'Bank clearing', 'asset', 'debit'],
        ['1030', 'Cash / manual clearing', 'asset', 'debit'],
        ['1100', 'Tenant receivables', 'asset', 'debit'],
        ['2000', 'Tenant deposits / customer funds', 'liability', 'credit'],
        ['2100', 'Refund liability', 'liability', 'credit'],
        ['2200', 'Unearned job revenue', 'liability', 'credit'],
        ['4000', 'Platform service revenue', 'revenue', 'credit'],
        ['4100', 'Catalogue revenue', 'revenue', 'credit'],
    ];

    public function up(): void
    {
        Schema::create('financial_accounts', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 120);
            $table->string('type', 20); // asset | liability | equity | revenue | expense
            $table->string('normal_balance', 10); // debit | credit
            $table->string('description', 255)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('financial_journals', function (Blueprint $table): void {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->string('reference', 40)->unique();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('job_id')->nullable()->constrained('stocktake_jobs')->nullOnDelete();
            $table->string('source_type', 60);
            $table->string('source_id', 60)->nullable();
            $table->string('currency', 3)->default('KES');
            $table->date('transaction_date');
            $table->timestamp('posted_at');
            $table->string('description', 255);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reversal_of_id')->nullable()->constrained('financial_journals')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['source_type', 'source_id']);
            $table->index(['tenant_id', 'posted_at']);
            $table->index(['job_id', 'posted_at']);
        });

        Schema::create('financial_journal_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('journal_id')->constrained('financial_journals')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('financial_accounts');
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('job_id')->nullable()->constrained('stocktake_jobs')->nullOnDelete();
            $table->string('currency', 3)->default('KES');
            $table->decimal('debit', 14, 2)->default(0);
            $table->decimal('credit', 14, 2)->default(0);
            $table->string('memo', 255)->nullable();
            $table->index(['account_id', 'journal_id']);
        });

        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_id')->constrained('stocktake_jobs')->cascadeOnDelete();
            $table->foreignId('quote_id')->nullable()->constrained('stocktake_job_quotes')->nullOnDelete();
            $table->string('purpose', 20); // deposit | balance
            $table->string('method', 20); // mpesa | card | bank | manual
            $table->string('provider', 40); // mpesa_sandbox | card_sandbox | bank | manual
            $table->decimal('amount', 14, 2);
            $table->string('currency', 3)->default('KES');
            $table->string('status', 20)->default('pending'); // pending | processing | succeeded | failed | cancelled
            $table->string('internal_reference', 40)->unique();
            $table->string('provider_reference', 80)->nullable()->unique();
            $table->string('idempotency_key', 80)->nullable();
            $table->string('phone', 30)->nullable();
            $table->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('succeeded_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('failure_reason', 255)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'idempotency_key']);
            $table->index(['tenant_id', 'status']);
            $table->index(['job_id', 'purpose']);
            $table->index(['provider', 'status']);
        });

        Schema::create('payment_attempts', function (Blueprint $table): void {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->foreignId('payment_id')->constrained('payments')->cascadeOnDelete();
            $table->string('provider', 40);
            $table->string('status', 20); // initiated | processing | succeeded | failed
            $table->string('provider_reference', 80)->nullable();
            $table->json('request')->nullable();
            $table->json('response')->nullable();
            $table->string('error', 255)->nullable();
            $table->timestamp('attempted_at');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['payment_id', 'created_at']);
        });

        Schema::create('payment_callbacks', function (Blueprint $table): void {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->string('provider', 40);
            $table->string('callback_id', 120);
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->string('provider_reference', 80)->nullable();
            $table->decimal('amount', 14, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->string('status', 20); // accepted | rejected | ignored
            $table->boolean('signature_valid')->default(false);
            $table->json('payload')->nullable();
            $table->timestamp('processed_at');
            $table->string('note', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['provider', 'callback_id']);
            $table->index(['payment_id', 'status']);
        });

        Schema::create('payment_allocations', function (Blueprint $table): void {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_id')->constrained('payments')->cascadeOnDelete();
            $table->string('target_type', 20); // deposit | balance
            $table->unsignedBigInteger('target_id')->nullable();
            $table->decimal('amount', 14, 2);
            $table->string('currency', 3)->default('KES');
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['tenant_id', 'target_type', 'target_id']);
        });

        Schema::create('refunds', function (Blueprint $table): void {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_id')->constrained('payments')->cascadeOnDelete();
            $table->foreignId('job_id')->nullable()->constrained('stocktake_jobs')->nullOnDelete();
            $table->decimal('amount', 14, 2);
            $table->string('currency', 3)->default('KES');
            $table->string('status', 20)->default('requested'); // requested | approved | processing | completed | failed | cancelled
            $table->string('reason', 500);
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->string('provider_reference', 100)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->string('failure_reason', 255)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('financial_reconciliations', function (Blueprint $table): void {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->string('reference', 40)->unique();
            $table->string('provider', 40);
            $table->string('currency', 3)->default('KES');
            $table->date('period_start');
            $table->date('period_end');
            $table->string('status', 20)->default('draft'); // balanced | variance
            $table->decimal('statement_total', 14, 2)->default(0);
            $table->decimal('payment_total', 14, 2)->default(0);
            $table->decimal('ledger_total', 14, 2)->default(0);
            $table->decimal('difference', 14, 2)->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestamps();
        });

        Schema::create('financial_reconciliation_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('reconciliation_id')->constrained('financial_reconciliations')->cascadeOnDelete();
            $table->string('provider_reference', 80);
            $table->decimal('amount', 14, 2);
            $table->string('status', 20); // matched | unmatched | missing | amount_mismatch
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->string('note', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        foreach (self::ACCOUNTS as [$code, $name, $type, $normal]) {
            DB::table('financial_accounts')->insert([
                'code' => $code,
                'name' => $name,
                'type' => $type,
                'normal_balance' => $normal,
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->migrateLegacyDepositPayments();

        Schema::drop('stocktake_deposit_payments');
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_reconciliation_items');
        Schema::dropIfExists('financial_reconciliations');
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('payment_allocations');
        Schema::dropIfExists('payment_callbacks');
        Schema::dropIfExists('payment_attempts');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('financial_journal_lines');
        Schema::dropIfExists('financial_journals');
        Schema::dropIfExists('financial_accounts');
    }

    private function migrateLegacyDepositPayments(): void
    {
        if (! Schema::hasTable('stocktake_deposit_payments')) {
            return;
        }

        $accounts = DB::table('financial_accounts')->pluck('id', 'code');
        $clearing = ['mpesa' => '1000', 'card' => '1010', 'bank' => '1020', 'manual' => '1030'];

        foreach (DB::table('stocktake_deposit_payments')->orderBy('id')->get() as $legacy) {
            $deposit = DB::table('stocktake_deposits')->where('id', $legacy->deposit_id)->first();
            if ($deposit === null) {
                continue;
            }

            $succeeded = $legacy->status === 'confirmed';
            $paymentId = DB::table('payments')->insertGetId([
                'uuid' => $legacy->uuid ?: (string) Str::ulid(),
                'tenant_id' => $legacy->tenant_id,
                'job_id' => $deposit->job_id,
                'quote_id' => $deposit->quote_id,
                'purpose' => 'deposit',
                'method' => $legacy->method,
                'provider' => $legacy->method.'_legacy',
                'amount' => $legacy->amount,
                'currency' => $deposit->currency,
                'status' => $succeeded ? 'succeeded' : 'failed',
                'internal_reference' => 'PAY-LEGACY-'.Str::upper(substr((string) ($legacy->uuid ?: Str::ulid()), -6)),
                'provider_reference' => null,
                'idempotency_key' => null,
                'initiated_by' => $legacy->recorded_by,
                'succeeded_at' => $succeeded ? ($legacy->confirmed_at ?? $legacy->created_at) : null,
                'failed_at' => $succeeded ? null : $legacy->created_at,
                'failure_reason' => $succeeded ? null : 'Migrated from the Phase 6 deposit ledger.',
                'metadata' => json_encode([
                    'legacy_uuid' => $legacy->uuid,
                    'reference' => $legacy->reference,
                    'notes' => $legacy->notes,
                    'migrated_at' => now()->toIso8601String(),
                ]),
                'created_at' => $legacy->created_at,
                'updated_at' => $legacy->updated_at,
            ]);

            DB::table('payment_allocations')->insert([
                'uuid' => (string) Str::ulid(),
                'tenant_id' => $legacy->tenant_id,
                'payment_id' => $paymentId,
                'target_type' => 'deposit',
                'target_id' => $deposit->id,
                'amount' => $legacy->amount,
                'currency' => $deposit->currency,
                'applied_at' => $succeeded ? ($legacy->confirmed_at ?? $legacy->created_at) : null,
                'created_at' => $legacy->created_at,
            ]);

            if (! $succeeded) {
                continue;
            }

            $journalId = DB::table('financial_journals')->insertGetId([
                'uuid' => (string) Str::ulid(),
                'reference' => 'JRN-LEGACY-'.Str::upper(substr((string) $legacy->uuid, -6)),
                'tenant_id' => $legacy->tenant_id,
                'job_id' => $deposit->job_id,
                'source_type' => 'legacy_deposit_payment',
                'source_id' => $legacy->uuid,
                'currency' => $deposit->currency,
                'transaction_date' => substr((string) ($legacy->confirmed_at ?? $legacy->created_at), 0, 10),
                'posted_at' => $legacy->confirmed_at ?? $legacy->created_at,
                'description' => 'Migrated deposit payment '.$legacy->method,
                'actor_id' => $legacy->confirmed_by,
                'created_at' => $legacy->confirmed_at ?? $legacy->created_at,
            ]);

            $lines = [
                ['account' => $clearing[$legacy->method] ?? '1030', 'debit' => $legacy->amount],
                ['account' => '2000', 'credit' => $legacy->amount],
            ];

            foreach ($lines as $line) {
                DB::table('financial_journal_lines')->insert([
                    'journal_id' => $journalId,
                    'account_id' => $accounts[$line['account']],
                    'tenant_id' => $legacy->tenant_id,
                    'job_id' => $deposit->job_id,
                    'currency' => $deposit->currency,
                    'debit' => $line['debit'] ?? 0,
                    'credit' => $line['credit'] ?? 0,
                    'memo' => 'Deposit received',
                ]);
            }
        }
    }
};
