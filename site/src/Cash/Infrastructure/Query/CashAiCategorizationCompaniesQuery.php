<?php

declare(strict_types=1);

namespace App\Cash\Infrastructure\Query;

use Doctrine\DBAL\Connection;

/**
 * Охват cron `app:cash:ai-categorize --all-enabled`: компании с живыми операциями ДДС за
 * окно кандидатов. Включён ли ИИ для компании, решает allowlist (`AiTaskFacadeInterface`),
 * какие операции ставить — `RequestCashAiCategorizationAction`.
 */
final readonly class CashAiCategorizationCompaniesQuery
{
    public function __construct(private Connection $db)
    {
    }

    /** @return list<string> */
    public function withTransactionsSince(\DateTimeImmutable $since): array
    {
        return array_map('strval', $this->db->fetchFirstColumn(
            'SELECT DISTINCT company_id::text FROM cash_transaction WHERE deleted_at IS NULL AND occurred_at >= :since ORDER BY 1',
            ['since' => $since->format('Y-m-d')],
        ));
    }
}
