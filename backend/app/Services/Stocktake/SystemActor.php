<?php

namespace App\Services\Stocktake;

/**
 * Represents trusted system processing (provider callbacks, scheduled work)
 * inside the stock-taking workflow. It is never derived from an HTTP session.
 */
final class SystemActor
{
    public function __construct(public readonly string $name = 'BRATHAMATU system') {}

    public function getKey(): ?int
    {
        return null;
    }
}
