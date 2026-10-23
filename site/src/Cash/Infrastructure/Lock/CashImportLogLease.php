<?php

declare(strict_types=1);

namespace App\Cash\Infrastructure\Lock;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * Lease журнала импорта без задания (синхронный 1С, банк по API): сессионная advisory-блокировка
 * по ID журнала держится, пока импорт идёт. Умер процесс — закрылось соединение — PostgreSQL снял
 * блокировку, и журнал можно закрывать ремонтом. Журнал файлового импорта живость берёт из
 * статуса задания (`queued`/`processing`).
 */
final readonly class CashImportLogLease
{
    private const NAMESPACE = 'cash_import_log';

    public function __construct(
        private Connection $connection,
        private LoggerInterface $logger,
    ) {
    }

    public function acquire(string $importLogId): void
    {
        // ID журнала свежий — блокировку никто не держит, ожидания нет.
        $this->connection->fetchOne(
            'SELECT pg_advisory_lock(hashtext(:namespace), hashtext(:id))',
            ['namespace' => self::NAMESPACE, 'id' => $importLogId],
        );
    }

    /**
     * Вызывается из `finally` импорта: сбой снятия не подменяет исходное исключение. Соединение
     * потеряно — PostgreSQL снял сессионную блокировку сам.
     */
    public function release(string $importLogId): void
    {
        try {
            $this->connection->fetchOne(
                'SELECT pg_advisory_unlock(hashtext(:namespace), hashtext(:id))',
                ['namespace' => self::NAMESPACE, 'id' => $importLogId],
            );
        } catch (\Throwable $exception) {
            $this->logger->warning('Cash import log lease release failed', [
                'import_log' => $importLogId,
                'exception_class' => $exception::class,
            ]);
        }
    }

    /**
     * SQL-условие «lease журнала держит живой импорт» для `$idColumn` (UUID журнала). Ключ из двух
     * int4 лежит в `pg_locks` как classid/objid с objsubid = 2.
     */
    public static function heldCondition(string $idColumn): string
    {
        return \sprintf(
            <<<'SQL'
                EXISTS (
                    SELECT 1 FROM pg_locks pl
                    WHERE pl.locktype = 'advisory' AND pl.objsubid = 2 AND pl.granted
                      AND pl.database = (SELECT oid FROM pg_database WHERE datname = current_database())
                      AND pl.classid = hashtext('%s')::oid
                      AND pl.objid = hashtext(%s::text)::oid
                )
                SQL,
            self::NAMESPACE,
            $idColumn,
        );
    }
}
