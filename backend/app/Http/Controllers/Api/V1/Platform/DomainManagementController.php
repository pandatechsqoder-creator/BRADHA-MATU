<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Models\DomainReservation;
use App\Models\ReservedDomainName;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class DomainManagementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = TenantDomain::with('tenant')->latest();
        if ($request->filled('search')) {
            $query->where('slug', 'like', '%'.strtolower($request->string('search')).'%');
        }

        return ApiResponse::success($query->paginate(min((int) $request->integer('per_page', 30), 100)));
    }

    public function reservations(Request $request): JsonResponse
    {
        $query = DomainReservation::query()->latest();
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        return ApiResponse::success($query->paginate(min((int) $request->integer('per_page', 30), 100)));
    }

    public function settings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'base_domain' => ['nullable', 'string', 'max:255'],
            'reservation_minutes' => ['nullable', 'integer', 'between:5,1440'],
            'verification_minutes' => ['nullable', 'integer', 'between:5,1440'],
            'invitation_days' => ['nullable', 'integer', 'between:1,30'],
        ]);

        foreach ($data as $key => $value) {
            DB::table('domain_settings')->updateOrInsert(
                ['key' => $key],
                ['value' => json_encode($value), 'updated_at' => now(), 'created_at' => now()],
            );
        }

        return ApiResponse::success($data);
    }

    public function allocate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tenant_uuid' => ['required', 'string', 'size:26'],
            'slug' => ['required', 'string', 'regex:/^[a-z0-9]{2,63}$/'],
        ]);

        $tenant = Tenant::where('uuid', $data['tenant_uuid'])->first();
        abort_if($tenant === null, 404, 'Tenant not found.');

        if (ReservedDomainName::where('slug', $data['slug'])->where('blocked', true)->exists()) {
            throw ValidationException::withMessages(['slug' => ['This domain name is reserved or prohibited.']]);
        }

        if (TenantDomain::where('slug', $data['slug'])->where('tenant_id', '!=', $tenant->id)->exists()) {
            throw ValidationException::withMessages(['slug' => ['This domain name is already allocated.']]);
        }

        DB::transaction(function () use ($tenant, $data, $request): void {
            $tenant->forceFill(['slug' => $data['slug']])->save();
            TenantDomain::updateOrCreate(
                ['tenant_id' => $tenant->id],
                [
                    'slug' => $data['slug'],
                    'subdomain' => $data['slug'],
                    'status' => 'allocated',
                    'allocated_at' => now(),
                ],
            );
            DB::table('domain_allocation_logs')->insert([
                'tenant_id' => $tenant->id,
                'slug' => $data['slug'],
                'action' => 'manual_allocated',
                'source' => 'super-admin',
                'actor_id' => $request->user()?->id,
                'metadata' => json_encode(['previous_slug' => $tenant->getOriginal('slug')]),
                'created_at' => now(),
            ]);
        });

        return ApiResponse::success(['slug' => $data['slug'], 'subdomain' => $data['slug'].'.'.config('tenancy.base_domain')]);
    }

    public function block(Request $request): JsonResponse
    {
        $data = $request->validate([
            'slug' => ['required', 'string', 'regex:/^[a-z0-9]{2,63}$/'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        ReservedDomainName::updateOrCreate(
            ['slug' => $data['slug']],
            ['reason' => $data['reason'] ?? 'Blocked by super-admin', 'blocked' => true],
        );

        return ApiResponse::success(['blocked' => true, 'slug' => $data['slug']]);
    }
}
