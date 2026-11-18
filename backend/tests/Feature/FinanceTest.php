<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Finance\LedgerService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class FinanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    private function memberOf(Tenant $tenant, string $role = 'business-owner'): User
    {
        $user = User::factory()->create();
        $tenant->memberships()->create([
            'user_id' => $user->id,
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $user->assignRole($role, $tenant->id);

        return $user;
    }

    private function platformUser(string $role = 'finance-manager'): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function as(User $user, Tenant $tenant): static
    {
        $this->app->make('auth')->forgetGuards();
        $this->app->forgetScopedInstances();

        return $this->withToken($user->createToken('test')->plainTextToken)
            ->withHeader('X-Tenant-Id', $tenant->uuid);
    }

    private function asPlatform(User $user): static
    {
        $this->app->make('auth')->forgetGuards();
        $this->app->forgetScopedInstances();

        return $this->withToken($user->createToken('test')->plainTextToken);
    }

    /**
     * @return array{tenant: Tenant, owner: User, job: array<string, mixed>}
     */
    private function awaitingDepositJob(): array
    {
        $tenant = Tenant::create(['name' => 'Acme Retail', 'status' => 'active']);
        $owner = $this->memberOf($tenant);

        $branch = $this->as($owner, $tenant)->postJson("/api/v1/tenants/{$tenant->uuid}/branches", [
            'name' => 'Nairobi HQ',
            'code' => 'NRB',
        ])->assertCreated()->json('data');

        $location = $this->as($owner, $tenant)->postJson("/api/v1/tenants/{$tenant->uuid}/stock-locations", [
            'name' => 'Main Store',
            'code' => 'MAIN',
            'branch_id' => $branch['id'],
        ])->assertCreated()->json('data');

        $this->as($owner, $tenant)->postJson("/api/v1/tenants/{$tenant->uuid}/products", [
            'name' => 'Sugar 1kg',
            'sku' => 'SUG-1KG',
            'base_unit' => 'piece',
            'cost_price' => 120,
        ])->assertCreated();

        $summary = $this->as($owner, $tenant)->getJson("/api/v1/tenants/{$tenant->uuid}/catalogue")
            ->assertOk()->json('data');

        $this->as($owner, $tenant)->postJson(
            "/api/v1/tenants/{$tenant->uuid}/catalogues/{$summary['id']}/declarations",
            [
                'declaration_text' => 'I confirm the catalogue products, units and barcodes are accurate.',
                'confirm_accuracy' => true,
            ],
        )->assertCreated();

        $job = $this->as($owner, $tenant)->postJson("/api/v1/tenants/{$tenant->uuid}/stocktake-jobs", [
            'title' => 'Quarterly stock take',
            'job_type' => 'full',
            'priority' => 'high',
            'count_method' => 'quantity',
            'branch_ids' => [$branch['id']],
            'stock_location_ids' => [$location['id']],
            'required_team_size' => 3,
        ])->assertCreated()->json('data');

        $job = $this->as($owner, $tenant)
            ->postJson("/api/v1/tenants/{$tenant->uuid}/stocktake-jobs/{$job['id']}/submit", [
                'request_declaration' => ['confirm_accuracy' => true],
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'awaiting_deposit')
            ->json('data');

        return ['tenant' => $tenant, 'owner' => $owner, 'job' => $job];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function initiatePayment(Tenant $tenant, User $owner, array $job, array $overrides = []): array
    {
        return $this->as($owner, $tenant)
            ->postJson("/api/v1/tenants/{$tenant->uuid}/stocktake-jobs/{$job['id']}/payments", [
                'purpose' => 'deposit',
                'method' => 'mpesa',
                'amount' => (float) $job['deposit']['amount'],
                'phone' => '254712345678',
                ...$overrides,
            ])
            ->assertCreated()
            ->json('data');
    }

    private function signedCallback(array $payload): TestResponse
    {
        $raw = json_encode($payload);
        $signature = hash_hmac('sha256', $raw, (string) config('payments.callbacks.sandbox_secret'));

        return $this->call(
            'POST',
            '/api/v1/payments/mpesa/callback',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_BRATHAMATU_SIGNATURE' => $signature,
            ],
            $raw,
        );
    }

    public function test_sandbox_payment_requires_a_signed_callback_and_is_idempotent(): void
    {
        ['tenant' => $tenant, 'owner' => $owner, 'job' => $job] = $this->awaitingDepositJob();
        $depositAmount = (float) $job['deposit']['amount'];

        $headers = ['Idempotency-Key' => 'deposit-attempt-1'];
        $first = $this->as($owner, $tenant)->withHeaders($headers)
            ->postJson("/api/v1/tenants/{$tenant->uuid}/stocktake-jobs/{$job['id']}/payments", [
                'purpose' => 'deposit',
                'method' => 'mpesa',
                'amount' => $depositAmount,
                'phone' => '254712345678',
            ])->assertCreated()->json('data');

        $second = $this->as($owner, $tenant)->withHeaders($headers)
            ->postJson("/api/v1/tenants/{$tenant->uuid}/stocktake-jobs/{$job['id']}/payments", [
                'purpose' => 'deposit',
                'method' => 'mpesa',
                'amount' => $depositAmount,
                'phone' => '254712345678',
            ])->assertCreated()->json('data');

        $this->assertSame($first['payment']['id'], $second['payment']['id']);
        $this->assertSame(1, Payment::withoutGlobalScope('tenant')->count());
        $this->assertSame('processing', $first['payment']['status']);

        $this->as($owner, $tenant)
            ->getJson("/api/v1/tenants/{$tenant->uuid}/stocktake-jobs/{$job['id']}")
            ->assertOk()
            ->assertJsonPath('data.status', 'deposit_pending_verification')
            ->assertJsonPath('data.deposit.status', 'required');

        $callback = [
            'CheckoutRequestID' => $first['payment']['checkout_reference'],
            'ResultCode' => 0,
            'ResultDesc' => 'The service request is processed successfully.',
            'Amount' => $depositAmount,
            'Currency' => 'KES',
            'MpesaReceiptNumber' => 'SBXTEST001',
            'PhoneNumber' => '254712345678',
        ];

        $this->postJson('/api/v1/payments/mpesa/callback', $callback)
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'rejected');

        $this->assertDatabaseHas('payment_callbacks', ['status' => 'rejected', 'signature_valid' => false]);

        $this->signedCallback($callback)
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'accepted');

        $view = $this->as($owner, $tenant)
            ->getJson("/api/v1/tenants/{$tenant->uuid}/stocktake-jobs/{$job['id']}")
            ->assertOk()
            ->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath('data.deposit.status', 'paid')
            ->assertJsonPath('data.payments.0.status', 'succeeded')
            ->json('data');

        $this->assertEqualsWithDelta($depositAmount, (float) $view['deposit']['paid_amount'], 0.001);

        $trial = app(LedgerService::class)->trialBalance();
        $this->assertTrue($trial['balanced']);

        $deposits = collect($trial['accounts'])->firstWhere('code', '2000');
        $this->assertEqualsWithDelta($depositAmount, (float) $deposits['credit'], 0.001);

        $this->signedCallback($callback)
            ->assertStatus(202)
            ->assertJsonPath('data.duplicate', true);
    }

    public function test_callback_with_wrong_amount_is_rejected_without_settling(): void
    {
        ['tenant' => $tenant, 'owner' => $owner, 'job' => $job] = $this->awaitingDepositJob();
        $payment = $this->initiatePayment($tenant, $owner, $job, ['method' => 'card']);

        $raw = json_encode([
            'session_reference' => $payment['payment']['checkout_reference'],
            'transaction_reference' => 'CARD-TEST-1',
            'amount' => 5,
            'currency' => 'KES',
            'status' => 'captured',
        ]);
        $signature = hash_hmac('sha256', $raw, (string) config('payments.callbacks.sandbox_secret'));

        $this->call('POST', '/api/v1/payments/card/callback', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_BRATHAMATU_SIGNATURE' => $signature,
        ], $raw)->assertStatus(202)->assertJsonPath('data.status', 'rejected');

        $this->as($owner, $tenant)
            ->getJson("/api/v1/tenants/{$tenant->uuid}/stocktake-jobs/{$job['id']}")
            ->assertOk()
            ->assertJsonPath('data.status', 'deposit_pending_verification')
            ->assertJsonPath('data.deposit.status', 'required');

        $this->assertSame('processing', Payment::withoutGlobalScope('tenant')->firstOrFail()->status);
    }

    public function test_balance_payment_clears_the_receivable_and_ledger(): void
    {
        ['tenant' => $tenant, 'owner' => $owner, 'job' => $job] = $this->awaitingDepositJob();
        $reviewer = $this->platformUser('operations-manager');
        $finance = $this->platformUser('finance-manager');

        $depositPayment = $this->initiatePayment($tenant, $owner, $job);

        $this->as($owner, $tenant)
            ->postJson("/api/v1/tenants/{$tenant->uuid}/stocktake-jobs/{$job['id']}/payments/{$depositPayment['payment']['id']}/simulate", [
                'outcome' => 'success',
            ])
            ->assertOk()
            ->assertJsonPath('data.job.status', 'submitted');

        $this->asPlatform($reviewer)
            ->postJson("/api/v1/platform/stocktake-jobs/{$job['id']}/quotes", [
                'base_service_charge' => 10000,
                'worker_compensation_budget' => 4000,
            ])
            ->assertCreated();

        $this->as($owner, $tenant)
            ->postJson("/api/v1/tenants/{$tenant->uuid}/stocktake-jobs/{$job['id']}/quote/accept")
            ->assertOk()
            ->assertJsonPath('data.status', 'awaiting_balance_payment');

        $balance = $this->as($owner, $tenant)
            ->postJson("/api/v1/tenants/{$tenant->uuid}/stocktake-jobs/{$job['id']}/payments", [
                'purpose' => 'balance',
                'method' => 'bank',
            ])
            ->assertCreated()
            ->assertJsonPath('data.job.status', 'payment_pending_verification')
            ->json('data');

        $this->assertTrue($balance['payment']['can_verify']);

        $this->asPlatform($finance)
            ->postJson("/api/v1/platform/finance/payments/{$balance['payment']['id']}/verify", [
                'decision' => 'confirm',
                'reference' => 'BANK-BAL-1001',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'succeeded');

        $this->as($owner, $tenant)
            ->getJson("/api/v1/tenants/{$tenant->uuid}/stocktake-jobs/{$job['id']}")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved_for_execution')
            ->assertJsonPath('data.quote.balance_due', 0)
            ->assertJsonPath('data.balance.due', 0);

        $trial = app(LedgerService::class)->trialBalance();
        $this->assertTrue($trial['balanced']);

        $receivable = collect($trial['accounts'])->firstWhere('code', '1100');
        $this->assertEqualsWithDelta(0.0, (float) $receivable['debit'] - (float) $receivable['credit'], 0.001);

        $this->assertDatabaseHas('financial_journals', ['source_type' => 'quote_accepted']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'finance.payment.settled']);
    }

    public function test_reconciliation_matches_statement_lines_and_flags_variance(): void
    {
        ['tenant' => $tenant, 'owner' => $owner, 'job' => $job] = $this->awaitingDepositJob();
        $finance = $this->platformUser('finance-manager');

        $payment = $this->initiatePayment($tenant, $owner, $job, ['method' => 'bank']);
        $this->asPlatform($finance)
            ->postJson("/api/v1/platform/finance/payments/{$payment['payment']['id']}/verify", [
                'decision' => 'confirm',
                'reference' => 'BANK-REC-1',
            ])
            ->assertOk();

        $matched = $this->asPlatform($finance)
            ->postJson('/api/v1/platform/finance/reconciliations', [
                'provider' => 'bank',
                'period_start' => now()->toDateString(),
                'period_end' => now()->toDateString(),
                'statement_lines' => [
                    ['provider_reference' => 'BANK-REC-1', 'amount' => (float) $job['deposit']['amount']],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'balanced')
            ->assertJsonPath('data.items.0.status', 'matched')
            ->json('data');

        $this->assertSame(0, $matched['exception_count']);
        $this->assertEqualsWithDelta((float) $job['deposit']['amount'], (float) $matched['statement_total'], 0.001);

        $variance = $this->asPlatform($finance)
            ->postJson('/api/v1/platform/finance/reconciliations', [
                'provider' => 'bank',
                'period_start' => now()->toDateString(),
                'period_end' => now()->toDateString(),
                'statement_lines' => [
                    ['provider_reference' => 'BANK-REC-1', 'amount' => 999],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'variance')
            ->assertJsonPath('data.items.0.status', 'amount_mismatch')
            ->json('data');

        $this->assertGreaterThan(0, $variance['exception_count']);
        $this->assertGreaterThan(0.001, abs((float) $variance['difference']));
    }

    public function test_refund_requires_approval_and_posts_a_balanced_reversal(): void
    {
        ['tenant' => $tenant, 'owner' => $owner, 'job' => $job] = $this->awaitingDepositJob();
        $finance = $this->platformUser('finance-manager');

        $payment = $this->initiatePayment($tenant, $owner, $job, ['method' => 'bank']);
        $this->asPlatform($finance)
            ->postJson("/api/v1/platform/finance/payments/{$payment['payment']['id']}/verify", [
                'decision' => 'confirm',
                'reference' => 'BANK-REFUND-1',
            ])
            ->assertOk();

        $refund = $this->asPlatform($finance)
            ->postJson('/api/v1/platform/finance/refunds', [
                'payment_id' => $payment['payment']['id'],
                'amount' => 500,
                'reason' => 'Cancellation fee adjustment for the tenant.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'requested')
            ->json('data');

        $this->asPlatform($finance)
            ->postJson("/api/v1/platform/finance/refunds/{$refund['id']}/decision", [
                'decision' => 'approve',
                'reason' => 'Approved by finance.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->asPlatform($finance)
            ->postJson("/api/v1/platform/finance/refunds/{$refund['id']}/decision", [
                'decision' => 'process',
                'reference' => 'REFUND-MPESA-1',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.provider_reference', 'REFUND-MPESA-1');

        $this->assertDatabaseHas('financial_journals', ['source_type' => 'refund']);

        $trial = app(LedgerService::class)->trialBalance();
        $this->assertTrue($trial['balanced']);

        $deposits = collect($trial['accounts'])->firstWhere('code', '2000');
        $this->assertEqualsWithDelta(500.0, (float) $deposits['debit'], 0.001);

        $this->asPlatform($finance)
            ->getJson('/api/v1/platform/finance/overview')
            ->assertOk()
            ->assertJsonPath('data.ledger.balanced', true)
            ->assertJsonStructure(['data' => ['collected_today', 'awaiting_deposit_jobs', 'ledger', 'by_method']]);

        $this->asPlatform($finance)
            ->getJson('/api/v1/platform/finance/ledger')
            ->assertOk()
            ->assertJsonPath('meta.trial_balance.balanced', true);
    }

    public function test_finance_endpoints_are_permission_guarded(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->awaitingDepositJob();

        $this->as($owner, $tenant)
            ->getJson('/api/v1/platform/finance/overview')
            ->assertForbidden();

        $operations = $this->platformUser('operations-manager');

        $this->asPlatform($operations)
            ->getJson('/api/v1/platform/finance/ledger')
            ->assertForbidden();
    }
}
