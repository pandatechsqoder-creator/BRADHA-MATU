<?php

namespace Tests\Feature;

use Database\Seeders\MarketingContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_public_homepage_returns_a_successful_response(): void
    {
        $this->seed(MarketingContentSeeder::class);

        $this->get('/')->assertOk();
    }
}
