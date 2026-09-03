<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Application;

use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Ingestion\Application\Action\RefreshOrderStatusesAction;
use App\Ingestion\Application\Command\RefreshOrderStatusesCommand;
use App\Ingestion\Enum\IngestOrderScheme;
use App\Ingestion\Enum\IngestOrderStatus;
use App\Ingestion\Enum\IngestSource;
use App\Marketplace\Entity\MarketplaceConnection;
use App\Marketplace\Enum\MarketplaceConnectionType;
use App\Marketplace\Enum\MarketplaceType;
use App\Tests\Builders\Ingestion\IngestOrderBuilder;
use App\Tests\Support\Kernel\PostgresResetTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\Exception as DriverException;
use Doctrine\DBAL\DriverManager;
use Psr\Log\AbstractLogger;
use Ramsey\Uuid\Uuid;

/**
 * ПОРЯДОК блокировок, а не факт их наличия.
 *
 * Нормализация начинает с сырья и только потом берёт заказы — иначе она не
 * может, разбор идёт от сырья. Значит порядок задаёт она: сначала сырьё, потом
 * заказы. Часовая остановка зависших брала их наоборот, и две транзакции
 * складывались в цикл: крон держал заказ и ждал сырьё, нормализатор держал
 * сырьё и ждал заказ. PostgreSQL разрывает такой цикл, убивая одну из
 * транзакций: пачка остановки откатывалась целиком, а часовой прогон падал.
 *
 * Момент наблюдения даёт лог о взятых блокировках сырья: он пишется внутри
 * транзакции, после блокировки сырья и до блокировки заказов.
 *
 * DAMA-откат выключен (`PostgresResetTestCase`): второе соединение не увидело
 * бы незакоммиченных данных первого.
 */
final class RefreshOrderStatusesLockingTest extends PostgresResetTestCase
{
    private const CONNECTION_ID = '77777777-7777-7777-7777-0000000ff0d1';

    public function testEvidenceIsLockedBeforeTheOrderItBelongsTo(): void
    {
        [$orderId, $rawRecordId] = $this->seedStuckOrderWithEvidence();

        $observer = $this->newConnection();
        // Иначе NOWAIT-проверки ждали бы прогон до конца теста.
        $observer->executeStatement("SET lock_timeout = '250ms'");

        $rawLocked = null;
        $orderStillFree = null;

        $probe = new class($observer, $orderId, $rawRecordId, $rawLocked, $orderStillFree) extends AbstractLogger {
            /**
             * @param ?bool $rawLocked держит ли прогон блокировку сырья
             * @param ?bool $orderStillFree свободен ли ещё заказ
             */
            public function __construct(
                private readonly Connection $observer,
                private readonly string $orderId,
                private readonly string $rawRecordId,
                private ?bool &$rawLocked,
                private ?bool &$orderStillFree,
            ) {
            }

            public function rawLocked(): ?bool
            {
                return $this->rawLocked;
            }

            public function orderStillFree(): ?bool
            {
                return $this->orderStillFree;
            }

            /**
             * @param mixed[] $context
             */
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                if (!str_contains((string) $message, 'locked evidence rows before the orders')) {
                    return;
                }

                $this->rawLocked = !$this->free('ingest_raw_records', $this->rawRecordId);
                $this->orderStillFree = $this->free('ingest_orders', $this->orderId);
            }

            private function free(string $table, string $id): bool
            {
                $this->observer->beginTransaction();

                try {
                    $this->observer->executeQuery(
                        sprintf('SELECT id FROM %s WHERE id = :id FOR UPDATE NOWAIT', $table),
                        ['id' => $id],
                    );

                    return true;
                } catch (\Throwable $exception) {
                    if (!RefreshOrderStatusesLockingTest::isLockTimeout($exception)) {
                        throw $exception;
                    }

                    return false;
                } finally {
                    $this->observer->rollBack();
                }
            }
        };

