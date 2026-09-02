<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Application;

use App\Ingestion\Application\Action\PruneRawRecordsAction;
use App\Ingestion\Application\Command\PruneRawRecordsCommand;
use App\Ingestion\Application\ReadRawRecordAction;
use App\Ingestion\DTO\RawBatch;
use App\Ingestion\Entity\NormalizationIssue;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Enum\NormalizationIssueKind;
use App\Ingestion\Exception\RawPayloadPrunedException;
use App\Ingestion\Facade\RawStorageFacade;
use App\Ingestion\Repository\IngestRawRecordRepository;
use App\Shared\Service\Storage\ObjectStorageInterface;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Ramsey\Uuid\Uuid;

final class PruneRawRecordsActionTest extends IntegrationTestCase
{
    private const CONNECTION_REF = '0192f0c2-0000-7000-8000-00000000c001';

    private PruneRawRecordsAction $action;
    private IngestRawRecordRepository $rawRecords;
    private ObjectStorageInterface $storage;
    private string $companyId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = self::getContainer()->get(PruneRawRecordsAction::class);
        $this->rawRecords = self::getContainer()->get(IngestRawRecordRepository::class);
        $this->storage = self::getContainer()->get(ObjectStorageInterface::class);
        $this->companyId = Uuid::uuid7()->toString();
    }

    /**
     * Ради этого удаление и живёт в приложении: lifecycle-правило хранилища
     * видит только объекты и оставило бы запись без всякого признака того, что
     * нагрузка удалена намеренно — чтение падало бы невнятной ошибкой
     * хранилища.
     */
    public function testDeletesThePayloadAndMarksTheRecord(): void
    {
        $record = $this->seedRaw('page-1', new \DateTimeImmutable('-400 days'));
        $path = $record['path'];

        self::assertTrue($this->storage->exists($path));

        $result = ($this->action)(new PruneRawRecordsCommand(olderThanDays: 365, limit: 100, execute: true));

        self::assertSame(1, $result->candidates);
        self::assertSame(1, $result->prunedPayloads);
        self::assertGreaterThan(0, $result->bytesFreed);
        self::assertSame(0, $result->orphanedObjects);

        // Строка ОСТАЁТСЯ: указатели на неё продолжают разрешаться, дедупу
        // есть что обновлять, а чтение отвечает внятной ошибкой вместо сбоя
        // хранилища.
        $kept = $this->rawRecords->findByIdAndCompany($record['id'], $this->companyId);
        self::assertNotNull($kept);
        self::assertNotNull($kept->getPayloadPrunedAt(), 'Запись обязана знать, что нагрузки больше нет.');
        self::assertFalse($this->storage->exists($path), 'Объект обязан уйти.');
    }

    public function testDryRunTouchesNothing(): void
    {
        $record = $this->seedRaw('page-1', new \DateTimeImmutable('-400 days'));

        $result = ($this->action)(new PruneRawRecordsCommand(olderThanDays: 365, limit: 100, execute: false));

        self::assertSame(1, $result->candidates, 'Кандидат виден…');
        self::assertSame(0, $result->prunedPayloads, '…но не тронут.');

        // Ради этого числа dry-run и запускается перед Production Gate:
        // решение включать удаление принимается по объёму, а не по числу строк.
        self::assertGreaterThan(0, $result->candidateBytes, 'Объём обязан быть виден до удаления.');

        self::assertNotNull($this->rawRecords->findByIdAndCompany($record['id'], $this->companyId));
        self::assertTrue($this->storage->exists($record['path']));
    }

    /**
     * Возраст считается по «когда последний раз видели», а не по «когда
     * впервые скачали». Дедуп при часовом опросе обновляет отметку, не создавая
     * новую запись: удалив подтверждаемую запись, мы получили бы её обратно
     * следующим же прогоном под новым идентификатором.
     */
    public function testRecordStillSeenRecentlyIsKept(): void
    {
        // Скачано давно, но подтверждается до сих пор. Отметки РАЗНЫЕ
        // намеренно: с одинаковыми тест остался бы зелёным и в том случае,
        // если бы возраст считался по `fetchedAt` — то есть не защищал бы
        // правило, ради которого написан.
        $this->seedRaw(
            'page-1',
            new \DateTimeImmutable('-2 days'),
            new \DateTimeImmutable('-400 days'),
        );

        $result = ($this->action)(new PruneRawRecordsCommand(olderThanDays: 365, limit: 100, execute: true));

        self::assertSame(0, $result->candidates);
        self::assertSame(0, $result->prunedPayloads);
    }

    /**
     * Сырьё открытой проблемы — то, с чего начинает разбирающий. Удалив его,
     * мы оставили бы очередь на разбор без единственного доказательства.
     */
    public function testRecordHeldByUnresolvedIssueSurvivesUntilItIsResolved(): void
    {
        $record = $this->seedRaw('page-1', new \DateTimeImmutable('-400 days'));

        $issue = new NormalizationIssue(
            $this->companyId,
            $record['id'],
            null,
            NormalizationIssueKind::MAPPER_FAILURE,
            [],
        );
        $this->em->persist($issue);
        $this->em->flush();

        $held = ($this->action)(new PruneRawRecordsCommand(olderThanDays: 365, limit: 100, execute: true));

        self::assertSame(0, $held->candidates);
        self::assertSame(1, $held->heldByIssues, 'Удержание обязано быть видно отдельно от «нечего удалять».');
        self::assertNotNull($this->rawRecords->findByIdAndCompany($record['id'], $this->companyId));

        // Состояние не вечное: разобранная проблема отпускает сырьё.
        $issue->markResolved(new \DateTimeImmutable());
        $this->em->flush();

        $released = ($this->action)(new PruneRawRecordsCommand(olderThanDays: 365, limit: 100, execute: true));

        self::assertSame(1, $released->prunedPayloads);
        self::assertSame(0, $released->heldByIssues);
        self::assertFalse($this->storage->exists($record['path']));
    }

    /**
     * Упавшее удаление объекта не оставляет записи, которая утверждает, что
     * нагрузка на месте, и не молчит: запись помечена, объект остаётся
     * сиротой, счётчик это показывает, а путь уходит в лог.
     *
     * Тест проверяет именно ЭТО, а не порядок операций: при сбое удаления
     * объекта оба порядка дают одинаковый результат. Сам порядок (строка, потом
     * объект) выбран разбором отказов, а не доказан тестом — различает их
     * только падение процесса МЕЖДУ двумя шагами, которое в наборе не
     * воспроизвести. Зафиксировано как ограничение проверки.
     */
    public function testFailedObjectDeletionIsReportedAndTheRecordStopsClaimingThePayload(): void
    {
        $record = $this->seedRaw('page-1', new \DateTimeImmutable('-400 days'));

        $action = new PruneRawRecordsAction(
            $this->rawRecords,
            new class($this->storage) implements ObjectStorageInterface {
                public function __construct(private readonly ObjectStorageInterface $inner)
                {
                }

                public function write(string $path, string $contents): \App\Shared\Service\Storage\StoredObject
                {
                    return $this->inner->write($path, $contents);
                }

                public function read(string $path): string
                {
                    return $this->inner->read($path);
                }

                public function readStream(string $path)
                {
                    return $this->inner->readStream($path);
                }

                public function exists(string $path): bool
                {
                    return $this->inner->exists($path);
                }

                public function delete(string $path): void
                {
                    throw new \RuntimeException('storage is unavailable');
                }
            },
            $this->em,
            self::getContainer()->get(ClockInterface::class),
            $logger = new class extends AbstractLogger {
                /** @var list<array{level: mixed, message: string, context: mixed[]}> */
                public array $records = [];

                /**
                 * @param mixed[] $context
                 */
                public function log($level, string|\Stringable $message, array $context = []): void
                {
                    $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
                }
            },
        );

        $result = $action(new PruneRawRecordsCommand(olderThanDays: 365, limit: 100, execute: true));

        self::assertSame(1, $result->prunedPayloads);
        self::assertSame(1, $result->orphanedObjects);
        self::assertSame(0, $result->bytesFreed, 'Место не освободилось — отчёт не должен утверждать обратное.');

        // Путь — единственное, по чему сироту можно найти и убрать руками.
        // Без этой проверки его исчезновение из контекста прошло бы все тесты.
        $errors = array_values(array_filter(
            $logger->records,
            static fn (array $entry): bool => LogLevel::ERROR === $entry['level'],
        ));

        self::assertCount(1, $errors);
        self::assertSame($record['path'], $errors[0]['context']['storagePath']);
        self::assertSame($record['id'], $errors[0]['context']['rawRecordId']);
        self::assertSame(\RuntimeException::class, $errors[0]['context']['exceptionClass']);
        self::assertArrayNotHasKey('exceptionMessage', $errors[0]['context'], 'Сообщения хранилища несут URL с учётными данными.');

        self::assertTrue($this->storage->exists($record['path']), 'Объект остался — он и есть сирота.');

        $this->em->clear();
        $record = $this->rawRecords->findByIdAndCompany($record['id'], $this->companyId);
        self::assertNotNull($record);
        self::assertNotNull(
            $record->getPayloadPrunedAt(),
            'Запись не должна утверждать, что нагрузка на месте, если её удаляли.',
        );
    }

    /**
     * Проблема, заведённая ПОСЛЕ выборки кандидатов, обязана удержать сырьё.
     *
     * `FOR UPDATE` блокирует строку сырья, но не отсутствие строки в таблице
     * проблем: условие внутри блокирующей выборки осталось бы снимком её
     * собственного момента, и доказательство свежей проблемы было бы удалено.
     */
    public function testIssueCreatedAfterSelectionStillHoldsTheRecord(): void
    {
        $target = $this->seedRaw('page-1', new \DateTimeImmutable('-400 days'));

        // Вторая запись с открытой проблемой даёт предупреждение — точку между
        // выборкой кандидатов и их удалением.
        $held = $this->seedRaw('page-2', new \DateTimeImmutable('-400 days'));
        $this->em->persist(new NormalizationIssue(
            $this->companyId,
            $held['id'],
            null,
            NormalizationIssueKind::MAPPER_FAILURE,
            [],
        ));
        $this->em->flush();

        $action = new PruneRawRecordsAction(
            $this->rawRecords,
            $this->storage,
            $this->em,
            self::getContainer()->get(ClockInterface::class),
            new class($this->connection, $this->companyId, $target['id']) extends AbstractLogger {
                public function __construct(
                    private readonly Connection $connection,
                    private readonly string $companyId,
                    private readonly string $rawRecordId,
                ) {
                }

                /**
                 * @param mixed[] $context
                 */
                public function log($level, string|\Stringable $message, array $context = []): void
                {
                    if (LogLevel::WARNING !== $level) {
                        return;
                    }

                    $this->connection->executeStatement(
                        'INSERT INTO ingest_normalization_issues (id, company_id, raw_record_id, kind, details, created_at)
                         VALUES (gen_random_uuid(), :company, :raw, :kind, :details, now())',
                        [
                            'company' => $this->companyId,
                            'raw' => $this->rawRecordId,
                            'kind' => 'mapper_failure',
                            'details' => '{}',
                        ],
                    );
                }
            },
        );

        $result = $action(new PruneRawRecordsCommand(olderThanDays: 365, limit: 100, execute: true));

        self::assertSame(0, $result->prunedPayloads, 'Свежая проблема удерживает своё доказательство.');
        self::assertNotNull($this->rawRecords->findByIdAndCompany($target['id'], $this->companyId));
        self::assertTrue($this->storage->exists($target['path']));
    }

    /**
     * Регрессия: кандидаты выбирались один раз, а удалялись позже и без
     * перепроверки. За это время дедуп мог обновить отметку «видели», а
     * нормализация — завести проблему; удаление после этого необратимо теряло
     * свежее сырьё или единственное доказательство.
     *
     * Конкурент вносится ПОСЛЕ выборки: подставленный логгер срабатывает на
     * предупреждении об удержанных записях, то есть ровно между выборкой
     * кандидатов и их удалением.
     */
    public function testCandidateThatStoppedBeingPrunableIsNotDeleted(): void
    {
        $target = $this->seedRaw('page-1', new \DateTimeImmutable('-400 days'));

        // Вторая запись с открытой проблемой: она удерживается, и именно её
        // предупреждение даёт нам точку между выборкой и удалением.
        $held = $this->seedRaw('page-2', new \DateTimeImmutable('-400 days'));
        $this->em->persist(new NormalizationIssue(
            $this->companyId,
            $held['id'],
            null,
            NormalizationIssueKind::MAPPER_FAILURE,
            [],
        ));
        $this->em->flush();

        $action = new PruneRawRecordsAction(
            $this->rawRecords,
            $this->storage,
            $this->em,
            self::getContainer()->get(ClockInterface::class),
            new class($this->connection, $target['id']) extends AbstractLogger {
                public function __construct(
                    private readonly Connection $connection,
                    private readonly string $rawRecordId,
                ) {
                }

                /**
                 * @param mixed[] $context
                 */
                public function log($level, string|\Stringable $message, array $context = []): void
                {
                    if (LogLevel::WARNING !== $level) {
                        return;
                    }

                    // Дедуп подтвердил запись, пока прогон шёл к её удалению.
                    $this->connection->executeStatement(
                        'UPDATE ingest_raw_records SET last_seen_at = :seen WHERE id = :id',
                        ['seen' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.u'), 'id' => $this->rawRecordId],
                    );
                }
            },
        );

        $result = $action(new PruneRawRecordsCommand(olderThanDays: 365, limit: 100, execute: true));

        self::assertSame(1, $result->candidates, 'Кандидат был выбран…');
        self::assertSame(0, $result->prunedPayloads, '…но к моменту удаления перестал им быть.');
        self::assertNotNull($this->rawRecords->findByIdAndCompany($target['id'], $this->companyId));
        self::assertTrue($this->storage->exists($target['path']));
    }

    /**
     * Незавершённая очистка доводится следующим прогоном.
     *
     * Решение коммитится ДО обращения к хранилищу, поэтому падение между ними
     * оставляет запись помеченной, но с живым объектом. Кандидатов ищут среди
     * НЕпомеченных, значит без отдельной выборки такой объект остался бы в
     * хранилище навсегда.
     */
    public function testPayloadMarkedButNotDeletedIsFinishedByTheNextRun(): void
    {
        $record = $this->seedRaw('page-1', new \DateTimeImmutable('-400 days'));

        // Прогон, который принял решение, но до хранилища не дошёл.
        $this->connection->executeStatement(
            'UPDATE ingest_raw_records SET payload_pruned_at = now() WHERE id = :id',
            ['id' => $record['id']],
        );
        $this->em->clear();

        self::assertTrue($this->storage->exists($record['path']), 'Объект пока на месте.');

        $result = ($this->action)(new PruneRawRecordsCommand(olderThanDays: 365, limit: 100, execute: true));

        self::assertSame(0, $result->candidates, 'Помеченная запись кандидатом уже не считается…');
        self::assertGreaterThan(0, $result->bytesFreed, '…но её объект всё равно удаляется.');
        self::assertFalse($this->storage->exists($record['path']));
    }

    /**
     * Чтение очищенного сырья отвечает внятным отказом, а не сбоем хранилища.
     *
     * «Записи нет» и «запись есть, а нагрузки уже нет» — разные факты с разной
     * реакцией: первое означает неверный идентификатор, второе — что данные
     * вышли за окно хранения, и это ожидаемо.
     */
    public function testReadingAPrunedPayloadFailsExplicitly(): void
    {
        $record = $this->seedRaw('page-1', new \DateTimeImmutable('-400 days'));

        ($this->action)(new PruneRawRecordsCommand(olderThanDays: 365, limit: 100, execute: true));
        $this->em->clear();

        /** @var ReadRawRecordAction $read */
        $read = self::getContainer()->get(ReadRawRecordAction::class);

        $this->expectException(RawPayloadPrunedException::class);
        iterator_to_array($read($record['id'], $this->companyId), false);
    }

    /**
     * Та же выгрузка после очистки возвращает нагрузку и снимает отметку.
     *
     * Ради этого строка и остаётся. В прежней модели retention удалял её
     * целиком, и дедуп при часовом опросе обновлял `lastSeenAt` у уже
     * удалённой записи: свежая выгрузка терялась молча, потому что UPDATE
     * задевал ноль строк. Теперь запись на месте, объект восстанавливается, и
     * система лечит себя сама.
     */
    public function testSameBatchAfterPruningRestoresThePayload(): void
    {
        $record = $this->seedRaw('page-1', new \DateTimeImmutable('-400 days'));

        ($this->action)(new PruneRawRecordsCommand(olderThanDays: 365, limit: 100, execute: true));
        self::assertFalse($this->storage->exists($record['path']));

        // Тот же самый батч приезжает снова — дедуп находит запись по хешу.
        $again = $this->seedRaw('page-1', new \DateTimeImmutable('-400 days'));

        self::assertSame($record['id'], $again['id'], 'Дедуп обязан попасть в ту же запись.');
        self::assertTrue($this->storage->exists($record['path']), 'Нагрузка вернулась.');

        $this->em->clear();
        $restored = $this->rawRecords->findByIdAndCompany($record['id'], $this->companyId);
        self::assertNotNull($restored);
        self::assertNull($restored->getPayloadPrunedAt(), 'Отметка обязана сняться вместе с возвратом нагрузки.');
    }

    /**
     * @return array{id: string, path: string}
     */
    private function seedRaw(string $externalId, \DateTimeImmutable $lastSeenAt, ?\DateTimeImmutable $fetchedAt = null): array
    {
        /** @var RawStorageFacade $facade */
        $facade = self::getContainer()->get(RawStorageFacade::class);

        $records = $facade->store(new RawBatch(
            companyId: $this->companyId,
            connectionRef: self::CONNECTION_REF,
            shopRef: 'shop-main',
            source: IngestSource::OZON,
            resourceType: 'prune_fixture',
            externalId: $externalId,
            syncJobId: Uuid::uuid7()->toString(),
            fetchedAt: $fetchedAt ?? $lastSeenAt,
            rows: [['posting_number' => 'posting-1']],
        ));

        $record = $records[0];
        $record->markSeen($lastSeenAt);
        $this->em->flush();

        return ['id' => $record->getId(), 'path' => $record->getStoragePath()];
    }
}
