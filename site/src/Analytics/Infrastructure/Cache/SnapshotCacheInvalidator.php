<?php

declare(strict_types=1);

namespace App\Analytics\Infrastructure\Cache;

use App\Company\Entity\Company;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class SnapshotCacheInvalidator
{
    private const VERSION_CACHE_KEY = 'dashboard:v1:version:%s';

    public function __construct(
        private readonly CacheInterface $cache,
    ) {
    }

    public function invalidateForCompany(Company $company): void
    {
        $versionKey = $this->buildVersionKey($company);
        $currentVersion = $this->resolveVersionForCompany($company);

        $this->cache->delete($versionKey);
        $this->cache->get($versionKey, static function (ItemInterface $item) use ($currentVersion): int {
            $item->expiresAfter(null);

            return $currentVersion + 1;
        });
    }

    public function resolveVersionForCompany(Company $company): int
    {
        $versionKey = $this->buildVersionKey($company);

        return $this->cache->get($versionKey, static function (ItemInterface $item): int {
            $item->expiresAfter(null);

            return 1;
        });
    }

    private function buildVersionKey(Company $company): string
    {
        return sprintf(self::VERSION_CACHE_KEY, (string) $company->getId());
    }
}

