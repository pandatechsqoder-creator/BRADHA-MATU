<?php

namespace App\Console\Commands;

use App\Services\Email\TransactionalEmailService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('emails:purge-retry-payloads')]
#[Description('Erase expired encrypted transactional email retry payloads')]
class PurgeEmailRetryPayloads extends Command
{
    public function handle(TransactionalEmailService $emails): int
    {
        $purged = $emails->purgeExpiredRetryPayloads();
        $this->info("Erased {$purged} expired email retry payloads.");

        return Command::SUCCESS;
    }
}