        $action = new RefreshOrderStatusesAction(
            self::getContainer()->get(\App\Marketplace\Facade\MarketplaceSyncFacade::class),
            self::getContainer()->get(\App\Ingestion\Repository\IngestOrderRepository::class),
            self::getContainer()->get(\App\Ingestion\Infrastructure\Api\Ozon\OzonOrdersClientInterface::class),
            self::getContainer()->get(\App\Ingestion\Infrastructure\Api\Wildberries\WbOrdersClientInterface::class),
            self::getContainer()->get(\App\Ingestion\Domain\Service\IngestOrderStatusMapper::class),
            self::getContainer()->get(\App\Ingestion\Application\Service\OrderStatusJournal::class),
            self::getContainer()->get(\App\Ingestion\Facade\RawStorageFacade::class),
            self::getContainer()->get(\App\Ingestion\Repository\IngestRawRecordRepository::class),
            self::getContainer()->get(\App\Ingestion\Application\Action\RecordNormalizationIssueAction::class),
            $this->em,
            self::getContainer()->get(\Psr\Clock\ClockInterface::class),
            $probe,
        );

        try {
            $action(new RefreshOrderStatusesCommand(days: 30, limitPerConnection: 100));
        } finally {
            $observer->close();
        }

        self::assertTrue($rawLocked, 'Сырьё обязано быть заблокировано первым.');
        self::assertTrue(
            $orderStillFree,
            'Заказ не должен быть заблокирован раньше сырья: обратный порядок складывается с нормализацией в цикл.',
        );
    }

    /**
     * Чужое сырьё НЕ блокируется, даже если на него ссылается наш заказ.
     *
     * Указатель заказа — данные, и они могут быть повреждены. Заказ компании
     * A, ссылающийся на сырьё компании B, брал бы `PESSIMISTIC_WRITE` на
     * чужую строку и задерживал чужой ingestion. Владелец выясняется до
     * блокировки, а несовпадение считается отсутствующим доказательством:
     * заказ останавливается без записи в очереди, как и заказ вовсе без сырья.
     */
    public function testForeignEvidenceIsNeverLockedAndTheOrderIsStoppedWithoutAnIssue(): void
    {
        [$orderId, $rawRecordId] = $this->seedStuckOrderWithEvidence();

        // Сырьё «переезжает» к другой компании: указатель заказа остаётся.
        $foreignCompanyId = Uuid::uuid7()->toString();
        $this->connection->executeStatement(
            'UPDATE ingest_raw_records SET company_id = :company WHERE id = :id',
            ['company' => $foreignCompanyId, 'id' => $rawRecordId],
        );

        $observer = $this->newConnection();
        $observer->executeStatement("SET lock_timeout = '250ms'");

        $rawFree = null;

        $probe = new class($observer, $rawRecordId, $rawFree) extends AbstractLogger {
            /**
             * @param ?bool $rawFree свободна ли чужая строка в момент блокировки заказов
             */
            public function __construct(
                private readonly Connection $observer,
                private readonly string $rawRecordId,
                private ?bool &$rawFree,
            ) {
            }

            public function rawFree(): ?bool
            {
                return $this->rawFree;
            }

            /**
             * @param mixed[] $context
             */
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                if (!str_contains((string) $message, 'locked evidence rows before the orders')) {
                    return;
                }

                $this->observer->beginTransaction();

                try {
                    $this->observer->executeQuery(
                        'SELECT id FROM ingest_raw_records WHERE id = :id FOR UPDATE NOWAIT',
                        ['id' => $this->rawRecordId],
                    );
                    $this->rawFree = true;
                } catch (\Throwable $exception) {
                    if (!RefreshOrderStatusesLockingTest::isLockTimeout($exception)) {
                        throw $exception;
                    }

                    $this->rawFree = false;
                } finally {
                    $this->observer->rollBack();
                }
            }
        };

        $action = new RefreshOrderStatusesAction(
            self::getContainer()->get(\App\Marketplace\Facade\MarketplaceSyncFacade::class),
            self::getContainer()->get(\App\Ingestion\Repository\IngestOrderRepository::class),
            self::getContainer()->get(\App\Ingestion\Infrastructure\Api\Ozon\OzonOrdersClientInterface::class),
            self::getContainer()->get(\App\Ingestion\Infrastructure\Api\Wildberries\WbOrdersClientInterface::class),
            self::getContainer()->get(\App\Ingestion\Domain\Service\IngestOrderStatusMapper::class),
            self::getContainer()->get(\App\Ingestion\Application\Service\OrderStatusJournal::class),
            self::getContainer()->get(\App\Ingestion\Facade\RawStorageFacade::class),
            self::getContainer()->get(\App\Ingestion\Repository\IngestRawRecordRepository::class),
            self::getContainer()->get(\App\Ingestion\Application\Action\RecordNormalizationIssueAction::class),
            $this->em,
            self::getContainer()->get(\Psr\Clock\ClockInterface::class),
            $probe,
        );

        try {
            $result = $action(new RefreshOrderStatusesCommand(days: 30, limitPerConnection: 100));
        } finally {
            $observer->close();
        }

        self::assertTrue($rawFree, 'Чужая строка сырья не имеет права быть заблокированной нашим прогоном.');
        self::assertSame(1, $result->stopped, 'Заказ обязан быть остановлен: доказательства у него нет.');
        self::assertSame(
            0,
            (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM ingest_normalization_issues WHERE raw_record_id = :id',
                ['id' => $rawRecordId],
            ),
            'Проблема на чужое сырьё не заводится.',
        );
        self::assertNotNull(
            $this->connection->fetchOne('SELECT refresh_stopped_at FROM ingest_orders WHERE id = :id', ['id' => $orderId]),
        );
    }

    /**
     * Запись ответов ЖДЁТ замок подключения — тот же, что берёт нормализация.
     *
     * Номер маркетплейса присваивается нормализацией и применяется
     * перепросом; без общего замка их шаги переплетались, и ответ на номер,
     * присвоенный тем временем ещё одному заказу, ложился на первого
     * носителя. Замок держит отдельное соединение — как держала бы
     * нормализация, — и прогон обязан упереться в него, а не записать ответ
     * мимо.
     */
    public function testApplyingAnswersWaitsForTheConnectionScopeLock(): void
    {
        [$orderId] = $this->seedStuckOrderWithEvidence(orderedDaysAgo: 2);
        $companyId = (string) $this->connection->fetchOne('SELECT company_id FROM ingest_orders WHERE id = :id', ['id' => $orderId]);

        /** @var \App\Tests\Integration\Ingestion\Fixtures\FakeOzonOrdersClient $ozon */
        $ozon = self::getContainer()->get(\App\Ingestion\Infrastructure\Api\Ozon\OzonOrdersClientInterface::class);
        $ozon->setPostings(['ancient' => ['posting_number' => 'ancient', 'status' => 'delivered']]);

        $holder = $this->newConnection();
        $holder->beginTransaction();
        $holder->executeStatement(
            'SELECT pg_advisory_xact_lock(hashtext(:scope))',
            ['scope' => sprintf('ingest_orders:%s:%s:%s', $companyId, 'ozon', self::CONNECTION_ID)],
        );

        try {
            // Иначе прогон ждал бы держателя до конца теста.
            $this->connection->executeStatement("SET lock_timeout = '250ms'");

            $action = self::getContainer()->get(RefreshOrderStatusesAction::class);
            $blocked = false;

            try {
                $action(new RefreshOrderStatusesCommand(days: 30, limitPerConnection: 100));
            } catch (\Throwable $exception) {
                if (!self::isLockTimeout($exception)) {
                    throw $exception;
                }

                $blocked = true;
            }

            self::assertTrue($blocked, 'Запись ответов обязана ждать замок подключения.');
            self::assertSame(
                'shipped',
                $holder->fetchOne('SELECT status FROM ingest_orders WHERE id = :id', ['id' => $orderId]),
                'Ответ не должен быть записан в обход замка.',
            );
        } finally {
            $this->connection->executeStatement('SET lock_timeout = 0');
            $holder->rollBack();
            $holder->close();
        }
    }

    /**
     * @return array{0: string, 1: string} идентификаторы заказа и его сырья
     */
    private function seedStuckOrderWithEvidence(int $orderedDaysAgo = 90): array
    {
        $user = new User(Uuid::uuid4()->toString());
        $user->setEmail('refresh-locks-'.Uuid::uuid4()->toString().'@example.com');
        $user->setPassword('password');

        $company = new Company(Uuid::uuid4()->toString(), $user);
        $company->setName('Refresh Locks Company');

        $connection = new MarketplaceConnection(
            id: self::CONNECTION_ID,
            company: $company,
            marketplace: MarketplaceType::OZON,
            connectionType: MarketplaceConnectionType::SELLER,
        );
        $connection->setApiKey('test-key');
        $connection->setClientId('test-client-id');
        $connection->setIsActive(true);

        $this->em->persist($user);
        $this->em->persist($company);
        $this->em->persist($connection);
        $this->em->flush();

        $rawRecordId = Uuid::uuid7()->toString();
        $this->connection->executeStatement(
            "INSERT INTO ingest_raw_records
                 (id, company_id, connection_ref, shop_ref, source, resource_type, external_id,
                  storage_path, hash, byte_size, fetched_at, last_seen_at, sync_job_id,
                  normalization_status, created_at, updated_at)
             VALUES
                 (:id, :company, :connection, 'shop-main', 'ozon', 'orders_fixture', 'page-1',
                  :path, :hash, 128, now(), now(), :job, 'done', now(), now())",
            [
                'id' => $rawRecordId,
                'company' => (string) $company->getId(),
                'connection' => self::CONNECTION_ID,
                'path' => 'company/ozon/shop/orders/'.$rawRecordId.'.ndjson.gz',
                'hash' => hash('sha256', $rawRecordId),
                'job' => Uuid::uuid7()->toString(),
            ],
        );

        $order = IngestOrderBuilder::anOrder()
            ->forCompany((string) $company->getId())
            ->withConnectionRef(self::CONNECTION_ID)
            ->withSource(IngestSource::OZON)
            ->withScheme(IngestOrderScheme::FBO)
            ->withExternalId('ancient')
            ->withStatus(IngestOrderStatus::SHIPPED, 'delivering')
            ->withLastRawRecordId($rawRecordId)
            ->orderedAt(new \DateTimeImmutable(sprintf('-%d days', $orderedDaysAgo)))
            ->build();

        $this->em->persist($order);
        $this->em->flush();

        return [$order->getId(), $rawRecordId];
    }

    private function newConnection(): Connection
    {
        return DriverManager::getConnection($this->connection->getParams());
    }

    /**
     * Это ИМЕННО ожидание блокировки, а не любая ошибка.
     *
     * `catch (\Throwable)` красил тест зелёным от чего угодно: опечатки в SQL,
     * отсутствующей колонки, закрытого соединения, исключения самого Action.
     * Регрессия в протоколе, который защищает от необратимого удаления, могла
     * бы спрятаться за посторонним сбоем. PostgreSQL сообщает о вышедшем
     * `lock_timeout` кодом `55P03`, и принимается только он.
     */
    public static function isLockTimeout(\Throwable $exception): bool
    {
        for ($error = $exception; null !== $error; $error = $error->getPrevious()) {
            if ($error instanceof DriverException && '55P03' === $error->getSQLState()) {
                return true;
            }
        }

        return false;
    }
}
