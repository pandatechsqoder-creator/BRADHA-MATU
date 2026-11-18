<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Api\V1\Tenant\Concerns\ChecksTenantAccess;
use App\Http\Controllers\Controller;
use App\Models\IntegrationConflict;
use App\Models\IntegrationConnection;
use App\Models\Tenant;
use App\Services\Integrations\IntegrationService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class IntegrationController extends Controller
{
    use ChecksTenantAccess;

    public function index(Request $request, Tenant $tenant, IntegrationService $integrations): JsonResponse
    {
        $this->assertCan($request, $tenant, 'integration.manage');

        return ApiResponse::success($integrations->listConnections($tenant));
    }

    public function store(Request $request, Tenant $tenant, IntegrationService $integrations): JsonResponse
    {
        $this->assertCan($request, $tenant, 'integration.manage');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'provider' => ['sometimes', Rule::in(IntegrationService::PROVIDERS)],
            'type' => ['sometimes', Rule::in(['csv', 'api'])],
            'status' => ['sometimes', Rule::in(['active', 'paused'])],
            'credentials' => ['nullable', 'array'],
            'credentials.token' => ['nullable', 'string', 'max:500'],
            'settings' => ['nullable', 'array'],
            'settings.url' => ['nullable', 'url', 'max:500'],
            'settings.auto_sync' => ['nullable', 'boolean'],
            'webhook_secret' => ['nullable', 'string', 'min:16', 'max:200'],
        ]);

        return ApiResponse::success(
            $integrations->createConnection($tenant, $data, $request->user()),
            201,
        );
    }

    public function update(Request $request, Tenant $tenant, IntegrationConnection $connection, IntegrationService $integrations): JsonResponse
    {
        $this->assertCan($request, $tenant, 'integration.manage');
        abort_unless($connection->tenant_id === $tenant->id, 404);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'provider' => ['sometimes', Rule::in(IntegrationService::PROVIDERS)],
            'type' => ['sometimes', Rule::in(['csv', 'api'])],
            'status' => ['sometimes', Rule::in(['active', 'paused', 'error'])],
            'credentials' => ['nullable', 'array'],
            'settings' => ['nullable', 'array'],
            'webhook_secret' => ['sometimes', 'string', 'min:16', 'max:200'],
        ]);

        return ApiResponse::success($integrations->updateConnection($connection, $data, $request->user()));
    }

    public function destroy(Request $request, Tenant $tenant, IntegrationConnection $connection, IntegrationService $integrations): JsonResponse
    {
        $this->assertCan($request, $tenant, 'integration.manage');
        abort_unless($connection->tenant_id === $tenant->id, 404);

        $integrations->deleteConnection($connection, $request->user());

        return ApiResponse::success(['status' => 'deleted']);
    }

    public function test(Request $request, Tenant $tenant, IntegrationConnection $connection, IntegrationService $integrations): JsonResponse
    {
        $this->assertCan($request, $tenant, 'integration.manage');
        abort_unless($connection->tenant_id === $tenant->id, 404);

        return ApiResponse::success($integrations->testConnection($connection));
    }

    public function mapping(Request $request, Tenant $tenant, IntegrationConnection $connection, string $entity, IntegrationService $integrations): JsonResponse
    {
        $this->assertCan($request, $tenant, 'integration.manage');
        abort_unless($connection->tenant_id === $tenant->id, 404);
        abort_unless(in_array($entity, IntegrationService::ENTITIES, true), 404);

        return ApiResponse::success($integrations->mapping($connection, $entity));
    }

    public function saveMapping(Request $request, Tenant $tenant, IntegrationConnection $connection, string $entity, IntegrationService $integrations): JsonResponse
    {
        $this->assertCan($request, $tenant, 'integration.manage');
        abort_unless($connection->tenant_id === $tenant->id, 404);
        abort_unless(in_array($entity, IntegrationService::ENTITIES, true), 404);

        $data = $request->validate([
            'field_map' => ['present', 'array'],
            'field_map.*' => ['nullable', 'string', 'max:120'],
            'options' => ['nullable', 'array'],
        ]);

        return ApiResponse::success(
            $integrations->saveMapping($connection, $entity, $data['field_map'], $data['options'] ?? []),
        );
    }

    public function sync(Request $request, Tenant $tenant, IntegrationConnection $connection, IntegrationService $integrations): JsonResponse
    {
        $this->assertCan($request, $tenant, 'integration.manage');
        abort_unless($connection->tenant_id === $tenant->id, 404);

        $data = $request->validate([
            'entity' => ['required', Rule::in(IntegrationService::ENTITIES)],
            'direction' => ['sometimes', Rule::in(['import', 'pull'])],
            'rows' => ['required_if:direction,import', 'array'],
            'rows.*' => ['array'],
        ]);

        if (($data['direction'] ?? 'import') === 'pull' && empty($connection->settings['url'])) {
            abort(422, 'This connection has no remote endpoint configured.');
        }

        return ApiResponse::success(
            $integrations->runSync($connection, $data, $request->user()),
            201,
        );
    }

    public function runs(Request $request, Tenant $tenant, IntegrationConnection $connection, IntegrationService $integrations): JsonResponse
    {
        $this->assertCan($request, $tenant, 'integration.manage');
        abort_unless($connection->tenant_id === $tenant->id, 404);

        return ApiResponse::success($integrations->listRuns($connection));
    }

    public function conflicts(Request $request, Tenant $tenant, IntegrationConnection $connection, IntegrationService $integrations): JsonResponse
    {
        $this->assertCan($request, $tenant, 'integration.manage');
        abort_unless($connection->tenant_id === $tenant->id, 404);

        return ApiResponse::success($integrations->listConflicts($connection, $request->query('resolution')));
    }

    public function resolveConflict(Request $request, Tenant $tenant, IntegrationConnection $connection, IntegrationConflict $conflict, IntegrationService $integrations): JsonResponse
    {
        $this->assertCan($request, $tenant, 'integration.manage');
        abort_unless($connection->tenant_id === $tenant->id, 404);
        abort_unless($conflict->connection_id === $connection->id, 404);

        $data = $request->validate([
            'resolution' => ['required', Rule::in(['accept_external', 'keep_local', 'ignored'])],
        ]);

        return ApiResponse::success(
            $integrations->resolveConflict($conflict, $data['resolution'], $request->user()),
        );
    }

    public function webhooks(Request $request, Tenant $tenant, IntegrationConnection $connection, IntegrationService $integrations): JsonResponse
    {
        $this->assertCan($request, $tenant, 'integration.manage');
        abort_unless($connection->tenant_id === $tenant->id, 404);

        return ApiResponse::success($integrations->listWebhookEvents($connection));
    }
}
