<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Application;

use App\Ingestion\Application\Action\PruneRawRecordsAction;
use App\Ingestion\Application\Command\PruneRawRecordsCommand;
use App\Ingestion\Repository\IngestRawRecordRepository;
use App\Shared\Service\Storage\ObjectStorageInterface;
use App\Tests\Support\Kernel\PostgresResetTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Psr\Clock\ClockInterface;
use Psr\Log\AbstractLogger;
use Ramsey\Uuid\Uuid;

/**
 * Проверка НАСТОЯЩЕЙ блокировки, а не перечитывания.
 *
 * Остальные тесты retention вносят конкурента тем же соединением и потому
 * доказывают только повторный запрос и `HINT_REFRESH`: снятие
 * `PESSIMISTIC_WRITE` их не покраснит. Здесь конкурент — отдельное соединение
 * с собственной транзакцией, то есть ровно то, от чего блокировка и защищает.
 *
 * DAMA-откат выключен (`PostgresResetTestCase`): второе соединение не увидело
 * бы незакоммиченных данных первого.
 */
final class PruneRawRecordsLockingTest extends PostgresResetTestCase
{
    /**
     * Пока конкурент держит строку сырья, prune не имеет права её удалить.
     *
     * Ожидание ограничено `lock_timeout`: без блокировки prune не стал бы
     * ждать вовсе и удалил бы запись, которую в этот момент правит другой.
     */
    public function testPruneWaitsForARowLockedByAnotherTransaction(): void
    {
        $rawRecordId = $this->seedStaleRawRecord();

        $competitor = $this->newConnection();
        $competitor->beginTransaction();
        $competitor->executeQuery(
            'SELECT id FROM ingest_raw_records WHERE id = :id FOR UPDATE',
            ['id' => $rawRecordId],
        );

        try {
            // Иначе prune ждал бы конкурента до конца теста.
            $this->connection->executeStatement("SET lock_timeout = '250ms'");

            $action = self::getContainer()->get(PruneRawRecordsAction::class);

            $failed = false;

            try {
                $action(new PruneRawRecordsCommand(olderThanDays: 365, limit: 100, execute: true));
            } catch (\Throwable) {
                // Ожидание блокировки прервано таймаутом — это и есть
                // доказательство, что блокировка бралась.
                $failed = true;
            }

            self::assertTrue($failed, 'Prune обязан ждать чужую блокировку, а не удалять строку мимо неё.');

            self::assertSame(
                1,
                (int) $competitor->fetchOne('SELECT COUNT(*) FROM ingest_raw_records WHERE id = :id', ['id' => $rawRecordId]),
                'Строка обязана пережить попытку удаления.',
            );
        } finally {
            $this->connection->executeStatement('SET lock_timeout = 0');
            $competitor->rollBack();
            $competitor->close();
        }
    }

    /**
     * Конкурент не может подтвердить запись, пока prune её удаляет.
     *
     * Это и есть роль блокировки НА ВЫБОРКЕ. Без неё чтение остаётся снимком:
     * конкурент успевает обновить `lastSeenAt` и закоммитить между выборкой и
     * удалением, а Doctrine удаляет строку по идентификатору, ничего не
     * перепроверяя, — свежее сырьё пропадает.
     *
     * Момент вмешательства даёт логгер: сообщение о предстоящем удалении
     * пишется внутри транзакции, после выборки и до flush.
     */
    public function testConcurrentRefreshCannotSlipBetweenSelectionAndDeletion(): void
    {
        $rawRecordId = $this->seedStaleRawRecord();

        $competitor = $this->newConnection();
        // Иначе конкурент ждал бы prune до конца теста.
        $competitor->executeStatement("SET lock_timeout = '250ms'");

        $refreshed = null;

        $action = new PruneRawRecordsAction(
            self::getContainer()->get(IngestRawRecordRepository::class),
            self::getContainer()->get(ObjectStorageInterface::class),
            $this->em,
            self::getContainer()->get(ClockInterface::class),
            new class($competitor, $rawRecordId, $refreshed) extends AbstractLogger {
                /**
                 * @param ?bool $refreshed результат конкурента, читается тестом по ссылке
                 */
                public function __construct(
                    private readonly Connection $competitor,
                    private readonly string $rawRecordId,
                    private ?bool &$refreshed,
                ) {
                }

                public function competitorSucceeded(): ?bool
                {
                    return $this->refreshed;
                }

                /**
                 * @param mixed[] $context
                 */
                public function log($level, string|\Stringable $message, array $context = []): void
                {
                    if (!str_contains((string) $message, 'about to be deleted')) {
                        return;
                    }

                    try {
                        $this->competitor->executeStatement(
                            'UPDATE ingest_raw_records SET last_seen_at = now() WHERE id = :id',
                            ['id' => $this->rawRecordId],
                        );
                        $this->refreshed = true;
                    } catch (\Throwable) {
                        $this->refreshed = false;
                    }
                }
            },
        );

        try {
            $action(new PruneRawRecordsCommand(olderThanDays: 365, limit: 100, execute: true));
        } finally {
            $competitor->close();
        }

        self::assertFalse(
            $refreshed,
            'Подтверждение записи обязано ждать: иначе prune удалит уже свежее сырьё.',
        );
    }

    private function seedStaleRawRecord(): string
    {
        $id = Uuid::uuid7()->toString();
        $old = (new \DateTimeImmutable('-400 days'))->format('Y-m-d H:i:s.u');

        $this->connection->executeStatement(
            "INSERT INTO ingest_raw_records
                 (id, company_id, connection_ref, shop_ref, source, resource_type, external_id,
                  storage_path, hash, byte_size, fetched_at, last_seen_at, sync_job_id,
                  normalization_status, created_at, updated_at)
             VALUES
                 (:id, :company, 'conn-1', 'shop-main', 'ozon', 'prune_fixture', 'page-1',
                  'company/ozon/shop/prune/2025/01/01/job/page-1/hash.ndjson.gz', 'hash', 128,
                  :old, :old, :job, 'done', now(), now())",
            [
                'id' => $id,
                'company' => Uuid::uuid7()->toString(),
                'old' => $old,
                'job' => Uuid::uuid7()->toString(),
            ],
        );

        return $id;
    }

    private function newConnection(): Connection
    {
        return DriverManager::getConnection($this->connection->getParams());
    }
}
