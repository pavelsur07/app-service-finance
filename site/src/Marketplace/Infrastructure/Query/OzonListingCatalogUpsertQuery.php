<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Query;

use App\Marketplace\Enum\MarketplaceType;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;

/**
 * Запись листинга Ozon из каталога маркетплейса.
 *
 * Отдельный класс от {@see OzonListingUpsertQuery}, а не его изменение.
 * Тот обслуживает финансовый pipeline и обязан остаться `DO NOTHING`: если бы
 * оба пути делили один `DO UPDATE`, финансовый документ перезаписывал бы
 * каталожное имя — ровно наоборот принятому решению, что каталог авторитетнее.
 *
 * Чего запрос намеренно НЕ трогает:
 * - `is_active` — пропажу товара из каталога разбираем вручную;
 * - `price` — каталожная цена витринная, а не цена продажи; она идёт
 *   в `marketplace_data`, иначе колонка сменила бы смысл;
 * - `product_id` — маппинг на товар учётной системы делает человек.
 *
 * `marketplace_data` перезаписывается целиком: каталог — единственный писатель
 * этой колонки.
 */
final class OzonListingCatalogUpsertQuery
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param array<string, mixed> $marketplaceData
     *
     * @return int число фактически записанных строк: 0 означает, что
     *             freshness-guard отклонил устаревший прогон
     */
    public function upsert(
        string $companyId,
        string $marketplaceSku,
        ?string $name,
        ?string $supplierSku,
        ?\DateTimeImmutable $marketplaceCreatedAt,
        \DateTimeImmutable $lastSeenAt,
        array $marketplaceData,
    ): int {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        return (int) $this->connection->executeStatement(
            <<<'SQL'
            INSERT INTO marketplace_listings
                (id, company_id, marketplace, marketplace_sku, supplier_sku, size, price, name,
                 is_active, marketplace_data, marketplace_created_at, last_seen_at, created_at, updated_at)
            VALUES
                (:id, :company_id, :marketplace, :sku, :supplier_sku, 'UNKNOWN', '0.00', :name,
                 true, CAST(:marketplace_data AS JSON), :marketplace_created_at, :last_seen_at, :now, :now)
            ON CONFLICT (company_id, marketplace, marketplace_sku, size) DO UPDATE SET
                name = COALESCE(EXCLUDED.name, marketplace_listings.name),
                supplier_sku = COALESCE(EXCLUDED.supplier_sku, marketplace_listings.supplier_sku),
                marketplace_data = EXCLUDED.marketplace_data,
                marketplace_created_at = COALESCE(EXCLUDED.marketplace_created_at, marketplace_listings.marketplace_created_at),
                last_seen_at = EXCLUDED.last_seen_at,
                updated_at = EXCLUDED.updated_at
            -- Прогон, начавшийся раньше, но завершившийся позже, не должен подменять
            -- более свежий снимок: иначе строка выглядит актуальной по
            -- last_seen_at, а несёт устаревшие имя, цену и статус. Условие
            -- закрывает ВСЕ поля разом, а не только отметку времени.
            --
            -- Сравнение строгое. Внутри одного прогона один и тот же
            -- conflict-ключ (company_id, marketplace, marketplace_sku, size)
            -- не пишется дважды: у двух листингов одного товара разные
            -- marketplace_sku. А колонка last_seen_at имеет точность в секунду,
            -- поэтому при `>=` два прогона, стартовавшие в одну секунду, снова
            -- могли бы перезаписать друг друга — ровно та гонка, которую это
            -- условие и закрывает.
            WHERE marketplace_listings.last_seen_at IS NULL
               OR EXCLUDED.last_seen_at > marketplace_listings.last_seen_at
            SQL,
            [
                'id' => Uuid::uuid7()->toString(),
                'company_id' => $companyId,
                'marketplace' => MarketplaceType::OZON->value,
                'sku' => $marketplaceSku,
                'supplier_sku' => $supplierSku,
                'name' => $name,
                'marketplace_data' => json_encode($marketplaceData, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE),
                'marketplace_created_at' => $marketplaceCreatedAt?->format('Y-m-d H:i:s'),
                'last_seen_at' => $lastSeenAt->format('Y-m-d H:i:s'),
                'now' => $now,
            ],
        );
    }
}
