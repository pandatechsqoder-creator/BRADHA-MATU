<?php

namespace App\Providers\Pos\External;

use App\Models\Tenant;
use Illuminate\Support\Str;

abstract class ExternalPosProvider
{
    /** @var string */
    protected $name;

    /** @var array */
    protected $credentials;

    /** @var string */
    protected $baseUrl;

    /** @var int */
    protected $timeout;

    /**
     * Create a new external POS provider instance.
     *
     * @param array $credentials  Credentials for the external system
     * @param string $baseUrl     Base API URL
     * @param int $timeout        Request timeout in seconds
     */
    public function __construct(array $credentials, string $baseUrl = '', int $timeout = 30)
    {
        $this->credentials = $credentials;
        $this->baseUrl = $baseUrl ?: config("external_pos.{$this->name}.base_url", '');
        $this->timeout = $timeout ?: config("external_pos.{$this->name}.timeout", 30);
    }

    /**
     * Get the name of the external system.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Check if the provider is configured.
     */
    public function isConfigured(): bool
    {
        return !empty($this->credentials['api_key'] ?? $this->credentials['username'] ?? null);
    }

    /**
     * Build the full URL for an endpoint.
     */
    protected function buildUrl(string $endpoint, array $query = []): string
    {
        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($endpoint, '/');

        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        return $url;
    }

    /**
     * Sync products from external system data.
     *
     * @param array $productDatas  Product data from external system
     * @return array<\App\Models\Product>
     */
    public function syncProducts(array $productDatas): array
    {
        $products = [];

        foreach ($productDatas as $productData) {
            $products[] = $this->syncProduct($productData);
        }

        return $products;
    }

    /**
     * Sync a single product from external system data.
     *
     * @param array $productData  Product data from external system
     * @return \App\Models\Product
     */
    public function syncProduct(array $productData): \App\Models\Product
    {
        $normalized = $this->normalizeProduct($productData);

        $product = $this->tenant->products()
            ->where('external_id', $normalized['external_id'] ?? null)
            ->first();

        if ($product) {
            $product->update([
                'name' => $normalized['name'] ?? $product->name,
                'sku' => $normalized['sku'] ?? $product->sku,
                'barcode' => $normalized['barcode'] ?? $product->barcode,
                'unit' => $normalized['unit'] ?? $product->unit,
                'track_inventory' => $normalized['track_inventory'] ?? $product->track_inventory,
            ]);
        } else {
            $product = \App\Models\Product::create(array_merge([
                'tenant_id' => $this->tenant->id,
                'title' => $normalized['name'] ?? 'External Product',
                'external_id' => $normalized['external_id'] ?? Str::random(16),
                'sku' => $normalized['sku'],
                'barcode' => $normalized['barcode'],
                'unit' => $normalized['unit'] ?? 'piece',
                'track_inventory' => $normalized['track_inventory'] ?? true,
            ], $normalized));
        }

        return $product;
    }

    /**
     * Normalize product data from external system.
     *
     * @param array $data  Raw product data
     * @return array  Normalized product data
     */
    abstract protected function normalizeProduct(array $data): array;

    /**
     * Check eligibility for external POS sync.
     *
     * @param \App\Models\Tenant $tenant
     * @return array  Eligibility status
     */
    public function checkEligibility(Tenant $tenant): array
    {
        $minProducts = config('stocktake.min_products_for_commissioning', 5);
        $productCount = $tenant->products()->count();
        $hasReadiness = $productCount >= $minProducts;

        return [
            'ready' => $hasReadiness,
            'product_count' => $productCount,
            'minimum_required' => $minProducts,
            'blocks' => $hasReadiness ? [] : ['insufficient_products'],
            'can_commission' => $hasReadiness,
        ];
    }
}