<?php

namespace App\Jobs;

use App\Models\Marketing\MarketingIntegrationSyncRun;
use App\Services\Marketing\Meta\MetaIntegrationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncMarketingMeta implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(public readonly string $runUuid) {}

    public function handle(MetaIntegrationService $service): void
    {
        $run = MarketingIntegrationSyncRun::query()->where('uuid', $this->runUuid)->first();
        if ($run === null || $run->provider !== 'meta') {
            return;
        }

        $service->execute($run);
    }
}
