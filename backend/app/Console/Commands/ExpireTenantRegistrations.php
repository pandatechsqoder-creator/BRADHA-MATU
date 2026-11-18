<?php

namespace App\Console\Commands;

use App\Services\Tenancy\DomainEngine;
use App\Services\Tenancy\TenantRegistrationService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('tenancy:expire-registrations')]
#[Description('Expire abandoned tenant registration attempts and domain reservations')]
class ExpireTenantRegistrations extends Command
{
    public function handle(TenantRegistrationService $registrations, DomainEngine $domains): int
    {
        $expiredRegistrations = $registrations->expireAbandonedRegistrations();
        $expiredReservations = $domains->expireReservations();

        $this->info("Expired {$expiredRegistrations} registrations and {$expiredReservations} domain reservations.");

        return Command::SUCCESS;
    }
}
