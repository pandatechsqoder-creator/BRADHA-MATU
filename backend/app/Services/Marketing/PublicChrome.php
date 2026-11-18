<?php

namespace App\Services\Marketing;

final class PublicChrome
{
    /** @var array<string, mixed>|null */
    private ?array $payload = null;

    public function __construct(
        private readonly MarketingSettings $settings,
        private readonly PublicMenu $menus,
        private readonly PublicBanner $banners,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        if ($this->payload !== null) {
            return $this->payload;
        }

        return $this->payload = [
            'brand' => $this->settings->brand(),
            'contact' => $this->settings->contact(),
            'social' => $this->settings->social(),
            'banner' => $this->banners->active(),
            'primary_menu' => $this->menus->items('primary'),
            'footer_menu' => $this->menus->items('footer'),
            'legal_menu' => $this->menus->items('legal'),
            'app_url' => rtrim((string) config('marketing.app_url'), '/'),
        ];
    }
}
