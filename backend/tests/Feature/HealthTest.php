<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_endpoint_reports_ok_with_envelope_and_request_id(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertOk()
            ->assertHeader('X-Request-Id')
            ->assertJsonPath('data.status', 'ok')
            ->assertJsonPath('data.service', 'brathamatu-stocktake-api')
            ->assertJsonPath('data.checks.database', 'ok')
            ->assertJsonPath('data.checks.cache', 'ok')
            ->assertJsonStructure([
                'data' => ['status', 'service', 'version', 'time', 'checks'],
                'meta' => ['request_id', 'timestamp'],
            ]);
    }

    public function test_unknown_api_route_returns_json_error_envelope(): void
    {
        $response = $this->getJson('/api/v1/does-not-exist');

        $response->assertNotFound()
            ->assertHeader('X-Request-Id')
            ->assertJsonPath('error.code', 'NOT_FOUND');

        $this->assertNotEmpty($response->json('error.request_id'));
    }

    public function test_unauthenticated_api_request_returns_json_envelope_without_accept_header(): void
    {
        $response = $this->get('/api/v1/tenants/1/products');

        $response->assertUnauthorized()
            ->assertHeader('X-Request-Id')
            ->assertJsonPath('error.code', 'UNAUTHENTICATED');

        $this->assertNotEmpty($response->json('error.request_id'));
    }
}
