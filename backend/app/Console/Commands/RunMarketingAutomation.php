<?php

namespace App\Console\Commands;

use App\Services\Marketing\AutomationService;
use Illuminate\Console\Command;

final class RunMarketingAutomation extends Command
{
    protected $signature = 'marketing:automation:run';

    protected $description = 'Advance marketing automation runs whose waits or retry backoffs are due.';

    public function handle(AutomationService $automation): int
    {
        if (! $automation->enabled()) {
            $this->warn('Automation is paused by the kill switch; no runs were advanced.');

            return self::SUCCESS;
        }

        $processed = $automation->processDue();

        $this->info(sprintf('%d automation run(s) processed.', $processed));

        return self::SUCCESS;
    }
}
