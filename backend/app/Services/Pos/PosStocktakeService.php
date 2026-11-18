<?php

namespace App\Services\Pos;

use App\Models\PosSale;
use App\Models\PosShift;
use App\Models\PrepaidAgreement;
use App\Models\StocktakeJob;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class PosStocktakeService
{
    public function checkCatalogueReadiness(Tenant $tenant): array
    {
        $minimum = (int) config('stocktake.min_products_for_commissioning', 5);
        $productCount = $tenant->products()->count();
        $ready = $productCount >= $minimum;
        $prepaidEligible = $this->activePrepaidAgreement($tenant) !== null;

        return [
            'ready' => $ready,
            'product_count' => $productCount,
            'minimum_required' => $minimum,
            'blocks' => $ready ? [] : ['insufficient_products'],
            'prepaid_eligible' => $prepaidEligible,
            'can_commission' => $ready || $prepaidEligible,
        ];
    }

    public function commissionStocktake(Tenant $tenant, User $user, array $data): StocktakeJob
    {
        $agreement = $this->resolvePrepaidAgreement($tenant, $data['prepaid_agreement_id'] ?? null);

        if ($agreement === null) {
            $readiness = $this->checkCatalogueReadiness($tenant);
            if (! $readiness['ready']) {
                throw ValidationException::withMessages([
                    'catalogue_readiness' => [
                        'Catalogue must have at least ' . $readiness['minimum_required'] . ' products for stock-taking commissioning.',
                    ],
                ]);
            }
        }

        $existing = StocktakeJob::where('tenant_id', $tenant->id)
            ->where('pos_commissioned', true)
            ->whereIn('status', ['commissioned_pending_authorisation', 'in_progress'])
            ->exists();

        if ($existing) {
            throw ValidationException::withMessages([
                'job' => ['An open POS commissioned stock-take already exists. Authorise or decline it first.'],
            ]);
        }

        $startsAt = $data['starts_at'] ?? now()->toIso8601String();
        $durationHours = (int) ($data['estimated_duration_hours'] ?? 4);

        return StocktakeJob::create([
            'tenant_id' => $tenant->id,
            'reference' => 'POS-' . Str::upper(Str::random(8)),
            'job_type' => 'on_demand',
            'title' => $data['title'],
            'description' => $data['authorisation_notes'] ?? null,
            'status' => $agreement !== null ? 'commissioned_authorised' : 'commissioned_pending_authorisation',
            'priority' => 'normal',
            'pos_commissioned' => true,
            'pos_vendor_reference' => $data['pos_reference'] ?? null,
            'prepaid_agreement_id' => $agreement?->id,
            'authorised_by' => null,
            'authorised_at' => null,
            'authorisation_notes' => $data['authorisation_notes'] ?? null,
            'scope' => $data['scope_config'] ?? null,
            'mode' => $data['mode'] ?? 'proposed',
            'frequency' => $data['frequency'] ?? 'on_demand',
            'recurrence_config' => $data['recurrence_config'] ?? null,
            'starts_at' => $startsAt,
            'ends_at' => $data['ends_at'] ?? null,
            'required_team_size' => (int) ($data['minimum_team_size'] ?? 1),
            'expected_duration_minutes' => $durationHours * 60,
            'created_by' => $user->id,
        ]);
    }

    public function authorizeStocktakeJob(StocktakeJob $job, Tenant $tenant, User $user): StocktakeJob
    {
        $this->assertJobTenant($job, $tenant);

        if ($job->status !== 'commissioned_pending_authorisation') {
            throw ValidationException::withMessages(['job' => ['Job is not in pending authorisation state.']]);
        }

        $job->update([
            'status' => 'commissioned_authorised',
            'authorised_by' => $user->id,
            'authorised_at' => now(),
        ]);

        return $job->fresh();
    }

    public function declineStocktakeJob(StocktakeJob $job, Tenant $tenant, User $user, string $reason): StocktakeJob
    {
        $this->assertJobTenant($job, $tenant);

        if (! in_array($job->status, ['commissioned_pending_authorisation', 'commissioned_authorised'], true)) {
            throw ValidationException::withMessages(['job' => ['Job cannot be declined in its current state.']]);
        }

        $job->update([
            'status' => 'cancelled',
            'cancellation_reason' => $reason,
            'cancelled_by' => $user->id,
            'cancelled_at' => now(),
        ]);

        return $job->fresh();
    }

    public function listJobs(Tenant $tenant): array
    {
        return StocktakeJob::where('tenant_id', $tenant->id)
            ->where('pos_commissioned', true)
            ->with(['prepaidAgreement', 'authorisedBy'])
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn (StocktakeJob $job) => $this->jobPayload($job))
            ->all();
    }

    public function generateQuoteFromPos(PosSale $sale, Tenant $tenant): array
    {
        abort_unless($sale->tenant_id === $tenant->id, 404);

        $subtotal = (float) $sale->total_amount;
        $taxRate = (float) config('tax.rate', 0.15);
        $taxAmount = round($subtotal * $taxRate, 2);
        $discountAmount = (float) ($sale->discount_amount ?? 0);

        return [
            'quote_number' => 'POS-QT-' . Str::upper(Str::random(6)),
            'quote_version' => 1,
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'discount_amount' => $discountAmount,
            'total' => round($subtotal + $taxAmount - $discountAmount, 2),
            'currency' => $sale->currency,
            'valid_until' => now()->addDays(7)->toIso8601String(),
            'issued_at' => now()->toIso8601String(),
            'items' => $sale->items->map(fn ($item) => [
                'product_name' => $item->product_name,
                'sku' => $item->sku,
                'barcode' => $item->barcode,
                'quantity' => (float) $item->quantity,
                'unit' => $item->unit,
                'unit_price' => (float) $item->unit_price,
                'line_total' => (float) $item->line_total,
                'tax_amount' => (float) $item->tax_amount,
                'discount_amount' => (float) $item->discount_amount,
            ])->all(),
        ];
    }

    public function fetchPosStocktakeEligibility(Tenant $tenant): array
    {
        $readiness = $this->checkCatalogueReadiness($tenant);
        $existingCommissioned = StocktakeJob::where('tenant_id', $tenant->id)
            ->where('pos_commissioned', true)
            ->whereIn('status', ['commissioned_pending_authorisation', 'in_progress'])
            ->count();
        $activeShift = PosShift::where('tenant_id', $tenant->id)->where('status', 'open')->exists();

        $blocks = $readiness['blocks'];
        if ($existingCommissioned > 0) {
            $blocks[] = 'existing_commissioned_jobs';
        }

        return [
            'catalogue_readiness' => $readiness['ready'],
            'product_count' => $readiness['product_count'],
            'minimum_required' => $readiness['minimum_required'],
            'has_active_shift' => $activeShift,
            'existing_commissioned_jobs' => $existingCommissioned,
            'prepaid_eligible' => $readiness['prepaid_eligible'],
            'can_commission' => $readiness['can_commission'] && $existingCommissioned === 0,
            'blocks' => $blocks,
        ];
    }

    public function jobPayload(StocktakeJob $job): array
    {
        return [
            'job_id' => $job->uuid,
            'reference' => $job->reference,
            'title' => $job->title,
            'status' => $job->status,
            'mode' => $job->mode,
            'frequency' => $job->frequency,
            'pos_commissioned' => (bool) $job->pos_commissioned,
            'pos_vendor_reference' => $job->pos_vendor_reference,
            'prepaid_agreement_id' => $job->prepaidAgreement?->uuid,
            'authorised_by' => $job->authorisedBy?->name,
            'authorised_at' => $job->authorised_at?->toIso8601String(),
            'cancellation_reason' => $job->cancellation_reason,
            'created_at' => $job->created_at?->toIso8601String(),
        ];
    }

    private function activePrepaidAgreement(Tenant $tenant): ?PrepaidAgreement
    {
        return PrepaidAgreement::where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->where('valid_until', '>=', now())
            ->first();
    }

    private function resolvePrepaidAgreement(Tenant $tenant, ?string $uuid): ?PrepaidAgreement
    {
        if ($uuid === null || $uuid === '') {
            return null;
        }

        $agreement = PrepaidAgreement::where('tenant_id', $tenant->id)
            ->where('uuid', $uuid)
            ->where('status', 'active')
            ->where('valid_until', '>=', now())
            ->first();

        if (! $agreement) {
            throw ValidationException::withMessages(['prepaid_agreement_id' => ['Prepaid agreement not found or expired.']]);
        }

        return $agreement;
    }

    private function assertJobTenant(StocktakeJob $job, Tenant $tenant): void
    {
        if ($job->tenant_id !== $tenant->id) {
            abort(404);
        }
    }
}
