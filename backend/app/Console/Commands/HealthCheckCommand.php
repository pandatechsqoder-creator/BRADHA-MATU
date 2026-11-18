<?php

namespace App\Console\Commands;

use App\Services\Health\HealthService;
use Illuminate\Console\Command;

final class HealthCheckCommand extends Command
{
    protected $signature = 'health:check';

    protected $description = 'Run readiness probes and exit non-zero when the service is degraded.';

    public function handle(HealthService $health): int
    {
        $result = $health->run();

        $this->line(json_encode([
            'status' => $result['status'],
            'checks' => $result['checks'],
            'diagnostics' => $result['diagnostics'],
            'time' => now()->toIso8601String(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $result['status'] === 'ok' ? self::SUCCESS : self::FAILURE;
    }
}
