<?php

namespace Tests\Feature;

use App\Models\Marketing\CmsPage;
use App\Models\Marketing\MarketingSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class LaunchCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_launch_check_fails_while_contact_details_and_homepage_are_missing(): void
    {
        $exit = Artisan::call('marketing:launch-check');
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('contact_details', $output);
        $this->assertStringContainsString('homepage', $output);
        $this->assertStringContainsString('not_ready', $output);
    }

    public function test_launch_check_passes_with_confirmed_contact_details_and_a_published_homepage(): void
    {
        MarketingSetting::query()->create([
            'group' => 'contact',
            'key' => 'contact.email',
            'value' => 'hello@example.test',
            'is_public' => true,
        ]);

        CmsPage::create([
            'slug' => 'home',
            'title' => 'Home',
            'type' => 'page',
            'template' => 'public.page',
            'status' => 'published',
            'is_home' => true,
            'published_at' => now(),
        ]);

        $exit = Artisan::call('marketing:launch-check');
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('ready_with_warnings', $output);
        $this->assertStringContainsString('Contact details are configured', $output);
    }
}
