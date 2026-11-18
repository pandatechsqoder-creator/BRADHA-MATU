<?php

namespace App\Services\Tenancy;

use App\Models\DomainAllocationLog;
use App\Models\DomainReservation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class DomainEngine
{
    /**
     * @param  list<array{slug: string, subdomain: string, available: bool}>  $suggestions
     */
    public function __construct(private readonly DomainNameNormalizer $normalizer) {}

    public function normalize(string $businessName): string
    {
        return $this->normalizer->normalize($businessName);
    }

    public function suggestions(string $businessName, ?string $location = null): array
    {
        $words = preg_split('/[^a-z0-9]+/i', Str::ascii(mb_strtolower(trim($businessName), 'UTF-8'))) ?: [];
        $stopWords = ['the', 'a', 'an', 'of', 'and', 'co', 'company', 'limited', 'ltd', 'inc', 'international'];
        $meaningful = array_values(array_filter($words, fn (string $word) => $word !== '' && ! in_array($word, $stopWords, true)));

        $descriptorWords = ['supermarket', 'shop', 'mart', 'retail', 'market', 'store', 'supplies', 'services', 'company', 'limited', 'ltd'];
        $baseWords = [];
        foreach ($meaningful as $word) {
            if (in_array($word, $descriptorWords, true)) {
                break;
            }
            $baseWords[] = $word;
            if (count($baseWords) >= 3) {
                break;
            }
        }

        $base = $this->safeNormalize(implode('', $baseWords));
        $full = $this->safeNormalize(implode('', $words));

        $locationSlug = $location === null
            ? ''
            : $this->safeNormalize(preg_replace('/[^a-z0-9]+/i', '', $location) ?: '');
        $descriptors = array_values(array_filter(
            (array) config('tenancy.suggestion_descriptors', []),
            fn (mixed $descriptor): bool => is_string($descriptor) && preg_match('/^[a-z0-9]+$/', $descriptor) === 1,
        ));

        $candidates = [$base, $full];
        for ($wordCount = 2; $wordCount <= min(3, count($meaningful)); $wordCount++) {
            $candidates[] = $this->safeNormalize(implode('', array_slice($meaningful, 0, $wordCount)));
        }
        foreach ($descriptors as $descriptor) {
            $candidates[] = $base.$descriptor;
        }
        if ($locationSlug !== '') {
            $candidates[] = $base.$locationSlug;
        }

        $numberLimit = max(2, min((int) config('tenancy.suggestion_number_limit', 10), 100));
        for ($number = 2; $number <= $numberLimit; $number++) {
            $candidates[] = $base.$number;
        }

        $ranked = [];
        foreach (array_unique($candidates) as $candidate) {
            $candidate = substr(preg_replace('/[^a-z0-9]/', '', strtolower($candidate)) ?: '', 0, $this->normalizer->maximumLength());
            if (strlen($candidate) < $this->normalizer->minimumLength()) {
                continue;
            }

            $ranked[] = [
                'slug' => $candidate,
                'subdomain' => $candidate.'.'.config('tenancy.base_domain', 'communitygroup.co.ke'),
                'available' => $this->isAvailable($candidate),
            ];
        }

        return array_values(array_filter($ranked, fn (array $item) => $item['available']));
    }

    public function isAvailable(string $slug): bool
    {
        $slug = strtolower(trim($slug));

        if (
            strlen($slug) < $this->normalizer->minimumLength()
            || strlen($slug) > $this->normalizer->maximumLength()
            || $this->normalizer->isReserved($slug)
        ) {
            return false;
        }
        if (! preg_match('/^[a-z0-9]+$/', $slug)) {
            return false;
        }

        if (DB::table('tenants')->where('slug', $slug)->exists()) {
            return false;
        }
        if (DB::table('tenant_domains')->where('slug', $slug)->exists()) {
            return false;
        }

        return ! DomainReservation::query()
            ->where('slug', $slug)
            ->where('status', 'reserved')
            ->where('expires_at', '>', now())
            ->exists();
    }

    private function safeNormalize(string $value): string
    {
        try {
            $slug = $this->normalizer->normalize($value);
        } catch (InvalidArgumentException) {
            $slug = substr(preg_replace('/[^a-z0-9]/', '', strtolower(Str::ascii($value))) ?: 'business', 0, 63);
        }

        return $slug !== '' ? $slug : 'business';
    }

    public function reserve(
        string $slug,
        string $businessName,
        ?string $ownerEmail = null,
        ?string $attemptUuid = null,
        ?string $ipAddress = null,
        int $durationMinutes = 30,
    ): array {
        $slug = strtolower(trim($slug));

        if (! preg_match('/^[a-z0-9]{2,63}$/', $slug)) {
            throw new InvalidArgumentException('Enter a valid domain containing only letters and numbers.');
        }

        if ($this->normalizer->isReserved($slug)) {
            throw new InvalidArgumentException('This domain name is reserved or prohibited.');
        }

        return DB::transaction(function () use ($slug, $businessName, $ownerEmail, $attemptUuid, $ipAddress, $durationMinutes): array {
            $this->expireReservations();

            if ($attemptUuid !== null) {
                $existingReservation = DomainReservation::query()
                    ->where('registration_attempt_uuid', $attemptUuid)
                    ->where('slug', $slug)
                    ->where('status', 'reserved')
                    ->where('expires_at', '>', now())
                    ->lockForUpdate()
                    ->first();

                if ($existingReservation !== null) {
                    return $this->reservationResponse($existingReservation, $businessName);
                }
            }

            if (! $this->isAvailable($slug)) {
                $suggestions = $this->conflictSuggestions($businessName, $slug);

                return [
                    'reserved' => false,
                    'conflict' => true,
                    'requested_slug' => $slug,
                    'suggestions' => $suggestions,
                ];
            }

            try {
                $reservation = DomainReservation::create([
                    'slug' => $slug,
                    'business_name' => $businessName,
                    'owner_email' => $ownerEmail,
                    'registration_attempt_uuid' => $attemptUuid,
                    'status' => 'reserved',
                    'expires_at' => now()->addMinutes($durationMinutes),
                    'ip_address' => $ipAddress,
                ]);
            } catch (UniqueConstraintViolationException) {
                $suggestions = $this->conflictSuggestions($businessName, $slug);

                return [
                    'reserved' => false,
                    'conflict' => true,
                    'requested_slug' => $slug,
                    'suggestions' => $suggestions,
                ];
            }

            DomainAllocationLog::create([
                'slug' => $slug,
                'action' => 'reserved',
                'source' => 'registration',
                'metadata' => [
                    'reservation_uuid' => $reservation->uuid,
                    'registration_attempt_uuid' => $attemptUuid,
                ],
            ]);

            return $this->reservationResponse($reservation, $businessName);
        });
    }

    public function expireReservations(): int
    {
        return DB::transaction(function (): int {
            $expiredReservations = DomainReservation::query()
                ->where('status', 'reserved')
                ->where('expires_at', '<=', now())
                ->lockForUpdate()
                ->get();

            foreach ($expiredReservations as $reservation) {
                $reservation->update(['status' => 'expired']);

                DomainAllocationLog::create([
                    'slug' => $reservation->slug,
                    'action' => 'reservation_expired',
                    'source' => 'system',
                    'metadata' => [
                        'reservation_uuid' => $reservation->uuid,
                        'registration_attempt_uuid' => $reservation->registration_attempt_uuid,
                    ],
                ]);
            }

            return $expiredReservations->count();
        });
    }

    private function conflictSuggestions(string $businessName, string $requestedSlug): array
    {
        $suggestions = $this->suggestions($businessName);
        $preferredSlug = substr($requestedSlug, 0, max(0, $this->normalizer->maximumLength() - 1)).'2';

        foreach ($suggestions as $index => $suggestion) {
            if ($suggestion['slug'] === $preferredSlug) {
                array_unshift($suggestions, ...array_splice($suggestions, $index, 1));
                break;
            }
        }

        return $suggestions;
    }

    private function reservationResponse(DomainReservation $reservation, string $businessName): array
    {
        return [
            'reserved' => true,
            'conflict' => false,
            'slug' => $reservation->slug,
            'subdomain' => $reservation->slug.'.'.config('tenancy.base_domain', 'communitygroup.co.ke'),
            'expires_at' => $reservation->expires_at->toIso8601String(),
            'suggestions' => $this->suggestions($businessName),
        ];
    }
}
