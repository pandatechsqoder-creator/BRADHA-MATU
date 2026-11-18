<?php

namespace App\Console\Commands;

use App\Models\IntegrationConnection;
use App\Services\Integrations\IntegrationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

final class SyncIntegrationsCommand extends Command
{
    protected $signature = 'integrations:sync {--connection= : Only sync the given connection UUID}';

    protected $description = 'Pull catalogue and stock movements from active external integrations.';

    public function handle(IntegrationService $integrations): int
    {
        $connections = IntegrationConnection::withoutGlobalScopes()
            ->where('status', 'active')
            ->where('type', 'api')
            ->when($this->option('connection'), fn ($query, $uuid) => $query->where('uuid', $uuid))
            ->get();

        if ($connections->isEmpty()) {
            $this->info('No active API integrations to sync.');

            return self::SUCCESS;
        }

        foreach ($connections as $connection) {
            if (empty($connection->settings['auto_sync'])) {
                continue;
            }

            foreach (['products', 'movements'] as $entity) {
                $run = $integrations->runSync($connection, [
                    'entity' => $entity,
                    'direction' => 'pull',
                ]);

                $this->line(sprintf(
                    '%s %s: %s (%d applied, %d conflicts)',
                    $connection->name,
                    $entity,
                    $run['status'],
                    $run['records_upserted'],
                    $run['conflict_count'],
                ));

                if ($run['status'] === 'failed') {
                    Log::warning('Integration sync failed', [
                        'connection' => $connection->uuid,
                        'entity' => $entity,
                        'message' => $run['message'],
                    ]);
                }
            }
        }

        return self::SUCCESS;
    }
}
