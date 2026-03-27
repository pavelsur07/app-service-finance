<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Query;

use App\Marketplace\Enum\MarketplaceType;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;

/**
 * Атомарная вставка листинга Ozon без дублей.
 *
 * INSERT ... ON CONFLICT DO NOTHING гарантирует, что при параллельном вызове
 * из нескольких воркеров (Sales / Returns / Costs) дубль не будет создан:
 * первый INSERT создаёт строку, остальные молча игнорируются.
 */
final class OzonListingUpsertQuery
{
    public function __construct(
        private readonly Connection $connection,
    ) {}

    /**
     * Создаёт листинг если он ещё не существует.
     * Если запись с тем же (company_id, marketplace, marketplace_sku, size) уже есть — ничего не делает.
     *
     * @param string      $companyId UUID компании
     * @param string      $sku       marketplace_sku из финансового отчёта Ozon
     * @param string|null $name      название товара (может отсутствовать)
     */
    public function upsertIfNotExists(string $companyId, string $sku, ?string $name): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->connection->executeStatement(
            <<<'SQL'
            INSERT INTO marketplace_listings
                (id, company_id, marketplace, marketplace_sku, size, price, name, is_active, created_at, updated_at)
            VALUES
                (:id, :company_id, :marketplace, :sku, 'UNKNOWN', '0.00', :name, true, :now, :now)
            ON CONFLICT (company_id, marketplace, marketplace_sku, size) DO NOTHING
            SQL,
            [
                'id'          => Uuid::uuid7()->toString(),
                'company_id'  => $companyId,
                'marketplace' => MarketplaceType::OZON->value,
                'sku'         => $sku,
                'name'        => $name,
                'now'         => $now,
            ],
        );
    }
}
