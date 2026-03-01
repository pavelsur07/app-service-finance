<?php

declare(strict_types=1);

namespace App\Cash\Infrastructure\Query;

use Doctrine\DBAL\Connection;

final class CounterpartyHistoryQuery
{
    public function __construct(
        private readonly Connection $connection
    ) {}

    /**
     * Возвращает историю задержек, сгруппированную по контрагентам.
     * * @return array<string, list<int>> Формат: ['counterparty_uuid' => [0, 2, -1, 15]]
     */
    public function getDelaysGroupedByCounterparty(string $companyId, \DateTimeImmutable $since): array
    {
        // Используем DBAL для максимальной производительности
        $sql = <<<SQL
            SELECT
                p.counterparty_id,
                -- Разница в днях между фактом (transaction) и планом (payment_plan)
                -- (В зависимости от вашей СУБД функция может отличаться, пример для PostgreSQL)
                EXTRACT(DAY FROM (t.date - p.planned_at)) AS delay_days
            FROM payment_plan_match m
            JOIN payment_plan p ON m.plan_id = p.id
            JOIN cash_transaction t ON m.transaction_id = t.id
            WHERE m.company_id = :companyId
              AND m.matched_at >= :since
              AND p.counterparty_id IS NOT NULL
SQL;

        $results = $this->connection->fetchAllAssociative($sql, [
            'companyId' => $companyId,
            'since' => $since->format('Y-m-d H:i:s'),
        ]);

        $grouped = [];
        foreach ($results as $row) {
            $cpId = $row['counterparty_id'];
            if (!isset($grouped[$cpId])) {
                $grouped[$cpId] = [];
            }
            $grouped[$cpId][] = (int) $row['delay_days'];
        }

        return $grouped;
    }

    /**
     * Возвращает список всех активных компаний для CLI Worker'а
     * * @return list<string>
     */
    public function getAllActiveCompanyIds(): array
    {
        $sql = 'SELECT id FROM company WHERE is_active = true';
        return $this->connection->fetchFirstColumn($sql);
    }
}
