<?php

declare(strict_types=1);

namespace App\Analytics\Infrastructure\Cache;

use App\Company\Entity\Company;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

final class SnapshotCacheInvalidator
{
    private const VERSION_CACHE_KEY = 'dashboard_v1_version_%s';

    public function __construct(
        private readonly CacheInterface $cache,
    ) {
    }

    public function invalidateForCompany(Company $company): void
    {
        $this->invalidateForCompanyId((string) $company->getId());
    }

    /**
     * То же по id: слушатель событий Cash получает только companyId.
     */
    public function invalidateForCompanyId(string $companyId): void
    {
        $versionKey = $this->buildVersionKey($companyId);
        $currentVersion = $this->resolveVersionForCompanyId($companyId);

        $this->cache->delete($versionKey);
        $this->cache->get($versionKey, static function (ItemInterface $item) use ($currentVersion): int {
            $item->expiresAfter(null);

            return $currentVersion + 1;
        });
    }

    public function resolveVersionForCompany(Company $company): int
    {
        return $this->resolveVersionForCompanyId((string) $company->getId());
    }

    private function resolveVersionForCompanyId(string $companyId): int
    {
        $versionKey = $this->buildVersionKey($companyId);

        return $this->cache->get($versionKey, static function (ItemInterface $item): int {
            $item->expiresAfter(null);

            return 1;
        });
    }

    private function buildVersionKey(string $companyId): string
    {
        $companyId = str_replace(['{', '}', '(', ')', '/', '\\', '@', ':'], '_', $companyId);

        return sprintf(self::VERSION_CACHE_KEY, $companyId);
    }
}
