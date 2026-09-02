<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Query;

use Doctrine\DBAL\Connection;

/**
 * DBAL Query: активные SELLER-подключения всех маркетплейсов.
 *
 * Используется cron-командами, которые должны пройти по всем парам
 * (companyId, marketplace) — например, ежедневная пересборка предварительного
 * ОПиУ. Возвращает массивы (Fast Read), без EntityManager.
 */
final class ActiveSellerConnectionsQuery
{
    /**
     * Потолок выборки подключений одной компании.
     *
     * «Их всегда единицы» — наблюдение, а не ограничение: схема столько
     * кабинетов не запрещает, и накопившийся мусор дал бы неограниченную
     * выборку. Значение с большим запасом относительно любого реального числа
     * кабинетов.
     */
    public const COMPANY_CONNECTIONS_LIMIT = 200;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return array<int, array{id: string, company_id: string, marketplace: string}>
     */
    public function execute(): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT mc.id, mc.company_id, mc.marketplace
             FROM marketplace_connections mc
             WHERE mc.is_active = true
               AND mc.connection_type = :type
             ORDER BY mc.company_id, mc.marketplace',
            ['type' => 'seller'],
        );
    }

    /**
     * Подключения ОДНОЙ компании. Фильтр выполняет БД, а не вызывающий: отбор
     * после выборки означал бы читать реестр подключений всех компаний ради
     * одной.
     *
     * @return list<array{id: string, company_id: string, marketplace: string}>
     */
    public function executeForCompany(string $companyId, int $limit = self::COMPANY_CONNECTIONS_LIMIT): array
    {
        return self::shape($this->connection->fetchAllAssociative(
            'SELECT mc.id, mc.company_id, mc.marketplace
             FROM marketplace_connections mc
             WHERE mc.is_active = true
               AND mc.connection_type = :type
               AND mc.company_id = :companyId
             ORDER BY mc.company_id, mc.marketplace
             LIMIT :limit',
            ['type' => 'seller', 'companyId' => $companyId, 'limit' => max(1, min(1000, $limit))],
        ));
    }

    /**
     * Страница реестра подключений с keyset-курсором по `id`.
     *
     * Keyset, а не OFFSET: страницы читаются в цикле, и подключение,
     * добавленное или отключённое между страницами, сдвинул бы OFFSET, из-за
     * чего одно подключение обработалось бы дважды, а другое — ни разу.
     *
     * @return list<array{id: string, company_id: string, marketplace: string}>
     */
    public function executePage(int $limit, ?string $afterId = null): array
    {
        $sql = 'SELECT mc.id, mc.company_id, mc.marketplace
             FROM marketplace_connections mc
             WHERE mc.is_active = true
               AND mc.connection_type = :type';
        $params = ['type' => 'seller'];

        if (null !== $afterId) {
            $sql .= ' AND mc.id > :afterId';
            $params['afterId'] = $afterId;
        }

        return self::shape($this->connection->fetchAllAssociative(
            $sql.' ORDER BY mc.id LIMIT '.max(1, min(1000, $limit)),
            $params,
        ));
    }

    /**
     * Форма строки объявляется явно: динамически собранный SQL инференсу
     * расширения недоступен, и без этого тип вырождался бы в
     * `array<string, mixed>` уже на границе Facade.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array{id: string, company_id: string, marketplace: string}>
     */
    private static function shape(array $rows): array
    {
        return array_map(
            static fn (array $row): array => [
                'id' => (string) $row['id'],
                'company_id' => (string) $row['company_id'],
                'marketplace' => (string) $row['marketplace'],
            ],
            $rows,
        );
    }
}
