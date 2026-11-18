<?php

namespace App\Services\Marketing\Meta;

use App\Exceptions\MarketingIntegrationException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class MetaGraphClient
{
    public function __construct(private readonly MetaOAuthService $oauth) {}

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function get(string $path, string $token, array $query = []): array
    {
        return $this->request('get', $path, $token, $query);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function post(string $path, string $token, array $body = []): array
    {
        return $this->request('post', $path, $token, $body);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, string $token, array $payload = []): array
    {
        $url = "https://graph.facebook.com/{$this->oauth->version()}/{$path}";
        $client = Http::withToken($token)->acceptJson()->timeout(30);

        try {
            $response = $method === 'post'
                ? $client->post($url, $payload)
                : $client->get($url, $payload);
        } catch (\Throwable) {
            Log::warning('Meta integration request failed', ['host' => 'graph.facebook.com', 'path' => $path]);

            throw new MarketingIntegrationException(
                'META_API_UNREACHABLE',
                'Meta could not be reached. Try again later.',
                502,
            );
        }

        $error = $response->json('error');
        $code = is_array($error) ? (int) ($error['code'] ?? 0) : 0;

        if ($response->status() === 401 || in_array($code, [102, 190, 463, 467], true)) {
            throw new MarketingIntegrationException(
                'META_REAUTH_REQUIRED',
                'Meta rejected the access token. Reconnect the account.',
                409,
            );
        }

        if ($response->status() === 403 || in_array($code, [10, 200, 299, 803], true)) {
            throw new MarketingIntegrationException(
                'META_PERMISSION_DENIED',
                'The connected Meta account does not have permission for this asset or action. Check the app review, Page roles and ad-account access.',
                403,
            );
        }

        if (! $response->successful()) {
            $detail = is_array($error) ? ($error['message'] ?? null) : null;
            $message = is_string($detail) && $detail !== ''
                ? 'Meta returned an error: '.mb_substr($detail, 0, 300)
                : "Meta returned HTTP {$response->status()}.";

            throw new MarketingIntegrationException('META_API_ERROR', $message, 422);
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }
}
