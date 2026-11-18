<?php

namespace App\Services\Marketing\Meta;

use App\Exceptions\MarketingIntegrationException;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

final class MetaOAuthService
{
    public function configured(): bool
    {
        return filled(config('services.meta.app_id'))
            && filled(config('services.meta.app_secret'))
            && filled(config('services.meta.redirect_uri'));
    }

    /**
     * @return list<string>
     */
    public function scopes(): array
    {
        return array_values((array) config('services.meta.scopes', []));
    }

    public function version(): string
    {
        return (string) config('services.meta.graph_version', 'v21.0');
    }

    public function authorizationUrl(User $user): string
    {
        $this->ensureConfigured();

        $state = Str::random(48);

        Cache::put($this->stateKey($state), [
            'user_id' => $user->id,
        ], now()->addMinutes(10));

        return "https://www.facebook.com/{$this->version()}/dialog/oauth?".http_build_query([
            'client_id' => config('services.meta.app_id'),
            'redirect_uri' => config('services.meta.redirect_uri'),
            'response_type' => 'code',
            'state' => $state,
            'scope' => implode(',', $this->scopes()),
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @return array{user_id:int}|null
     */
    public function consumeState(string $state): ?array
    {
        if ($state === '') {
            return null;
        }

        $payload = Cache::pull($this->stateKey($state));

        return is_array($payload) && isset($payload['user_id']) ? $payload : null;
    }

    /**
     * @return array{access_token:string, expires_at:string, token_type?:string}
     */
    public function exchange(string $code): array
    {
        $this->ensureConfigured();

        $short = Http::timeout(20)->get($this->graphUrl('oauth/access_token'), [
            'client_id' => config('services.meta.app_id'),
            'client_secret' => config('services.meta.app_secret'),
            'redirect_uri' => config('services.meta.redirect_uri'),
            'code' => $code,
        ]);

        $token = $short->json('access_token');
        if (! $short->successful() || ! is_string($token) || $token === '') {
            throw new MarketingIntegrationException(
                'META_OAUTH_FAILED',
                'Meta sign-in could not be completed. Check the app, redirect URI and approved permissions, then try again.',
            );
        }

        $long = Http::timeout(20)->get($this->graphUrl('oauth/access_token'), [
            'grant_type' => 'fb_exchange_token',
            'client_id' => config('services.meta.app_id'),
            'client_secret' => config('services.meta.app_secret'),
            'fb_exchange_token' => $token,
        ]);

        if ($long->successful() && is_string($long->json('access_token'))) {
            return $this->normalizeTokens($long->json());
        }

        return $this->normalizeTokens($short->json());
    }

    /**
     * @return array<string, mixed>
     */
    public function userInfo(string $accessToken): array
    {
        $response = Http::withToken($accessToken)->timeout(15)
            ->get($this->graphUrl('me'), ['fields' => 'id,name,email']);

        return $response->successful() && is_array($response->json()) ? $response->json() : [];
    }

    public function revoke(string $token): void
    {
        try {
            Http::withToken($token)->timeout(10)->delete($this->graphUrl('me/permissions'));
        } catch (\Throwable) {
            // Revocation is best effort; local credentials are cleared regardless.
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{access_token:string, expires_at:string, token_type?:string}
     */
    private function normalizeTokens(array $payload): array
    {
        $expiresIn = (int) ($payload['expires_in'] ?? 3600);

        return [
            'access_token' => (string) $payload['access_token'],
            'expires_at' => Carbon::now()->addSeconds(max(60, $expiresIn))->toIso8601String(),
            'token_type' => (string) ($payload['token_type'] ?? 'Bearer'),
        ];
    }

    private function ensureConfigured(): void
    {
        if (! $this->configured()) {
            throw new MarketingIntegrationException(
                'META_NOT_CONFIGURED',
                'Meta integration is not configured. An owner must set META_APP_ID, META_APP_SECRET and META_REDIRECT_URI.',
                422,
            );
        }
    }

    private function stateKey(string $state): string
    {
        return 'marketing:meta:oauth:'.hash('sha256', $state);
    }

    private function graphUrl(string $path): string
    {
        return "https://graph.facebook.com/{$this->version()}/{$path}";
    }
}
