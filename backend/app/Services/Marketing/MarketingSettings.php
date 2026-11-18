<?php

namespace App\Services\Marketing;

use App\Models\Marketing\MarketingSetting;
use Illuminate\Database\QueryException;

final class MarketingSettings
{
    /** @var array<string, mixed>|null */
    private ?array $values = null;

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        if ($this->values !== null) {
            return $this->values;
        }

        try {
            $this->values = MarketingSetting::query()
                ->get(['key', 'value'])
                ->mapWithKeys(fn (MarketingSetting $setting) => [$setting->key => $setting->value])
                ->all();
        } catch (QueryException) {
            $this->values = [];
        }

        return $this->values;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->all()[$key] ?? null;

        if ($value === null || $value === '' || $value === []) {
            return $default;
        }

        return $value;
    }

    /**
     * @return array{name: string, stocktake: string, pos: string, tagline: string, short_description: string}
     */
    public function brand(): array
    {
        return [
            'name' => (string) $this->get('brand.name', config('marketing.brand.name')),
            'stocktake' => (string) $this->get('brand.stocktake', config('marketing.brand.stocktake')),
            'pos' => (string) $this->get('brand.pos', config('marketing.brand.pos')),
            'tagline' => (string) $this->get('brand.tagline', config('marketing.brand.tagline')),
            'short_description' => (string) $this->get('brand.short_description', config('marketing.brand.short_description')),
        ];
    }

    /**
     * @return array{email: ?string, phone: ?string, whatsapp: ?string, whatsapp_url: ?string, address: ?string}
     */
    public function contact(): array
    {
        $whatsapp = $this->get('contact.whatsapp', config('marketing.contact.whatsapp'));
        $digits = $whatsapp !== null ? preg_replace('/\D+/', '', (string) $whatsapp) : null;

        return [
            'email' => $this->get('contact.email', config('marketing.contact.email')),
            'phone' => $this->get('contact.phone', config('marketing.contact.phone')),
            'whatsapp' => $whatsapp,
            'whatsapp_url' => $digits ? 'https://wa.me/'.$digits : null,
            'address' => $this->get('contact.address', config('marketing.contact.address')),
        ];
    }

    /**
     * @return array<string, ?string>
     */
    public function social(): array
    {
        $social = [];

        foreach (['facebook', 'instagram', 'linkedin', 'x'] as $network) {
            $value = $this->get('social.'.$network, config('marketing.social.'.$network));
            if ($value !== null) {
                $social[$network] = (string) $value;
            }
        }

        return $social;
    }

    public function titleSuffix(): string
    {
        return (string) $this->get('seo.title_suffix', config('marketing.seo.title_suffix', ''));
    }

    public function defaultDescription(): string
    {
        return (string) $this->get('seo.default_description', config('marketing.seo.default_description'));
    }
}
