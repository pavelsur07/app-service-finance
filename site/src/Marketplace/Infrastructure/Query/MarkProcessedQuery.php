<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Помечает записи Marketplace как обработанные:
 * SET document_id = :documentId WHERE document_id IS NULL AND date BETWEEN ...
 *
 * Выполняется ПОСЛЕ успешного создания документа ОПиУ через FinanceFacade.
 * Bulk UPDATE — не загружает Entity в память.
 */
final class MarkProcessedQuery
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * Пометить продажи как обработанные.
     *
     * @return int Количество обновлённых записей
     */
    public function markSales(
        string $companyId,
        string $marketplace,
        string $documentId,
        string $periodFrom,
        string $periodTo,
    ): int {
        return $this->connection->executeStatement(
            'UPDATE marketplace_sales
             SET document_id = :documentId,
                 updated_at = NOW()
             WHERE company_id = :companyId
               AND marketplace = :marketplace
               AND document_id IS NULL
               AND sale_date >= :periodFrom
               AND sale_date <= :periodTo',
            [
                'documentId' => $documentId,
                'companyId' => $companyId,
                'marketplace' => $marketplace,
                'periodFrom' => $periodFrom,
                'periodTo' => $periodTo,
            ]
        );
    }

    /**
     * Пометить возвраты как обработанные.
     *
     * @return int Количество обновлённых записей
     */
    public function markReturns(
        string $companyId,
        string $marketplace,
        string $documentId,
        string $periodFrom,
        string $periodTo,
    ): int {
        return $this->connection->executeStatement(
            'UPDATE marketplace_returns
             SET document_id = :documentId,
                 updated_at = NOW()
             WHERE company_id = :companyId
               AND marketplace = :marketplace
               AND document_id IS NULL
               AND return_date >= :periodFrom
               AND return_date <= :periodTo',
            [
                'documentId' => $documentId,
                'companyId' => $companyId,
                'marketplace' => $marketplace,
                'periodFrom' => $periodFrom,
                'periodTo' => $periodTo,
            ]
        );
    }

    /**
     * Пометить расходы как обработанные.
     *
     * @return int Количество обновлённых записей
     */
    public function markCosts(
        string $companyId,
        string $marketplace,
        string $documentId,
        string $periodFrom,
        string $periodTo,
    ): int {
        return $this->connection->executeStatement(
            'UPDATE marketplace_costs
             SET document_id = :documentId,
                 updated_at = NOW()
             WHERE company_id = :companyId
               AND marketplace = :marketplace
               AND document_id IS NULL
               AND cost_date >= :periodFrom
               AND cost_date <= :periodTo',
            [
                'documentId' => $documentId,
                'companyId' => $companyId,
                'marketplace' => $marketplace,
                'periodFrom' => $periodFrom,
                'periodTo' => $periodTo,
            ]
        );
    }

    /**
     * @param string[] $documentIds
     */
    public function unmarkSalesByDocumentIds(
        string $companyId,
        string $marketplace,
        array $documentIds,
    ): int {
        return $this->unmarkByDocumentIds(
            table: 'marketplace_sales',
            companyId: $companyId,
            marketplace: $marketplace,
            documentIds: $documentIds,
        );
    }

    /**
     * @param string[] $documentIds
     */
    public function unmarkReturnsByDocumentIds(
        string $companyId,
        string $marketplace,
        array $documentIds,
    ): int {
        return $this->unmarkByDocumentIds(
            table: 'marketplace_returns',
            companyId: $companyId,
            marketplace: $marketplace,
            documentIds: $documentIds,
        );
    }

    /**
     * @param string[] $documentIds
     */
    public function unmarkCostsByDocumentIds(
        string $companyId,
        string $marketplace,
        array $documentIds,
    ): int {
        return $this->unmarkByDocumentIds(
            table: 'marketplace_costs',
            companyId: $companyId,
            marketplace: $marketplace,
            documentIds: $documentIds,
        );
    }

    /**
     * @param string[] $documentIds
     */
    private function unmarkByDocumentIds(
        string $table,
        string $companyId,
        string $marketplace,
        array $documentIds,
    ): int {
        if ($documentIds === []) {
            return 0;
        }

        return $this->connection->executeStatement(
            sprintf(
                'UPDATE %s
                 SET document_id = NULL,
                     updated_at = NOW()
                 WHERE company_id = :companyId
                   AND marketplace = :marketplace
                   AND document_id IN (:documentIds)',
                $table,
            ),
            [
                'companyId' => $companyId,
                'marketplace' => $marketplace,
                'documentIds' => $documentIds,
            ],
            [
                'documentIds' => ArrayParameterType::STRING,
            ],
        );
    }
}
