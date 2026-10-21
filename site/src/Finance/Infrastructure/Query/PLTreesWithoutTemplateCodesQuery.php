<?php

declare(strict_types=1);

namespace App\Finance\Infrastructure\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Компании, у которых есть статьи ОПиУ, но ни одна не несёт кода стартового шаблона, — своё
 * дерево: «Базовый шаблон ОПиУ» к нему не применится без сопоставления.
 */
final readonly class PLTreesWithoutTemplateCodesQuery
{
    private const CHUNK = 500;

    public function __construct(private Connection $db)
    {
    }

    /**
     * @param list<string> $companyIds
     * @param list<string> $templateCodes коды всех стартовых шаблонов
     *
     * @return list<string> id компаний
     */
    public function __invoke(array $companyIds, array $templateCodes): array
    {
        $result = [];
        foreach (array_chunk($companyIds, self::CHUNK) as $chunk) {
            $result = [...$result, ...$this->db->fetchFirstColumn(
                'SELECT company_id::text FROM pl_categories WHERE company_id IN (?)
                 GROUP BY company_id HAVING COUNT(*) FILTER (WHERE code IN (?)) = 0',
                [$chunk, $templateCodes],
                [ArrayParameterType::STRING, ArrayParameterType::STRING],
            )];
        }

        return array_map('strval', $result);
    }
}
