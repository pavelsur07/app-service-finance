<?php

declare(strict_types=1);

namespace App\Balance\Infrastructure\Query;

use App\Company\Event\DirectoryDuplicate;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Соседние статьи справочника «Баланс» (один родитель) с одинаковым названием без учёта регистра и
 * крайних пробелов, кроме архивных — для ночной проверки справочников.
 */
final readonly class BalanceArticleDuplicateNamesQuery
{
    private const CHUNK = 500;

    public function __construct(private Connection $db)
    {
    }

    /**
     * @param list<string> $companyIds
     *
     * @return list<DirectoryDuplicate>
     */
    public function __invoke(array $companyIds): array
    {
        $duplicates = [];
        foreach (array_chunk($companyIds, self::CHUNK) as $chunk) {
            $rows = $this->db->fetchAllAssociative(
                'SELECT c.company_id::text AS company_id, MIN(c.name) AS name, COUNT(*) AS n, p.name AS parent_name
                 FROM balance_articles c LEFT JOIN balance_articles p ON p.id = c.parent_id
                 WHERE c.company_id IN (?) AND c.is_archived = false
                 GROUP BY c.company_id, c.parent_id, p.name, LOWER(BTRIM(c.name))
                 HAVING COUNT(*) > 1',
                [$chunk],
                [ArrayParameterType::STRING],
            );
            foreach ($rows as $row) {
                $duplicates[] = new DirectoryDuplicate('balance', (string) $row['company_id'], (string) $row['name'], (int) $row['n'], null !== $row['parent_name'] ? (string) $row['parent_name'] : null);
            }
        }

        return $duplicates;
    }
}
