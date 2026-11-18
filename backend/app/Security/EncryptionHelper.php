<?php

namespace App\Security;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Exception;

/**
 * Helper class for secure password and data handling.
 *
 * Follows the principle that Laravel is the authoritative backend
 * for all business rules and encryption (frontend never touches DB).
 */
class EncryptionHelper
{
    /**
     * Generate a secure password meeting BRATHAMATU requirements.
     *
     * @param int $length  Password length (default: 16)
     * @return \Illuminate\Validation\Rules\Password
     */
    public static function securePassword($length = 16): Password
    {
        return Password::min($length)
            ->mixedCase()
            ->numbers()
            ->symbols()
            ->unicode();
    }

    /**
     * Hash a password using Laravel's bcrypt.
     *
     * @param string $password  Plain text password
     * @return string  Hashed password
     */
    public static function hashPassword(string $password): string
    {
        return Hash::make($password);
    }

    /**
     * Verify a password against its hash.
     *
     * @param string $password  Plain text password
     * @param string $hashed    Hashed password
     * @return bool  Whether password matches
     * @throws Exception
     */
    public static function verifyPassword(string $password, string $hashed): bool
    {
        return Hash::check($password, $hashed);
    }

    /**
     * Encrypt sensitive data using Laravel's encrypt helper (AES-256).
     *
     * @param string $data  Data to encrypt
     * @return string  Encrypted data (will be decrypted with decrypt())
     */
    public static function encryptData(string $data): string
    {
        return encrypt($data);
    }

    /**
     * Decrypt sensitive data encrypted by encryptData().
     *
     * @param string $encrypted  Encrypted data
     * @return string  Decrypted data
     * @throws Exception
     */
    public static function decryptData(string $encrypted): string
    {
        return decrypt($encrypted);
    }

    /**
     * Generate a secure API token for a tenant.
     *
     * @param \App\Models\Tenant $tenant  Tenant instance
     * @param int $ttl  Time to live in minutes (default: 43200 = 30 days)
     * @return string  Encrypted token
     */
    public static function generateApiToken(\App\Models\Tenant $tenant, $ttl = 43200): string
    {
        $token = base64_encode(random_bytes(64));

        // Store tenant_id + token in cache with expiry
        // In production, use Redis cache: Cache::put("api_token:{$tenant->id}:{$token}", true, $ttl/60);

        return $token;
    }

    /**
     * Validate API token for a tenant.
     *
     * @param \App\Models\Tenant $tenant  Tenant instance
     * @param string $token  Token to validate
     * @return bool  Whether token is valid
     */
    public static function validateApiToken(\App\Models\Tenant $tenant, string $token): bool
    {
        // In production, check Redis cache
        // return Cache::get("api_token:{$tenant->id}:{$token}") ?? false;

        // Placeholder - in production, implement proper token validation
        return !empty($token);
    }
}