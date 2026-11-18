<?php

namespace App\Services\Marketing\Google;

use App\Exceptions\MarketingIntegrationException;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

final class GoogleOAuthService
{
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';

    public function configured(): bool
    {
        return filled(config('services.google.client_id'))
            && filled(config('services.google.client_secret'))
            && filled(config('services.google.redirect_uri'));
    }

    /**
     * @return list<string>
     */
    public function scopes(): array
    {
        return array_values((array) config('services.google.scopes', []));
    }

    /**
     * Build the consent-screen URL and keep the PKCE verifier server-side.
     */
    public function authorizationUrl(User $user): string
    {
        $this->ensureConfigured();

        $state = Str::random(48);
        $verifier = Str::random(96);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        Cache::put($this->stateKey($state), [
            'user_id' => $user->id,
            'verifier' => $verifier,
        ], now()->addMinutes(10));

        return self::AUTH_URL.'?'.http_build_query([
            'client_id' => config('services.google.client_id'),
            'redirect_uri' => config('services.google.redirect_uri'),
            'response_type' => 'code',
            'scope' => implode(' ', $this->scopes()),
            'access_type' => 'offline',
            'include_granted_scopes' => 'true',
            'prompt' => 'consent',
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @return array{user_id:int, verifier:string}|null
     */
    public function consumeState(string $state): ?array
    {
        if ($state === '') {
            return null;
        }

        $payload = Cache::pull($this->stateKey($state));

        return is_array($payload) && isset($payload['user_id'], $payload['verifier']) ? $payload : null;
    }

    /**
     * @return array{access_token:string, refresh_token?:string, expires_at:string, token_type?:string}
     */
    public function exchange(string $code, string $verifier): array
    {
        $this->ensureConfigured();

        $response = Http::asForm()->timeout(20)->post(self::TOKEN_URL, [
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'code' => $code,
            'code_verifier' => $verifier,
            'grant_type' => 'authorization_code',
            'redirect_uri' => config('services.google.redirect_uri'),
        ]);

        if (! $response->successful() || ! $response->json('access_token')) {
            throw new MarketingIntegrationException(
                'GOOGLE_OAUTH_FAILED',
                'Google sign-in could not be completed. Check the OAuth client, redirect URI and scopes, then try again.',
            );
        }

        return $this->normalizeTokens($response->json());
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @return array{access_token:string, refresh_token?:string, expires_at:string, token_type?:string}
     */
    public function refresh(array $credentials): array
    {
        $this->ensureConfigured();

        $refreshToken = $credentials['refresh_token'] ?? null;
        if (! is_string($refreshToken) || $refreshToken === '') {
            throw new MarketingIntegrationException(
                'GOOGLE_REAUTH_REQUIRED',
                'The Google connection has expired and cannot refresh. Reconnect the account.',
                409,
            );
        }

        $response = Http::asForm()->timeout(20)->post(self::TOKEN_URL, [
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ]);

        if (! $response->successful() || ! $response->json('access_token')) {
            throw new MarketingIntegrationException(
                'GOOGLE_REAUTH_REQUIRED',
                'The Google connection has expired. Reconnect the account to continue syncing.',
                409,
            );
        }

        $tokens = $this->normalizeTokens($response->json());
        $tokens['refresh_token'] ??= $refreshToken;

        return $tokens;
    }

    /**
     * @return array<string, mixed>
     */
    public function userInfo(string $accessToken): array
    {
        $response = Http::withToken($accessToken)->timeout(15)
            ->get('https://openidconnect.googleapis.com/v1/userinfo');

        return $response->successful() && is_array($response->json()) ? $response->json() : [];
    }

    public function revoke(string $token): void
    {
        try {
            Http::asForm()->timeout(10)->post(self::REVOKE_URL, ['token' => $token]);
        } catch (\Throwable) {
            // Revocation is best effort; local credentials are cleared regardless.
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{access_token:string, refresh_token?:string, expires_at:string, token_type?:string}
     */
    private function normalizeTokens(array $payload): array
    {
        $expiresIn = (int) ($payload['expires_in'] ?? 3600);

        return [
            'access_token' => (string) $payload['access_token'],
            'refresh_token' => isset($payload['refresh_token']) ? (string) $payload['refresh_token'] : null,
            'expires_at' => Carbon::now()->addSeconds(max(60, $expiresIn))->toIso8601String(),
            'token_type' => (string) ($payload['token_type'] ?? 'Bearer'),
        ];
    }

    private function ensureConfigured(): void
    {
        if (! $this->configured()) {
            throw new MarketingIntegrationException(
                'GOOGLE_NOT_CONFIGURED',
                'Google integration is not configured. An owner must set GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET and GOOGLE_REDIRECT_URI.',
                422,
            );
        }
    }

    private function stateKey(string $state): string
    {
        return 'marketing:google:oauth:'.hash('sha256', $state);
    }
}
