<?php

declare(strict_types=1);

namespace App\Marketplace\Infrastructure\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Снимает `document_id` со строк ОДНОГО raw-документа, привязанных к указанным
 * документам ОПиУ.
 *
 * Отличие от `MarkProcessedQuery::unmark*ByDocumentIds`: тот снимает привязку со
 * всех строк периода, а здесь охват сужен до одного raw-документа — заменяется
 * только тот день, который перезагрузили, а остальной месяц не трогается.
 */
final class UnlinkDocumentRowsQuery
{
    private const ALLOWED_TABLES = [
        'marketplace_sales',
        'marketplace_returns',
        'marketplace_costs',
    ];

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @param list<string> $documentIds
     */
    public function execute(string $table, string $companyId, string $rawDocumentId, array $documentIds): int
    {
        $this->assertKnownTable($table);

        if ([] === $documentIds) {
            return 0;
        }

        return (int) $this->connection->executeStatement(
            sprintf(
                'UPDATE %s
                 SET document_id = NULL,
                     updated_at = NOW()
                 WHERE company_id = :companyId
                   AND raw_document_id = :rawDocumentId
                   AND document_id IN (:documentIds)',
                $table,
            ),
            [
                'companyId' => $companyId,
                'rawDocumentId' => $rawDocumentId,
                'documentIds' => $documentIds,
            ],
            [
                'documentIds' => ArrayParameterType::STRING,
            ],
        );
    }

    /**
     * Снять привязку со всех привязанных строк документа, какому бы документу
     * ОПиУ они ни принадлежали.
     *
     * Нужно для закрытий, у которых список id документов не сохранён: такие
     * встречаются у старых и повреждённых закрытий, и `ReopenMonthStageAction`
     * ровно поэтому умеет снимать привязки по периоду. Без этой ветки строки
     * остались бы привязанными, удаление их не тронуло бы, и правка Ozon не
     * доехала бы — при том что замена отчиталась бы как выполненная.
     *
     * Охват по-прежнему один raw-документ: это один день, и месяц у всех его
     * строк общий, поэтому предварительность этапа, проверенная вызывающим,
     * относится к ним всем.
     */
    public function executeAllLinked(string $table, string $companyId, string $rawDocumentId): int
    {
        $this->assertKnownTable($table);

        return (int) $this->connection->executeStatement(
            sprintf(
                'UPDATE %s
                 SET document_id = NULL,
                     updated_at = NOW()
                 WHERE company_id = :companyId
                   AND raw_document_id = :rawDocumentId
                   AND document_id IS NOT NULL',
                $table,
            ),
            [
                'companyId' => $companyId,
                'rawDocumentId' => $rawDocumentId,
            ],
        );
    }

    private function assertKnownTable(string $table): void
    {
        // Имя таблицы подставляется в SQL, поэтому оно приходит только из
        // белого списка: параметризовать идентификатор нельзя.
        if (!in_array($table, self::ALLOWED_TABLES, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown marketplace table: %s', $table));
        }
    }
}
