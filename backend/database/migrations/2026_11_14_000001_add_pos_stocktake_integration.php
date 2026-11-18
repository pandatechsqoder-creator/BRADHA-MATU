<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prepaid_agreements', function (Blueprint $table): void {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('reference')->unique();
            $table->decimal('amount', 14, 2);
            $table->string('currency', 3)->default('KES');
            $table->string('status')->default('active');
            $table->timestamp('valid_until');
            $table->text('terms_conditions')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
        });

        Schema::table('stocktake_jobs', function (Blueprint $table): void {
            $table->boolean('pos_commissioned')->default(false)->after('job_type');
            $table->string('pos_vendor_reference')->nullable()->after('pos_commissioned');
            $table->string('mode')->default('proposed')->after('pos_vendor_reference');
            $table->string('frequency')->default('on_demand')->after('mode');
            $table->json('recurrence_config')->nullable()->after('frequency');
            $table->timestamp('starts_at')->nullable()->after('recurrence_config');
            $table->timestamp('ends_at')->nullable()->after('starts_at');
            $table->foreignId('prepaid_agreement_id')->nullable()->constrained('prepaid_agreements')->nullOnDelete();
            $table->foreignId('authorised_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('authorised_at')->nullable();
            $table->text('authorisation_notes')->nullable();
            $table->index(['tenant_id', 'pos_commissioned']);
        });

        Schema::dropIfExists('pos_stocktake_requests');
    }

    public function down(): void
    {
        Schema::table('stocktake_jobs', function (Blueprint $table): void {
            $table->dropForeign(['prepaid_agreement_id']);
            $table->dropForeign(['authorised_by']);
            $table->dropIndex(['tenant_id', 'pos_commissioned']);
            $table->dropColumn([
                'pos_commissioned',
                'pos_vendor_reference',
                'mode',
                'frequency',
                'recurrence_config',
                'starts_at',
                'ends_at',
                'prepaid_agreement_id',
                'authorised_by',
                'authorised_at',
                'authorisation_notes',
            ]);
        });

        Schema::dropIfExists('prepaid_agreements');
    }
};
