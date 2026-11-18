<?php

namespace App\Services\Tenancy;

use App\Models\ReservedDomainName;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class DomainNameNormalizer
{
    public function normalize(string $name): string
    {
        $ascii = Str::ascii(trim(mb_strtolower($name, 'UTF-8')));
        $slug = strtolower(preg_replace('/[^a-z0-9]+/', '', $ascii) ?? '');
        $slug = substr($slug, 0, $this->maximumLength());

        if (strlen($slug) < $this->minimumLength()) {
            throw new InvalidArgumentException('The business link must contain at least '.config('tenancy.minimum_slug_length', 2).' letters or numbers.');
        }

        if ($this->isReserved($slug)) {
            throw new InvalidArgumentException('This domain name is reserved or prohibited.');
        }

        return $slug;
    }

    public function isReserved(string $slug): bool
    {
        $slug = strtolower(trim($slug));

        return ReservedDomainName::query()
            ->where('slug', $slug)
            ->where('blocked', true)
            ->exists();
    }

    public function minimumLength(): int
    {
        return max(2, min((int) config('tenancy.minimum_slug_length', 2), 63));
    }

    public function maximumLength(): int
    {
        return max($this->minimumLength(), min((int) config('tenancy.maximum_slug_length', 63), 63));
    }
}
