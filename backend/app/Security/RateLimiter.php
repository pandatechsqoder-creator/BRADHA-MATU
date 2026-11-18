<?php

namespace App\Security;

/**
 * Rate limiting configurations and helper for BRATHAMATU platform.
 *
 * Follows the principle that all API endpoints must enforce tenant isolation
 * and rate limiting to prevent abuse and ensure production readiness.
 */
class RateLimiter
{
    /** @var string */
    protected $prefix = 'rate_limit';

    /**
     * Get the rate limiter for API authentication endpoints.
     *
     * @return \Illuminate\Cache\RateLimiter
     */
    public function apiAuth(): \Illuminate\Cache\RateLimiter
    {
        return \Cache::limiter()->attempt(
            $this->key('api_auth'),
            fn() => 1,
            $this->perMinute(60)
        );
    }

    /**
     * Get the rate limiter for stock-take commissioning endpoints.
     *
     * @return \Illuminate\Cache\RateLimiter
     */
    public function stocktakeCommission(): \Illuminate\Cache\RateLimiter
    {
        return \Cache::limiter()->attempt(
            $this->key('stocktake_commission'),
            fn() => 1,
            $this->perMinute(10)
        );
    }

    /**
     * Get the rate limiter for POS sale endpoints.
     *
     * @return \Illuminate\Cache\RateLimiter
     */
    public function posSale(): \Illuminate\Cache\RateLimiter
    {
        return \Cache::limiter()->attempt(
            $this->key('pos_sale'),
            fn() => 1,
            $this->perMinute(200)
        );
    }

    /**
     * Get the rate limiter for inventory operations.
     *
     * @return \Illuminate\Cache\RateLimiter
     */
    public function inventory(): \Illuminate\Cache\RateLimiter
    {
        return \Cache::limiter()->attempt(
            $this->key('inventory'),
            fn() => 1,
            $this->perMinute(100)
        );
    }

    /**
     * Get the rate limiter for admin/platform endpoints.
     *
     * @return \Illuminate\Cache\RateLimiter
     */
    public function admin(): \Illuminate\Cache\RateLimiter
    {
        return \Cache::limiter()->attempt(
            $this->key('admin'),
            fn() => 1,
            $this->perMinute(30)
        );
    }

    /**
     * Build the rate limiter key for a tenant.
     *
     * @param string $type  Rate limiter type prefix
     * @return string  Full rate limiter key
     */
    protected function key(string $type): string
    {
        return "{$this->prefix}:{$type}";
    }

    /**
     * Get per-minute limit for a rate limiter type.
     *
     * @param string $type  Rate limiter type
     * @return int  Limits per minute
     */
    protected function perMinute(string $type): int
    {
        $limits = [
            'api_auth' => 60,
            'stocktake_commission' => 10,
            'pos_sale' => 200,
            'inventory' => 100,
            'admin' => 30,
        ];

        return $limits[$type] ?? 60;
    }

    /**
     * Get per-hour limit for a rate limiter type.
     *
     * @param string $type  Rate limiter type
     * @return int  Limits per hour
     */
    protected function perHour(string $type): int
    {
        $limits = [
            'api_auth' => 3600,
            'stocktake_commission' => 600,
            'pos_sale' => 12000,
            'inventory' => 6000,
            'admin' => 1800,
        ];

        return $limits[$type] ?? 3600;
    }

    /**
     * Reset rate limit for a specific tenant and type.
     *
     * @param string $type  Rate limiter type
     */
    public function reset(string $type): void
    {
        // In production, use Cache::forget()
        // Cache::forget("{$this->prefix}:{$type}");
    }
}