<?php

declare(strict_types=1);

namespace App\Cash\Infrastructure\Query;

use Doctrine\DBAL\Connection;

/** Отчёт, права, выбор встречной операции и реквизиты для сверки в одном состоянии группы. */
final readonly class CashflowGroupSnapshot
{
    public function __construct(private Connection $connection)
    {
    }

    /**
     * @template T
     *
     * @param callable():T $read
     *
     * @return T
     */
    public function read(callable $read): mixed
    {
        $native = $this->connection->getNativeConnection();
        if ($this->connection->isTransactionActive() || ($native instanceof \PDO && $native->inTransaction())) {
            $isolation = $this->connection->fetchOne('SHOW transaction_isolation');
            if (!\in_array($isolation, ['repeatable read', 'serializable'], true)) {
                throw new \LogicException('Для консолидированного отчёта нужна транзакция REPEATABLE READ или SERIALIZABLE.');
            }

            return $read();
        }

        return $this->connection->transactional(function () use ($read): mixed {
            $this->connection->executeStatement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');

            return $read();
        });
    }
}
