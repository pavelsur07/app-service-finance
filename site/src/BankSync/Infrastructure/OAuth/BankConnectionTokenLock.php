<?php

declare(strict_types=1);

namespace App\BankSync\Infrastructure\OAuth;

use App\BankSync\Exception\BankConnectionLockOutsideTransactionException;
use Doctrine\DBAL\Connection;

/**
 * Один процесс меняет токены подключения: обновление refresh (воркер) и новый вход
 * (callback) под одним ключом. У Альфы refresh годится на два обновления — параллельное
 * обновление его сожжёт.
 *
 * - `acquire()` / `release()` — сессионная блокировка воркера: HTTP обновления идёт под
 *   ней, но вне транзакции; умер воркер — PostgreSQL снял блокировку.
 * - `lockInTransaction()` — транзакционная, для записи нового входа; с сессионной того же
 *   ключа конфликтует.
 *
 * Порядок: блокировка подключения берётся раньше блокировки счёта импорта (Cash).
 */
final class BankConnectionTokenLock
{
    private const NAMESPACE = 'bank_sync_connection_token';
    private const POLL_INTERVAL_MICROSECONDS = 250_000;

    /** @var \Closure(int): void */
    private \Closure $sleep;

    /**
     * @param (\Closure(int): void)|null $sleep пауза между попытками, мкс; подменяется в тестах
     */
    public function __construct(
        private readonly Connection $connection,
        ?\Closure $sleep = null,
    ) {
        $this->sleep = $sleep ?? static function (int $microseconds): void {
            usleep($microseconds);
        };
    }

    /**
     * @return bool false — блокировку держали дольше `$timeoutSeconds`
     */
    public function acquire(string $connectionId, int $timeoutSeconds): bool
    {
        $attempts = max(1, (int) ceil($timeoutSeconds * 1_000_000 / self::POLL_INTERVAL_MICROSECONDS));
        for ($attempt = 1; $attempt <= $attempts; ++$attempt) {
            if ((bool) $this->connection->fetchOne(
                'SELECT pg_try_advisory_lock(hashtext(:namespace), hashtext(:key))',
                ['namespace' => self::NAMESPACE, 'key' => $connectionId],
            )) {
                return true;
            }
            if ($attempt < $attempts) {
                ($this->sleep)(self::POLL_INTERVAL_MICROSECONDS);
            }
        }

        return false;
    }

    public function release(string $connectionId): void
    {
        $this->connection->fetchOne(
            'SELECT pg_advisory_unlock(hashtext(:namespace), hashtext(:key))',
            ['namespace' => self::NAMESPACE, 'key' => $connectionId],
        );
    }

    /**
     * Только внутри транзакции: вне её PostgreSQL снимет блокировку сразу после запроса.
     */
    public function lockInTransaction(string $connectionId): void
    {
        if (!$this->connection->isTransactionActive()) {
            throw new BankConnectionLockOutsideTransactionException();
        }

        $this->connection->fetchOne(
            'SELECT pg_advisory_xact_lock(hashtext(:namespace), hashtext(:key))',
            ['namespace' => self::NAMESPACE, 'key' => $connectionId],
        );
    }
}
