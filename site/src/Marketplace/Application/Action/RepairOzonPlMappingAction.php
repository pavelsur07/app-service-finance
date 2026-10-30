<?php

declare(strict_types=1);

namespace App\Marketplace\Application\Action;

use App\Marketplace\Infrastructure\Query\OzonPlMappingWarningsQuery;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

final readonly class RepairOzonPlMappingAction
{
    public function __construct(private Connection $connection, private OzonPlMappingWarningsQuery $conflicts)
    {
    }

    public function __invoke(string $companyId): int
    {
        return $this->connection->transactional(function () use ($companyId): int {
            $this->connection->executeQuery('SELECT pg_advisory_xact_lock(hashtext(:key))', ['key' => 'ozon_pl_mapping:'.$companyId]);
            $rows = $this->conflicts->fetchConflicts($companyId);
            foreach ($rows as $row) {
                if (null === $row['realization_id'] || (bool) $row['realization_negative'] !== ('return_refund' === $row['amount_source'])) {
                    throw new \DomainException('Сначала настройте продажи и возвраты с СПП по отчёту «Реализация» на этих же статьях ОПиУ. Ни одно правило не изменено.');
                }
            }
            if ([] === $rows) {
                return 0;
            }

            return (int) $this->connection->executeStatement(
                "UPDATE marketplace_sale_mappings SET is_active = false, updated_at = CURRENT_TIMESTAMP WHERE company_id = :company AND marketplace = 'ozon' AND id IN (:ids) AND is_active = true",
                ['company' => $companyId, 'ids' => array_column($rows, 'id')],
                ['ids' => ArrayParameterType::STRING],
            );
        });
    }
}
