<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('integrations:sync')->hourly()->withoutOverlapping();
Schedule::command('marketing:publish-scheduled')->everyMinute()->withoutOverlapping();
Schedule::command('marketing:automation:run')->everyMinute()->withoutOverlapping();
Schedule::command('retention:purge')->dailyAt('02:30')->withoutOverlapping();
Schedule::command('tenancy:expire-registrations')->everyMinute()->withoutOverlapping();
Schedule::command('emails:purge-retry-payloads')->everyFifteenMinutes()->withoutOverlapping();
