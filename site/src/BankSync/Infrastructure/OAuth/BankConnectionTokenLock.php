<?php

declare(strict_types=1);

namespace App\BankSync\Infrastructure\OAuth;

use Doctrine\DBAL\Connection;

/**
 * Один процесс меняет учётные данные и статус подключения: обновление refresh и перевод
 * в `needs_reauth` (воркер), новый вход (callback) и сохранение формы — под одним ключом.
 * У Альфы refresh годится на два обновления — параллельное обновление его сожжёт.
 *
 * Блокировка сессионная: HTTP к банку идёт под ней, но вне транзакции; умер процесс —
 * PostgreSQL снял блокировку. Держатель перечитывает строку подключения после захвата.
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
}
