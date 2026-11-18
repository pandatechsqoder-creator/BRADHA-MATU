<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Local/staging demo data ONLY. Never seed this in production.
     * Shared local password: Password1!
     */
    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->warn('Production seeding is limited to RBAC; demo fixtures are not run.');
            $this->call(RbacSeeder::class);

            return;
        }

        $this->call([
            RbacSeeder::class,
            MarketingContentSeeder::class,
            DemoDataSeeder::class,
            PosDemoSeeder::class,
            SopSeeder::class,
        ]);
    }
}
