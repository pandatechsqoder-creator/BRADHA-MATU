<?php

namespace App\Services\Stocktake;

use App\Models\Branch;
use App\Models\Catalogue;
use App\Models\Department;
use App\Models\ProductVariant;
use App\Models\StockLocation;
use App\Models\StockZone;
use App\Models\StocktakeJob;
use App\Models\StocktakeRequestDeclaration;
use App\Models\StocktakeStatusHistory;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class StocktakeRequestService
{
    public function __construct(
        private readonly StocktakePolicyService $policies,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function catalogueReadiness(Tenant $tenant): array
    {
        $catalogue = Catalogue::query()
            ->where('tenant_id', $tenant->id)
            ->latest('id')
            ->first();

        $version = $catalogue?->currentVersion;
        $confirmed = $catalogue?->confirmed_at !== null;
        $productCount = (int) ($version?->product_count ?? 0);

        $reasons = [];
        if ($catalogue === null || $version === null) {
            $reasons[] = 'Add products to create a catalogue version.';
        }
        if ($productCount === 0) {
            $reasons[] = 'Your catalogue has no active products.';
        }
        if ($catalogue !== null && ! $confirmed) {
            $reasons[] = 'Declare that your catalogue is accurate before requesting a stock take.';
        }

        return [
            'id' => $catalogue?->uuid,
            'catalogue_id' => $catalogue?->id,
            'version_id' => $version?->id,
            'version_uuid' => $version?->uuid,
            'version_number' => $version?->version_number,
            'product_count' => $productCount,
            'confirmed' => $confirmed,
            'ready' => $catalogue !== null && $version !== null && $productCount > 0 && $confirmed,
            'reasons' => $reasons,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function scopeSummary(Tenant $tenant, array $data): array
    {
        $branchIds = array_values(array_unique((array) ($data['branch_ids'] ?? [])));
        $locationIds = array_values(array_unique((array) ($data['stock_location_ids'] ?? [])));
        $departmentIds = array_values(array_unique((array) ($data['department_ids'] ?? [])));
        $zoneIds = array_values(array_unique((array) ($data['zone_ids'] ?? [])));

        $branches = $this->resolve(Branch::class, $tenant, $branchIds, 'branch_ids', fn (Branch $branch) => [
            'id' => $branch->uuid, 'name' => $branch->name, 'code' => $branch->code,
        ]);
        $locations = $this->resolve(StockLocation::class, $tenant, $locationIds, 'stock_location_ids', fn (StockLocation $location) => [
            'id' => $location->uuid, 'name' => $location->name, 'code' => $location->code,
            'branch' => $location->branch?->name, 'warehouse' => $location->warehouse?->name,
        ]);
        $departments = $this->resolve(Department::class, $tenant, $departmentIds, 'department_ids', fn (Department $department) => [
            'id' => $department->uuid, 'name' => $department->name, 'code' => $department->code,
        ]);
        $zones = $this->resolve(StockZone::class, $tenant, $zoneIds, 'zone_ids', fn (StockZone $zone) => [
            'id' => $zone->uuid, 'name' => $zone->name, 'code' => $zone->code,
            'location' => $zone->location?->name,
        ]);

        if ($locations === [] && ($branches !== [] || ($departments === [] && $zones === [] && $branchIds === []))) {
            $locations = StockLocation::query()
                ->where('tenant_id', $tenant->id)
                ->when($branchIds !== [], fn ($query) => $query->whereHas('branch', fn ($branch) => $branch->whereIn('uuid', $branchIds)))
                ->with(['branch', 'warehouse'])
                ->get()
                ->map(fn (StockLocation $location) => [
                    'id' => $location->uuid, 'name' => $location->name, 'code' => $location->code,
                    'branch' => $location->branch?->name, 'warehouse' => $location->warehouse?->name,
                ])->values()->all();
        }

        $inferredBranchIds = collect($branches)->pluck('id');
        if ($inferredBranchIds->isEmpty() && $locations !== []) {
            $inferredBranchIds = StockLocation::query()
                ->where('tenant_id', $tenant->id)
                ->whereIn('uuid', collect($locations)->pluck('id'))
                ->with('branch')
                ->get()
                ->pluck('branch.uuid')
                ->filter()
                ->unique()
                ->values();
            $branches = Branch::query()
                ->where('tenant_id', $tenant->id)
                ->whereIn('uuid', $inferredBranchIds)
                ->get()
                ->map(fn (Branch $branch) => ['id' => $branch->uuid, 'name' => $branch->name, 'code' => $branch->code])
                ->values()
                ->all();
        }

        return [
            'branches' => $branches,
            'locations' => $locations,
            'departments' => $departments,
            'zones' => $zones,
            'summary' => [
                'branch_count' => count($branches),
                'location_count' => count($locations),
                'department_count' => count($departments),
                'zone_count' => count($zones),
                'label' => $this->scopeLabel($branches, $locations),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function estimate(Tenant $tenant, array $data): array
    {
        $readiness = $this->catalogueReadiness($tenant);
        $scope = $this->scopeSummary($tenant, $data);
        $skus = $this->countSkus($tenant, $data, $readiness);
        $policy = $this->policies->policy();
        $priority = (string) ($data['priority'] ?? 'normal');
        $multipliers = (array) ($policy->priority_multipliers ?? []);
        $multiplier = (float) ($multipliers[$priority] ?? 1.0);

        $base = (float) $policy->base_charge;
        $skuCharge = round($skus * (float) $policy->per_sku_charge, 2);
        $locationCharge = round($scope['summary']['location_count'] * (float) $policy->per_location_charge, 2);
        $branchCharge = round($scope['summary']['branch_count'] * (float) $policy->per_branch_charge, 2);
        $raw = $base + $skuCharge + $locationCharge + $branchCharge;
        $subtotal = round($raw * $multiplier, 2);
        $tax = round($subtotal * ((float) $policy->tax_rate / 100), 2);
        $total = round($subtotal + $tax, 2);
        $deposit = $this->policies->depositFor($total, $this->policies->depositRules($tenant, (string) ($data['job_type'] ?? 'full')));

        return [
            'catalogue' => [
                'version_number' => $readiness['version_number'],
                'product_count' => $readiness['product_count'],
                'confirmed' => $readiness['confirmed'],
                'ready' => $readiness['ready'],
                'reasons' => $readiness['reasons'],
            ],
            'scope' => $scope,
            'counts' => [
                'skus' => $skus,
                'branches' => $scope['summary']['branch_count'],
                'locations' => $scope['summary']['location_count'],
            ],
            'estimate' => [
                'currency' => $policy->currency,
                'priority' => $priority,
                'priority_multiplier' => $multiplier,
                'base_charge' => $base,
                'sku_charge' => $skuCharge,
                'location_charge' => $locationCharge,
                'branch_charge' => $branchCharge,
                'subtotal' => $subtotal,
                'tax_rate' => (float) $policy->tax_rate,
                'tax' => $tax,
                'total' => $total,
                'provisional' => true,
                'lines' => [
                    ['label' => 'Base service charge', 'amount' => $base],
                    ['label' => $skus.' SKUs in scope', 'amount' => $skuCharge],
                    ['label' => $scope['summary']['location_count'].' stock locations', 'amount' => $locationCharge],
                    ['label' => $scope['summary']['branch_count'].' branches', 'amount' => $branchCharge],
                    ['label' => ucfirst($priority).' priority adjustment', 'amount' => round($subtotal - $raw, 2)],
                ],
            ],
            'deposit' => $deposit,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function create(Tenant $tenant, User $actor, array $data): StocktakeJob
    {
        $readiness = $this->catalogueReadiness($tenant);
        if (! $readiness['ready']) {
            throw ValidationException::withMessages([
                'catalogue' => $readiness['reasons'] ?: ['Your catalogue is not ready for a stock take.'],
            ]);
        }

        $estimate = $this->estimate($tenant, $data);
        $scope = $estimate['scope'];

        return DB::transaction(function () use ($tenant, $actor, $data, $readiness, $estimate, $scope): StocktakeJob {
            $job = StocktakeJob::create([
                'tenant_id' => $tenant->id,
                'reference' => $this->reference(),
                'job_type' => $data['job_type'] ?? 'full',
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'status' => 'draft',
                'priority' => $data['priority'] ?? 'normal',
                'business_criticality' => $data['business_criticality'] ?? null,
                'count_method' => $data['count_method'] ?? 'quantity',
                'expected_duration_minutes' => $data['expected_duration_minutes'] ?? null,
                'preferred_start_at' => $data['preferred_start_at'] ?? null,
                'deadline_at' => $data['deadline_at'] ?? null,
                'scope' => [
                    'branches' => $scope['branches'],
                    'locations' => $scope['locations'],
                    'departments' => $scope['departments'],
                    'zones' => $scope['zones'],
                    'label' => $scope['summary']['label'],
                ],
                'product_scope' => $data['product_scope'] ?? 'all',
                'product_scope_payload' => $data['product_scope_payload'] ?? null,
                'industry_requirements' => $data['industry_requirements'] ?? null,
                'safety_requirements' => $data['safety_requirements'] ?? null,
                'access_requirements' => $data['access_requirements'] ?? null,
                'stock_movement_cutoff' => $data['stock_movement_cutoff'] ?? null,
                'required_team_size' => $data['required_team_size'] ?? 1,
                'required_skills' => $data['required_skills'] ?? null,
                'required_qualifications' => $data['required_qualifications'] ?? null,
                'required_evidence' => $data['required_evidence'] ?? null,
                'signoff_requirements' => $data['signoff_requirements'] ?? null,
                'sop_template' => $data['sop_template'] ?? null,
                'sop_version' => $data['sop_version'] ?? null,
                'custom_instructions' => $data['custom_instructions'] ?? null,
                'attachments' => $data['attachments'] ?? null,
                'catalogue_id' => $readiness['catalogue_id'],
                'catalogue_version_id' => $readiness['version_id'],
                'catalogue_product_count' => $readiness['product_count'],
                'inventory_snapshot_id' => $data['inventory_snapshot_id'] ?? null,
                'provisional_estimate' => $estimate['estimate'],
                'deposit_required' => false,
                'deposit_amount' => 0,
                'currency' => $estimate['estimate']['currency'],
                'created_by' => $actor->id,
            ]);

            StocktakeStatusHistory::create([
                'tenant_id' => $tenant->id,
                'job_id' => $job->id,
                'from_status' => null,
                'to_status' => 'draft',
                'actor_type' => 'tenant',
                'actor_id' => $actor->id,
                'reason' => 'Request created.',
            ]);

            $this->audit->log('stocktake.job.created', $job, null, null, [
                'reference' => $job->reference,
                'title' => $job->title,
                'catalogue_version' => $readiness['version_number'],
            ]);

            return $job->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(StocktakeJob $job, array $data): StocktakeJob
    {
        $allowed = [
            'title', 'description', 'job_type', 'priority', 'business_criticality', 'count_method',
            'expected_duration_minutes', 'preferred_start_at', 'deadline_at', 'product_scope',
            'product_scope_payload', 'industry_requirements', 'safety_requirements', 'access_requirements',
            'stock_movement_cutoff', 'required_team_size', 'required_skills', 'required_qualifications',
            'required_evidence', 'signoff_requirements', 'sop_template', 'sop_version',
            'custom_instructions', 'attachments', 'inventory_snapshot_id',
        ];

        $before = $job->only($allowed);
        $job->fill(array_intersect_key($data, array_flip($allowed)));

        if (array_intersect(array_keys($data), [
            'branch_ids', 'stock_location_ids', 'department_ids', 'zone_ids', 'priority', 'job_type', 'product_scope', 'product_scope_payload',
        ])) {
            $scopeData = [
                ...$data,
                'branch_ids' => $data['branch_ids'] ?? collect($job->scope['branches'] ?? [])->pluck('id')->all(),
                'stock_location_ids' => $data['stock_location_ids'] ?? collect($job->scope['locations'] ?? [])->pluck('id')->all(),
                'department_ids' => $data['department_ids'] ?? collect($job->scope['departments'] ?? [])->pluck('id')->all(),
                'zone_ids' => $data['zone_ids'] ?? collect($job->scope['zones'] ?? [])->pluck('id')->all(),
                'job_type' => $data['job_type'] ?? $job->job_type,
                'priority' => $data['priority'] ?? $job->priority,
                'product_scope' => $data['product_scope'] ?? $job->product_scope,
                'product_scope_payload' => $data['product_scope_payload'] ?? $job->product_scope_payload,
            ];
            $estimate = $this->estimate($job->tenant, $scopeData);
            $job->scope = [
                'branches' => $estimate['scope']['branches'],
                'locations' => $estimate['scope']['locations'],
                'departments' => $estimate['scope']['departments'],
                'zones' => $estimate['scope']['zones'],
                'label' => $estimate['scope']['summary']['label'],
            ];
            $job->provisional_estimate = $estimate['estimate'];
            $job->currency = $estimate['estimate']['currency'];
        }

        $job->save();

        $this->audit->log('stocktake.job.updated', $job, null, $before, $job->only($allowed));

        return $job->fresh();
    }

    /**
     * Record a signed snapshot of the exact request and catalogue version.
     *
     * @param  array{confirm_accuracy: bool, exceptions?: string|null}  $data
     */
    public function declareRequest(StocktakeJob $job, User $actor, array $data, ?string $userAgent, ?string $ipAddress): StocktakeRequestDeclaration
    {
        $catalogueVersionId = $job->catalogue_version_id;
        if ($catalogueVersionId === null || $job->catalogueVersion === null) {
            throw ValidationException::withMessages([
                'catalogue' => ['This request has no catalogue version to declare. Save a new request after the catalogue is ready.'],
            ]);
        }

        $snapshot = [
            'title' => $job->title,
            'job_type' => $job->job_type,
            'scope' => $job->scope,
            'product_scope' => $job->product_scope,
            'product_scope_payload' => $job->product_scope_payload,
            'catalogue_version_id' => $catalogueVersionId,
            'catalogue_product_count' => $job->catalogue_product_count,
            'stock_movement_cutoff' => $job->stock_movement_cutoff,
        ];

        $latest = $job->requestDeclarations()->latest('revision')->first();
        if ($latest !== null && $latest->request_snapshot === $snapshot
            && $latest->catalogue_version_id === $catalogueVersionId
            && $latest->exceptions === ($data['exceptions'] ?? null)) {
            return $latest;
        }

        return StocktakeRequestDeclaration::create([
            'tenant_id' => $job->tenant_id,
            'job_id' => $job->id,
            'revision' => (int) ($latest?->revision ?? 0) + 1,
            'catalogue_version_id' => $catalogueVersionId,
            'declared_by' => $actor->id,
            'declaration_text' => 'I confirm that this stock-taking request scope, selected locations, catalogue version, product selection and stock movement cut-off are accurate and authorized as at submission.',
            'exceptions' => $data['exceptions'] ?? null,
            'request_snapshot' => $snapshot,
            'device' => [
                'user_agent' => substr((string) $userAgent, 0, 500),
                'platform' => 'web',
            ],
            'ip_address' => $ipAddress,
            'accepted_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $readiness
     */
    private function countSkus(Tenant $tenant, array $data, array $readiness): int
    {
        $scope = $data['product_scope'] ?? 'all';
        $payload = array_values((array) ($data['product_scope_payload'] ?? []));

        if ($scope === 'category' && $payload !== []) {
            return ProductVariant::query()
                ->where('tenant_id', $tenant->id)
                ->where('status', 'active')
                ->whereHas('product.category', fn ($query) => $query->whereIn('uuid', $payload))
                ->count();
        }

        if ($scope === 'variant' && $payload !== []) {
            return ProductVariant::query()
                ->where('tenant_id', $tenant->id)
                ->where('status', 'active')
                ->where(function ($query) use ($payload): void {
                    $query->whereIn('uuid', $payload)->orWhereIn('sku', $payload);
                })
                ->count();
        }

        return (int) $readiness['product_count'];
    }

    /**
     * @param  class-string  $model
     * @param  array<int, string>  $ids
     * @return array<int, array<string, mixed>>
     */
    private function resolve(string $model, Tenant $tenant, array $ids, string $field, callable $map): array
    {
        if ($ids === []) {
            return [];
        }

        $records = $model::query()->where('tenant_id', $tenant->id)->whereIn('uuid', $ids)->get();
        if ($records->count() !== count($ids)) {
            throw ValidationException::withMessages([
                $field => ['One or more selected records do not belong to this organisation.'],
            ]);
        }

        return $records->map($map)->values()->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $branches
     * @param  array<int, array<string, mixed>>  $locations
     */
    private function scopeLabel(array $branches, array $locations): string
    {
        if ($locations !== []) {
            return count($locations) === 1
                ? (string) $locations[0]['name']
                : count($locations).' stock locations';
        }

        if ($branches !== []) {
            return count($branches) === 1
                ? (string) $branches[0]['name']
                : count($branches).' branches';
        }

        return 'All stock locations';
    }

    private function reference(): string
    {
        do {
            $reference = 'STJ-'.now()->format('Ymd').'-'.Str::upper(Str::random(6));
        } while (StocktakeJob::withoutGlobalScope('tenant')->where('reference', $reference)->exists());

        return $reference;
    }
}
