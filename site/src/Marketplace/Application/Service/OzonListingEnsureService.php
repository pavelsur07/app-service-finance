<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Service;

use App\Company\Entity\Company;
use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Query\OzonListingUpsertQuery;
use App\Marketplace\Repository\MarketplaceListingRepository;

/**
 * Идемпотентное создание листингов Ozon.
 *
 * Использует INSERT ... ON CONFLICT DO NOTHING (через OzonListingUpsertQuery)
 * чтобы безопасно создавать листинги даже при параллельной обработке нескольких
 * батчей (Sales / Returns / Costs одновременно).
 * Если другой процесс уже успел создать листинг с тем же SKU — INSERT просто
 * ничего не делает, а повторный SELECT возвращает существующую запись.
 */
final class OzonListingEnsureService
{
    public function __construct(
        private readonly MarketplaceListingRepository $listingRepository,
        private readonly OzonListingUpsertQuery $upsertQuery,
    ) {}

    /**
     * Гарантирует существование листингов для всех переданных SKU.
     * Возвращает карту sku → MarketplaceListing для всех запрошенных SKU.
     *
     * Алгоритм:
     * 1. Загружаем уже существующие листинги из БД.
     * 2. Для отсутствующих делаем INSERT ... ON CONFLICT DO NOTHING — атомарно и без гонок.
     * 3. Повторно запрашиваем все SKU, получая и только что вставленные, и созданные конкурентным процессом.
     *
     * @param array<string, string|null> $skusWithNames [sku => name|null]
     * @return array<string, MarketplaceListing>
     */
    public function ensureListings(Company $company, array $skusWithNames): array
    {
        if (empty($skusWithNames)) {
            return [];
        }

        $allSkus = array_keys($skusWithNames);

        $existing = $this->listingRepository->findListingsBySkusIndexed(
            $company,
            MarketplaceType::OZON,
            $allSkus,
        );

        $missing = array_diff_key($skusWithNames, $existing);

        if (empty($missing)) {
            return $existing;
        }

        $companyId = (string) $company->getId();

        foreach ($missing as $sku => $name) {
            $this->upsertQuery->upsertIfNotExists($companyId, (string) $sku, $name);
        }

        // Повторный запрос после вставки: получаем как только что созданные,
        // так и те, что параллельно создал другой процесс (ON CONFLICT DO NOTHING их не перезапишет).
        return $this->listingRepository->findListingsBySkusIndexed(
            $company,
            MarketplaceType::OZON,
            $allSkus,
        );
    }
}
