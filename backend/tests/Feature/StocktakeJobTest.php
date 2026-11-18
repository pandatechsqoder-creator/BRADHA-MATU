<?php

namespace Tests\Feature;

use App\Models\Payment;
use App\Models\StocktakeJob;
use App\Models\Tenant;
use App\Models\TenantSetting;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StocktakeJobTest extends TestCase
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

    private function platformUser(string $role = 'operations-manager'): User
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
     * @return array{request_declaration: array{confirm_accuracy: bool, exceptions: string}}
     */
    private function requestDeclarationPayload(): array
    {
        return [
            'request_declaration' => [
                'confirm_accuracy' => true,
                'exceptions' => 'No known exclusions.',
            ],
        ];
    }

    /**
     * @return array{tenant: Tenant, owner: User, branch: string, location: string}
     */
    private function readyTenant(): array
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

        return ['tenant' => $tenant, 'owner' => $owner, 'branch' => $branch['id'], 'location' => $location['id']];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function requestPayload(array $branch, array $location, array $overrides = []): array
    {
        return [
            'title' => 'Quarterly stock take',
            'description' => 'Full count of the main store before month end.',
            'job_type' => 'full',
            'priority' => 'high',
            'count_method' => 'quantity',
            'preferred_start_at' => now()->addDays(3)->toIso8601String(),
            'deadline_at' => now()->addDays(10)->toIso8601String(),
            'branch_ids' => [$branch['id']],
            'stock_location_ids' => [$location['id']],
            'required_team_size' => 3,
            'required_skills' => ['barcode counting'],
            'required_evidence' => ['signed count sheets'],
            'safety_requirements' => 'Closed-toe shoes required.',
            ...$overrides,
        ];
    }

    public function test_full_request_deposit_review_quote_and_acceptance_flow(): void
    {
        ['tenant' => $tenant, 'owner' => $owner, 'branch' => $branch, 'location' => $location] = $this->readyTenant();
        $reviewer = $this->platformUser();
        $finance = $this->platformUser('finance-manager');

        $estimate = $this->as($owner, $tenant)
            ->postJson("/api/v1/tenants/{$tenant->uuid}/stocktake-jobs/estimate", [
                'job_type' => 'full',
                'priority' => 'high',
                'required_team_size' => 3,
                'branch_ids' => [$branch],
                'stock_location_ids' => [$location],
            ])
            ->assertOk()
            ->assertJsonPath('data.catalogue.ready', true)
            ->assertJsonPath('data.counts.skus', 1)
            ->json('data');

        $this->assertGreaterThan(0, $estimate['estimate']['total']);
        $this->assertGreaterThan(0, $estimate['deposit']['amount']);

        $job = $this->as($owner, $tenant)
            ->postJson("/api/v1/tenants/{$tenant->uuid}/stocktake-jobs", $this->requestPayload(['id' => $branch], ['id' => $location]))
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.catalogue.product_count', 1)
            ->assertJsonPath('data.scope.branches.0.name', 'Nairobi HQ')
            ->assertJsonPath('data.internal', null)
            ->json('data');

        $this->assertStringStartsWith('STJ-', $job['reference']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'stocktake.job.created']);

        $submitted = $this->as($owner, $tenant)
            ->postJson("/api/v1/tenants/{$tenant->uuid}/stocktake-jobs/{$job['id']}/submit", $this->requestDeclarationPayload())
            ->assertOk()
            ->assertJsonPath('data.status', 'awaiting_deposit')
            ->assertJsonPath('data.request_declaration.revision', 1)
            ->assertJsonPath('data.request_declaration.catalogue_version_number', 1)
            ->json('data');
        $this->assertDatabaseHas('stocktake_request_declarations', [
            'tenant_id' => $tenant->id,
            'job_id' => StocktakeJob::where('uuid', $job['id'])->value('id'),
            'revision' => 1,
            'exceptions' => 'No known exclusions.',
        ]);

        $depositAmount = (float) $submitted['deposit']['amount'];
        $this->assertGreaterThan(0, $depositAmount);
        $this->assertSame('required', $submitted['deposit']['status']);
        $this->assertArrayNotHasKey('worker_compensation_budget', $submitted['quote'] ?? []);

        $depositPayment = $this->as($owner, $tenant)
            ->postJson("/api/v1/tenants/{$tenant->uuid}/stocktake-jobs/{$job['id']}/deposits", [
                'amount' => $depositAmount,
                'method' => 'mpesa',
                'phone' => '254712345678',
                'reference' => 'MPESA-QK7Y2',
            ])
            ->assertCreated()
            ->assertJsonPath('data.job.status', 'deposit_pending_verification')
            ->assertJsonPath('data.payment.status', 'processing')
            ->json('data');

        $this->as($owner, $tenant)
            ->postJson("/api/v1/tenants/{$tenant->uuid}/stocktake-jobs/{$job['id']}/payments/{$depositPayment['payment']['id']}/simulate", [
                'outcome' => 'success',
            ])
            ->assertOk()
            ->assertJsonPath('data.result.status', 'accepted')
            ->assertJsonPath('data.job.status', 'submitted')
            ->assertJsonPath('data.job.deposit.status', 'paid')
            ->assertJsonPath('data.job.deposit.paid_amount', $depositAmount);

        $this->assertDatabaseHas('financial_journals', ['source_type' => 'payment']);

        $this->asPlatform($reviewer)
            ->postJson("/api/v1/platform/stocktake-jobs/{$job['id']}/review")
            ->assertOk()
            ->assertJsonPath('data.status', 'under_review');

        $quoteResponse = $this->asPlatform($reviewer)
            ->postJson("/api/v1/platform/stocktake-jobs/{$job['id']}/quotes", [
                'base_service_charge' => 10000,
                'scope_complexity_charge' => 2000,
                'travel_expenses' => 500,
                'other_expenses' => 200,
                'discount' => 700,
                'worker_compensation_budget' => 5000,
                'worker_compensation_breakdown' => [['label' => 'Three stock takers', 'amount' => 5000]],
                'internal_notes' => 'Margin is thin; do not share.',
                'notes' => 'Valid for seven days.',
                'deposit_amount' => $depositAmount,
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'awaiting_quote_acceptance')
            ->assertJsonPath('data.quote.total', 12000)
            ->assertJsonPath('data.internal.worker_compensation_budget', 5000)
            ->assertJsonPath('data.internal.margin_amount', 7000)
            ->json('data');

        $this->assertSame(7000.0, (float) $quoteResponse['internal']['platform_fee']);
        $this->assertArrayHasKey('internal_notes', $quoteResponse['quote']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'stocktake.quote.issued']);

        $tenantView = $this->as($owner, $tenant)
            ->getJson("/api/v1/tenants/{$tenant->uuid}/stocktake-jobs/{$job['id']}")
            ->assertOk()
            ->assertJsonPath('data.status', 'awaiting_quote_acceptance')
            ->assertJsonPath('data.quote.total', 12000)
            ->json('data');

        $this->assertArrayNotHasKey('worker_compensation_budget', $tenantView['quote']);
        $this->assertArrayNotHasKey('worker_compensation_breakdown', $tenantView['quote']);
        $this->assertArrayNotHasKey('platform_fee', $tenantView['quote']);
        $this->assertArrayNotHasKey('margin_amount', $tenantView['quote']);
        $this->assertArrayNotHasKey('internal_notes', $tenantView['quote']);
        $this->assertNull($tenantView['internal']);

        $accepted = $this->as($owner, $tenant)
            ->postJson("/api/v1/tenants/{$tenant->uuid}/stocktake-jobs/{$job['id']}/quote/accept")
            ->assertOk()
            ->assertJsonPath('data.status', 'awaiting_balance_payment')
            ->assertJsonPath('data.quote.status', 'accepted')
            ->json('data');

        $this->assertSame(round(12000 - $depositAmount, 2), (float) $accepted['quote']['balance_due']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'stocktake.quote.accepted']);
        $this->assertDatabaseHas('stocktake_job_status_history', [
            'job_id' => StocktakeJob::withoutGlobalScope('tenant')->where('uuid', $job['id'])->value('id'),
            'to_status' => 'awaiting_quote_acceptance',
        ]);
    }

    public function test_submission_requires_and_records_a_request_specific_declaration(): void
    {
        ['tenant' => $tenant, 'owner' => $owner, 'branch' => $branch, 'location' => $location] = $this->readyTenant();
        $job = $this->as($owner, $tenant)
            ->postJson("/api/v1/tenants/{$tenant->uuid}/stocktake-jobs", $this->requestPayload(['id' => $branch], ['id' => $location]))
            ->assertCreated()
            ->json('data');

        $this->as($owner, $tenant)
            ->postJson("/api/v1/tenants/{$tenant->uuid}/stocktake-jobs/{$job['id']}/submit")
            ->assertUnprocessable()
            ->assertJsonStructure(['error' => ['details' => ['request_declaration.confirm_accuracy']]]);

        $this->as($owner, $tenant)
            ->postJson("/api/v1/tenants/{$tenant->uuid}/stocktake-jobs/{$job['id']}/submit", [
                'request_declaration' => ['confirm_accuracy' => false],
            ])
            ->assertUnprocessable();
        $this->assertDatabaseCount('stocktake_request_declarations', 0);

        $this->as($owner, $tenant)
            ->postJson("/api/v1/tenants/{$tenant->uuid}/stocktake-jobs/{$job['id']}/submit", [
                'request_declaration' => [
                    'confirm_accuracy' => true,
                    'exceptions' => 'Back room excluded by tenant.',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.request_declaration.revision', 1)
            ->assertJsonPath('data.request_declaration.exceptions', 'Back room excluded by tenant.')
            ->assertJsonCount(1, 'data.request_declaration_history')
            ->assertJsonPath('data.request_declaration.declared_by', $owner->name);

        $this->assertDatabaseHas('stocktake_request_declarations', [
            'tenant_id' => $tenant->id,
            'job_id' => StocktakeJob::where('uuid', $job['id'])->value('id'),
            'revision' => 1,
            'exceptions' => 'Back room excluded by tenant.',
        ]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'stocktake.request.declared']);
    }

    public function test_tenant_override_deposit_policy_and_fixed_platform_policy(): void
    {
        ['tenant' => $tenant, 'owner' => $owner, 'branch' => $branch, 'location' => $location] = $this->readyTenant();
        $finance = $this->platformUser('finance-manager');

        TenantSetting::create([
            'tenant_id' => $tenant->id,
            'key' => 'stocktake.deposit_policy',
            'value' => ['type' => 'percentage', 'value' => 50, 'minimum' => 2000, 'currency' => 'KES'],
        ]);

        $estimate = $this->as($owner, $tenant)
            ->postJson("/api/v1/tenants/{$tenant->uuid}/stocktake-jobs/estimate", [
                'branch_ids' => [$branch],
                'stock_location_ids' => [$location],
            ])
            ->assertOk()
            ->json('data');

        $this->assertSame('percentage', $estimate['deposit']['type']);
        $this->assertSame(50.0, (float) $estimate['deposit']['value']);
        $this->assertSame(round($estimate['estimate']['total'] * 0.5, 2), (float) $estimate['deposit']['amount']);

        $this->asPlatform($finance)
            ->patchJson('/api/v1/platform/stocktake-policy', [
                'deposit_type' => 'fixed',
                'deposit_value' => 7500,
                'deposit_minimum' => 0,
            ])
            ->assertOk()
            ->assertJsonPath('data.deposit_type', 'fixed')
            ->assertJsonPath('data.deposit_value', 7500);

        $this->assertDatabaseHas('audit_logs', ['action' => 'stocktake.policy.updated']);

        $tenantWithoutOverride = Tenant::create(['name' => 'Beta Traders', 'status' => 'active']);
        $betaOwner = $this->memberOf($tenantWithoutOverride);
        $betaBranch = $this->as($betaOwner, $tenantWithoutOverride)
            ->postJson("/api/v1/tenants/{$tenantWithoutOverride->uuid}/branches", ['name' => 'Beta HQ'])
            ->assertCreated()->json('data');

        $betaEstimate = $this->as($betaOwner, $tenantWithoutOverride)
            ->postJson("/api/v1/tenants/{$tenantWithoutOverride->uuid}/stocktake-jobs/estimate", [
                'branch_ids' => [$betaBranch['id']],
            ])
            ->assertOk()
            ->json('data');

        $this->assertSame(7500.0, (float) $betaEstimate['deposit']['amount']);
    }

    public function test_tenant_isolation_workflow_guards_and_amendments(): void
    {
        ['tenant' => $tenant, 'owner' => $owner, 'branch' => $branch, 'location' => $location] = $this->readyTenant();
        $reviewer = $this->platformUser();
        $finance = $this->platformUser('finance-manager');

        $job = $this->as($owner, $tenant)
            ->postJson("/api/v1/tenants/{$tenant->uuid}/stocktake-jobs", $this->requestPayload(['id' => $branch], ['id' => $location]))
            ->assertCreated()->json('data');

        $this->as($owner, $tenant)
            ->patchJson("/api/v1/tenants/{$tenant->uuid}/stocktake-jobs/{$job['id']}", ['status' => 'approved_for_execution'])
            ->assertOk()
            ->assertJsonPath('data.status', 'draft');

        $otherTenant = Tenant::create(['name' => 'Other Co', 'status' => 'active']);
        $otherOwner = $this->memberOf($otherTenant);

        $this->as($otherOwner, $otherTenant)
            ->getJson("/api/v1/tenants/{$otherTenant->uuid}/stocktake-jobs/{$job['id']}")
            ->assertNotFound();

        $this->as($otherOwner, $otherTenant)
            ->getJson("/api/v1/tenants/{$otherTenant->uuid}/stocktake-jobs")
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $branchManager = $this->memberOf($tenant, 'branch-manager');
        $this->as($branchManager, $tenant)
            ->postJson("/api/v1/tenants/{$tenant->uuid}/stocktake-jobs/{$job['id']}/quote/accept")
            ->assertForbidden();

        $submitted = $this->as($owner, $tenant)
            ->postJson("/api/v1/tenants/{$tenant->uuid}/stocktake-jobs/{$job['id']}/submit", $this->requestDeclarationPayload())
            ->assertOk()->json('data');

        $depositAmount = (float) $submitted['deposit']['amount'];
        $this->as($owner, $tenant)
            ->postJson("/api/v1/tenants/{$tenant->uuid}/stocktake-jobs/{$job['id']}/deposits", [
                'amount' => $depositAmount,
                'method' => 'bank',
                'reference' => 'BANK-9001',
            ])->assertCreated();

        $payment = Payment::withoutGlobalScope('tenant')->latest('id')->firstOrFail();
        $this->asPlatform($finance)
            ->postJson("/api/v1/platform/finance/payments/{$payment->uuid}/verify", [
                'decision' => 'confirm',
                'reference' => 'BANK-9001',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'succeeded');

        $this->asPlatform($reviewer)
            ->postJson("/api/v1/platform/stocktake-jobs/{$job['id']}/quotes", [
                'base_service_charge' => 9000,
                'worker_compensation_budget' => 4000,
            ])
            ->assertCreated();

        $this->as($owner, $tenant)
            ->postJson("/api/v1/tenants/{$tenant->uuid}/stocktake-jobs/{$job['id']}/quote/accept")
            ->assertOk()
            ->assertJsonPath('data.status', 'awaiting_balance_payment');

        $this->as($owner, $tenant)
            ->postJson("/api/v1/tenants/{$tenant->uuid}/stocktake-jobs/{$job['id']}/amendments", [
                'reason' => 'Add a second stock location to the count.',
                'changes' => ['required_team_size' => 5, 'title' => 'Quarterly stock take (expanded)'],
            ])
            ->assertCreated()
            ->assertJsonPath('data.amendment.status', 'pending');

        $amendment = StocktakeJob::withoutGlobalScope('tenant')
            ->where('uuid', $job['id'])->firstOrFail()
            ->amendments()->firstOrFail();

        $this->asPlatform($reviewer)
            ->postJson("/api/v1/platform/stocktake-jobs/{$job['id']}/amendments/{$amendment->uuid}/decision", [
                'decision' => 'approve',
                'reason' => 'Additional team member approved.',
                'financial_impact' => 2500,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'under_review')
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.quote.status', 'superseded')
            ->assertJsonPath('data.required_team_size', 5);

        $this->assertDatabaseHas('audit_logs', ['action' => 'stocktake.amendment.approved']);
        $this->assertDatabaseHas('stocktake_job_amendments', ['status' => 'approved', 'financial_impact' => 2500]);
    }

    public function test_tenant_can_edit_and_resubmit_when_platform_requests_information(): void
    {
        ['tenant' => $tenant, 'owner' => $owner, 'branch' => $branch, 'location' => $location] = $this->readyTenant();
        $reviewer = $this->platformUser();
        $finance = $this->platformUser('finance-manager');

        $job = $this->as($owner, $tenant)
            ->postJson("/api/v1/tenants/{$tenant->uuid}/stocktake-jobs", $this->requestPayload(['id' => $branch], ['id' => $location]))
            ->assertCreated()->json('data');

        $submitted = $this->as($owner, $tenant)
            ->postJson("/api/v1/tenants/{$tenant->uuid}/stocktake-jobs/{$job['id']}/submit", $this->requestDeclarationPayload())
            ->assertOk()->json('data');

        $depositPayment = $this->as($owner, $tenant)
            ->postJson("/api/v1/tenants/{$tenant->uuid}/stocktake-jobs/{$job['id']}/deposits", [
                'amount' => (float) $submitted['deposit']['amount'],
                'method' => 'mpesa',
                'phone' => '254712345678',
                'reference' => 'MPESA-INFO1',
            ])->assertCreated()->json('data');

        $this->as($owner, $tenant)
            ->postJson("/api/v1/tenants/{$tenant->uuid}/stocktake-jobs/{$job['id']}/payments/{$depositPayment['payment']['id']}/simulate", [
                'outcome' => 'success',
            ])
            ->assertOk()
            ->assertJsonPath('data.job.status', 'submitted');

        $this->asPlatform($reviewer)
            ->postJson("/api/v1/platform/stocktake-jobs/{$job['id']}/review")
            ->assertOk();

        $this->asPlatform($reviewer)
            ->postJson("/api/v1/platform/stocktake-jobs/{$job['id']}/request-information", [
                'reason' => 'Clarify which warehouse aisle is included in the count.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'needs_tenant_information');

        $this->as($owner, $tenant)
            ->getJson("/api/v1/tenants/{$tenant->uuid}/stocktake-jobs/{$job['id']}")
            ->assertOk()
            ->assertJsonPath('data.permissions.can_edit', true)
            ->assertJsonPath('data.permissions.can_submit', true);

        $this->as($owner, $tenant)
            ->patchJson("/api/v1/tenants/{$tenant->uuid}/stocktake-jobs/{$job['id']}", [
                'description' => 'Full count of the main store, including the rear aisle.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'needs_tenant_information')
            ->assertJsonPath('data.description', 'Full count of the main store, including the rear aisle.');

        $this->as($owner, $tenant)
            ->postJson("/api/v1/tenants/{$tenant->uuid}/stocktake-jobs/{$job['id']}/submit", $this->requestDeclarationPayload())
            ->assertOk()
            ->assertJsonPath('data.status', 'submitted');

        $this->assertDatabaseHas('audit_logs', ['action' => 'stocktake.job.information_requested']);
    }

    public function test_cancellation_records_fee_and_refund_and_blocks_without_permission(): void
    {
        ['tenant' => $tenant, 'owner' => $owner, 'branch' => $branch, 'location' => $location] = $this->readyTenant();
        $reader = $this->memberOf($tenant, 'tenant-read-only');

        $job = $this->as($owner, $tenant)
            ->postJson("/api/v1/tenants/{$tenant->uuid}/stocktake-jobs", $this->requestPayload(['id' => $branch], ['id' => $location]))
            ->assertCreated()->json('data');

        $this->as($reader, $tenant)
            ->postJson("/api/v1/tenants/{$tenant->uuid}/stocktake-jobs/{$job['id']}/cancel", ['reason' => 'No longer required.'])
            ->assertForbidden();

        $cancelled = $this->as($owner, $tenant)
            ->postJson("/api/v1/tenants/{$tenant->uuid}/stocktake-jobs/{$job['id']}/cancel", [
                'reason' => 'Stock take postponed until next quarter.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->json('data');

        $this->assertSame(0.0, (float) $cancelled['cancellation']['deposit_paid']);
        $this->assertSame(0.0, (float) $cancelled['cancellation']['refund_due']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'stocktake.job.cancelled']);
    }
}
