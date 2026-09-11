<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Query;

use App\Marketplace\Enum\MarketplaceType;
use Doctrine\DBAL\Connection;

/**
 * Транзакционная блокировка периода закрытия месяца.
 *
 * Закрытие месяца и замена строк перезагруженного дня спорят за одни и те же
 * записи: закрытие агрегирует строки и проставляет им `document_id`, замена их
 * удаляет и пишет заново. Без сериализации закрытие может собрать документ по
 * прежним строкам, а замена — закоммититься после него, и документ ОПиУ
 * останется расходиться с источником навсегда.
 *
 * Блокировка транзакционная (`pg_advisory_xact_lock`): снимается сама на commit
 * или rollback, поэтому забыть её отпустить нельзя. Ключ — компания,
 * маркетплейс и период: разные кабинеты и разные месяцы друг друга не ждут.
 *
 * Оба участника обязаны брать её ВНУТРИ своей транзакции, иначе она отпустится
 * раньше, чем закончится критический участок.
 */
final class MonthCloseAdvisoryLockQuery
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function lock(string $companyId, MarketplaceType $marketplace, int $year, int $month): void
    {
        $this->connection->executeStatement(
            'SELECT pg_advisory_xact_lock(hashtext(:key))',
            ['key' => sprintf('marketplace_month_close:%s:%s:%d-%02d', $companyId, $marketplace->value, $year, $month)],
        );
    }
}
