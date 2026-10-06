<?php

declare(strict_types=1);

namespace App\Balance\Infrastructure\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Компании из переданного списка, которым нужна стартовая структура баланса:
 * у них нет ни одной статьи, и книга не инициализирована. Книга с историей
 * без статей невозможна, а инициализированная книга — признак того, что
 * компания баланс использует: пустое дерево там решение пользователя.
 */
final readonly class CompaniesWithoutBalanceStructureQuery
{
    private const CHUNK = 500;

    public function __construct(private Connection $db)
    {
    }

    /**
     * @param list<string> $companyIds
     *
     * @return list<string>
     */
    public function __invoke(array $companyIds): array
    {
        $result = [];
        foreach (array_chunk($companyIds, self::CHUNK) as $chunk) {
            $occupied = $this->db->fetchFirstColumn(
                'SELECT company_id::text FROM balance_articles WHERE company_id IN (?)
                 UNION
                 SELECT company_id::text FROM balance_books WHERE initialized = TRUE AND company_id IN (?)',
                [$chunk, $chunk],
                [ArrayParameterType::STRING, ArrayParameterType::STRING],
            );
            $occupied = array_flip($occupied);
            foreach ($chunk as $companyId) {
                if (!isset($occupied[$companyId])) {
                    $result[] = $companyId;
                }
            }
        }

        return $result;
    }
}
