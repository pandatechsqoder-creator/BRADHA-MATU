<?php

namespace App\Services\Tenancy;

use App\Mail\VerificationCodeMail;
use App\Models\DomainAllocationLog;
use App\Models\DomainReservation;
use App\Models\RegistrationAttempt;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Models\User;
use App\Services\Email\TransactionalEmailService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class TenantRegistrationService
{
    public function __construct(
        private readonly DomainEngine $domains,
        private readonly TransactionalEmailService $emails,
    ) {}

    public function start(array $data, ?string $idempotencyKey = null, ?string $ipAddress = null): array
    {
        $email = strtolower(trim($data['owner_email']));
        $requestHash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));

        if ($idempotencyKey !== null && (trim($idempotencyKey) === '' || strlen($idempotencyKey) > 255)) {
            throw ValidationException::withMessages(['Idempotency-Key' => ['The idempotency key must be between 1 and 255 characters.']]);
        }

        $result = DB::transaction(function () use ($data, $email, $idempotencyKey, $ipAddress, $requestHash): array {
            $this->expireAbandonedRegistrations();

            if ($idempotencyKey !== null) {
                $existing = RegistrationAttempt::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    if (! Hash::check($requestHash, (string) $existing->request_hash)) {
                        throw ValidationException::withMessages([
                            'Idempotency-Key' => ['This idempotency key was already used with different registration details.'],
                        ]);
                    }

                    return ['attempt' => $existing, 'duplicate' => true];
                }
            }

            if (
                User::whereRaw('LOWER(email) = ?', [$email])->exists()
                || RegistrationAttempt::where('active_owner_email', $email)->exists()
            ) {
                throw ValidationException::withMessages(['owner_email' => ['An account already exists for this email address.']]);
            }

            $reservation = $this->domains->reserve(
                $data['slug'],
                $data['business_name'],
                $email,
                null,
                $ipAddress,
                (int) config('tenancy.domain_reservation_minutes', 30),
            );

            if ($reservation['conflict']) {
                return ['conflict' => true, 'suggestions' => $reservation['suggestions']];
            }

            $attempt = RegistrationAttempt::create([
                'idempotency_key' => $idempotencyKey,
                'request_hash' => Hash::make($requestHash),
                'business_name' => $data['business_name'],
                'display_name' => $data['display_name'] ?? null,
                'industry' => $data['industry'] ?? null,
                'country' => $data['country'] ?? null,
                'county' => $data['county'] ?? null,
                'owner_name' => $data['owner_name'],
                'owner_email' => $email,
                'owner_phone' => $data['owner_phone'] ?? null,
                'password_hash' => Hash::make($data['password']),
                'requested_slug' => $reservation['slug'],
                'domain_accepted_at' => now(),
                'status' => 'verification_pending',
                'expires_at' => now()->addMinutes((int) config('tenancy.domain_reservation_minutes', 30)),
            ]);

            $domainReservation = DomainReservation::query()
                ->where('slug', $reservation['slug'])
                ->where('status', 'reserved')
                ->lockForUpdate()
                ->first();

            if ($domainReservation === null || $domainReservation->expires_at->isPast()) {
                throw ValidationException::withMessages([
                    'slug' => ['The selected business link could not be reserved. Please choose another suggestion.'],
                ]);
            }

            $domainReservation->update(['registration_attempt_uuid' => $attempt->uuid]);

            DB::table('registration_events')->insert([
                'uuid' => (string) Str::ulid(),
                'registration_attempt_id' => $attempt->id,
                'event' => 'registration.started',
                'metadata' => json_encode([
                    'slug' => $reservation['slug'],
                    'domain_accepted' => true,
                ], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);

            return [
                'attempt' => $attempt,
                'duplicate' => false,
                'reservation' => $reservation,
            ];
        });

        if (! empty($result['conflict'])) {
            return $result;
        }

        /** @var RegistrationAttempt $attempt */
        $attempt = $result['attempt'];
        if (! $result['duplicate']) {
            $this->issueCode($attempt, true);
        }

        return [
            'conflict' => false,
            'attempt_uuid' => $attempt->uuid,
            'status' => $attempt->status,
            'duplicate' => $result['duplicate'],
            'completed' => $attempt->status === 'completed',
            'tenant_uuid' => $attempt->tenant?->uuid,
            'slug' => $attempt->requested_slug,
            'subdomain' => $attempt->requested_slug.'.'.config('tenancy.base_domain', 'communitygroup.co.ke'),
            'expires_at' => $attempt->verification_expires_at?->toIso8601String(),
        ];
    }

    public function resend(string $attemptUuid): array
    {
        return DB::transaction(function () use ($attemptUuid): array {
            $attempt = RegistrationAttempt::where('uuid', $attemptUuid)->lockForUpdate()->firstOrFail();

            if ($attempt->status !== 'verification_pending' || $attempt->expires_at?->isPast()) {
                throw ValidationException::withMessages(['attempt' => ['This registration is no longer awaiting verification.']]);
            }

            if ($attempt->resend_count >= (int) config('tenancy.registration.max_resends', 5)) {
                throw ValidationException::withMessages(['attempt' => ['The maximum resend limit has been reached.']]);
            }

            if (
                $attempt->last_code_sent_at !== null
                && $attempt->last_code_sent_at->diffInSeconds(now()) < (int) config('tenancy.registration.resend_cooldown_seconds', 60)
            ) {
                throw ValidationException::withMessages(['attempt' => ['Please wait before requesting another code.']]);
            }

            $this->issueCode($attempt, false);

            return ['sent' => true, 'expires_at' => $attempt->refresh()->verification_expires_at?->toIso8601String()];
        });
    }

    public function expireAbandonedRegistrations(): int
    {
        return DB::transaction(function (): int {
            $attempts = RegistrationAttempt::query()
                ->where('status', 'verification_pending')
                ->whereNotNull('expires_at')
                ->where('expires_at', '<=', now())
                ->lockForUpdate()
                ->get();

            foreach ($attempts as $attempt) {
                $attempt->codes()->where('status', 'pending')->update(['status' => 'expired', 'updated_at' => now()]);
                $attempt->forceFill([
                    'status' => 'expired',
                    'idempotency_key' => null,
                    'request_hash' => null,
                ])->save();

                $reservation = DomainReservation::query()
                    ->where('registration_attempt_uuid', $attempt->uuid)
                    ->where('status', 'reserved')
                    ->lockForUpdate()
                    ->first();

                if ($reservation !== null) {
                    $reservation->update(['status' => 'expired']);
                    DomainAllocationLog::create([
                        'slug' => $reservation->slug,
                        'action' => 'reservation_expired',
                        'source' => 'system',
                        'metadata' => [
                            'reservation_uuid' => $reservation->uuid,
                            'registration_attempt_uuid' => $attempt->uuid,
                        ],
                    ]);
                }

                DB::table('registration_events')->insert([
                    'uuid' => (string) Str::ulid(),
                    'registration_attempt_id' => $attempt->id,
                    'event' => 'registration.expired',
                    'metadata' => json_encode(['slug' => $attempt->requested_slug], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                ]);
            }

            return $attempts->count();
        });
    }

    public function verify(string $attemptUuid, string $code): array
    {
        $attempt = RegistrationAttempt::where('uuid', $attemptUuid)->firstOrFail();

        if ($attempt->status === 'completed' && $attempt->tenant_id !== null) {
            return ['completed' => true, 'tenant_uuid' => $attempt->tenant->uuid];
        }

        if ($attempt->status !== 'verification_pending') {
            throw ValidationException::withMessages(['code' => ['This registration is no longer valid.']]);
        }

        if ($attempt->verification_expires_at === null || $attempt->verification_expires_at->isPast()) {
            throw ValidationException::withMessages(['code' => ['This verification code has expired.']]);
        }

        if ($attempt->verification_attempts >= (int) config('tenancy.registration.verification_max_attempts', 5)) {
            throw ValidationException::withMessages(['code' => ['Too many incorrect verification attempts.']]);
        }

        $pendingCode = $attempt->codes()->where('status', 'pending')->latest('id')->first();
        if ($pendingCode === null || $pendingCode->expires_at->isPast()) {
            throw ValidationException::withMessages(['code' => ['This verification code has expired.']]);
        }

        if (! Hash::check($code, $pendingCode->code_hash)) {
            $attempt->increment('verification_attempts');
            $pendingCode->increment('failed_attempts');
            throw ValidationException::withMessages(['code' => ['That verification code is not valid.']]);
        }

        return DB::transaction(function () use ($attempt, $pendingCode): array {
            $attempt = RegistrationAttempt::whereKey($attempt->id)->lockForUpdate()->firstOrFail();

            if ($attempt->status === 'completed') {
                return ['completed' => true, 'tenant_uuid' => $attempt->tenant->uuid];
            }

            $reservation = DB::table('domain_reservations')
                ->where('slug', $attempt->requested_slug)
                ->where('status', 'reserved')
                ->where('expires_at', '>', now())
                ->lockForUpdate()
                ->first();

            if ($reservation === null) {
                throw ValidationException::withMessages(['attempt' => ['The domain reservation has expired. Please select a domain again.']]);
            }

            $user = new User;
            $user->forceFill([
                'name' => $attempt->owner_name,
                'email' => $attempt->owner_email,
                'password' => $attempt->password_hash,
                'email_verified_at' => now(),
                'must_change_password' => false,
            ]);
            $user->save();

            $tenant = Tenant::create([
                'name' => $attempt->display_name ?: $attempt->business_name,
                'legal_name' => $attempt->business_name,
                'slug' => $attempt->requested_slug,
                'industry' => $attempt->industry,
                'email' => $attempt->owner_email,
                'phone' => $attempt->owner_phone,
                'country' => $attempt->country,
                'county' => $attempt->county,
                'timezone' => 'Africa/Nairobi',
                'status' => 'active',
                'created_by' => $user->id,
            ]);

            app(DefaultHeadOfficeProvisioner::class)->provision($tenant);

            Role::firstOrCreate(
                ['slug' => 'business-owner'],
                ['name' => 'Business Owner', 'scope' => 'tenant'],
            );

            $tenant->memberships()->create([
                'user_id' => $user->id,
                'status' => 'active',
                'joined_at' => now(),
            ]);
            $user->assignRole('business-owner', $tenant->id);

            TenantDomain::updateOrCreate(
                ['slug' => $attempt->requested_slug],
                [
                    'tenant_id' => $tenant->id,
                    'subdomain' => $attempt->requested_slug,
                    'status' => 'allocated',
                    'allocated_at' => now(),
                ],
            );

            DB::table('domain_reservations')->where('id', $reservation->id)->update([
                'status' => 'allocated',
                'updated_at' => now(),
            ]);

            DomainAllocationLog::create([
                'tenant_id' => $tenant->id,
                'slug' => $attempt->requested_slug,
                'action' => 'allocated',
                'source' => 'registration',
                'metadata' => ['reservation_uuid' => $reservation->uuid],
            ]);

            $pendingCode->update(['status' => 'used', 'used_at' => now()]);
            $attempt->forceFill([
                'status' => 'completed',
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
            ])->save();

            DB::table('registration_events')->insert([
                'uuid' => (string) Str::ulid(),
                'registration_attempt_id' => $attempt->id,
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'event' => 'registration.completed',
                'metadata' => json_encode(['slug' => $attempt->requested_slug]),
                'created_at' => now(),
            ]);

            return ['completed' => true, 'tenant_uuid' => $tenant->uuid];
        });
    }

    private function issueCode(RegistrationAttempt $attempt, bool $initial): void
    {
        $attempt->codes()->where('status', 'pending')->update(['status' => 'expired', 'updated_at' => now()]);

        $code = (string) random_int(100000, 999999);
        $expiresAt = now()->addMinutes((int) config('tenancy.registration.verification_expiry_minutes', 15));

        $attempt->codes()->create([
            'code_hash' => Hash::make($code),
            'status' => 'pending',
            'expires_at' => $expiresAt,
        ]);

        $attempt->forceFill([
            'verification_expires_at' => $expiresAt,
            'last_code_sent_at' => now(),
            'resend_count' => $initial ? 0 : $attempt->resend_count + 1,
        ])->save();

        $this->emails->queue(
            new VerificationCodeMail($attempt->owner_name, $code),
            $attempt->owner_email,
            'auth.verification',
            ['recipient_name' => $attempt->owner_name, 'code' => $code],
            $expiresAt,
        );
    }
}
