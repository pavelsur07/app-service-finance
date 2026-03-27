<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Service;

use App\Company\Entity\Company;
use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Repository\MarketplaceListingRepository;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;

/**
 * Идемпотентное создание листингов Ozon.
 *
 * Использует INSERT ... ON CONFLICT DO NOTHING чтобы безопасно создавать листинги
 * даже при параллельной обработке нескольких батчей (Sales / Returns / Costs одновременно).
 * Если другой процесс уже успел создать листинг с тем же SKU — INSERT просто ничего не делает,
 * а повторный SELECT возвращает существующую запись без дублирования.
 */
final class OzonListingEnsureService
{
    public function __construct(
        private readonly MarketplaceListingRepository $listingRepository,
        private readonly Connection $connection,
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

        $now       = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $companyId = (string) $company->getId();

        foreach ($missing as $sku => $name) {
            $this->connection->executeStatement(
                <<<'SQL'
                INSERT INTO marketplace_listings
                    (id, company_id, marketplace, marketplace_sku, size, price, name, is_active, created_at, updated_at)
                VALUES
                    (:id, :company_id, :marketplace, :sku, 'UNKNOWN', '0.00', :name, true, :now, :now)
                ON CONFLICT (company_id, marketplace, marketplace_sku, size) DO NOTHING
                SQL,
                [
                    'id'          => Uuid::uuid4()->toString(),
                    'company_id'  => $companyId,
                    'marketplace' => MarketplaceType::OZON->value,
                    'sku'         => (string) $sku,
                    'name'        => $name,
                    'now'         => $now,
                ],
            );
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
