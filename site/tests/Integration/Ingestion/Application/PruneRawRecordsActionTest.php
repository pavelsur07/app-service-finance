<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Application;

use App\Ingestion\Application\Action\PruneRawRecordsAction;
use App\Ingestion\Application\Action\RecordNormalizationIssueAction;
use App\Ingestion\Application\Command\PruneRawRecordsCommand;
use App\Ingestion\Application\Command\RecordNormalizationIssueCommand;
use App\Ingestion\Application\ReadRawRecordAction;
use App\Ingestion\DTO\RawBatch;
use App\Ingestion\Entity\NormalizationIssue;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Enum\NormalizationIssueKind;
use App\Ingestion\Exception\RawPayloadPrunedException;
use App\Ingestion\Facade\RawStorageFacade;
use App\Ingestion\Repository\IngestRawRecordRepository;
use App\Shared\Service\Storage\ObjectStorageInterface;
use App\Shared\Service\Storage\StoredObject;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
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

                public function write(string $path, string $contents): StoredObject
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
        // Уровень WARNING, а не ERROR: состояние повторяемо и лечится
        // следующим прогоном. Будить человека на самолечащемся сбое — ровно
        // тот ложный алерт, который обесценивает канал; видимость даёт
        // ненулевой код возврата команды.
        $warnings = array_values(array_filter(
            $logger->records,
            static fn (array $entry): bool => LogLevel::WARNING === $entry['level']
                && isset($entry['context']['storagePath']),
        ));

        self::assertSame([], array_filter(
            $logger->records,
            static fn (array $entry): bool => LogLevel::ERROR === $entry['level'],
        ));

        self::assertCount(1, $warnings);
        self::assertSame($record['path'], $warnings[0]['context']['storagePath']);
        self::assertSame($record['id'], $warnings[0]['context']['rawRecordId']);
        self::assertSame(\RuntimeException::class, $warnings[0]['context']['exceptionClass']);
        self::assertArrayNotHasKey('exceptionMessage', $warnings[0]['context'], 'Сообщения хранилища несут URL с учётными данными.');

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
     * Удаление объекта идемпотентно: если его уже нет, запись всё равно
     * доводится до конца.
     *
     * Такое состояние оставляет падение между успешным `delete()` и коммитом.
     * Если бы отсутствие объекта считалось ошибкой, запись навсегда осталась
     * бы недоделанной, и каждый прогон завершался бы сбоем — «хорошее»
     * состояние было бы недостижимо.
     */
    public function testDeletingAnAlreadyMissingObjectFinishesTheRecord(): void
    {
        $record = $this->seedRaw('page-1', new \DateTimeImmutable('-400 days'));

        $this->connection->executeStatement(
            'UPDATE ingest_raw_records SET payload_pruned_at = now() WHERE id = :id',
            ['id' => $record['id']],
        );
        $this->storage->delete($record['path']);
        $this->em->clear();

        $result = ($this->action)(new PruneRawRecordsCommand(olderThanDays: 365, limit: 100, execute: true));

        self::assertSame(0, $result->orphanedObjects, 'Отсутствие объекта — не сбой.');

        $this->em->clear();
        $finished = $this->rawRecords->findByIdAndCompany($record['id'], $this->companyId);
        self::assertNotNull($finished);
        self::assertNotNull($finished->getPayloadDeletedAt(), 'Запись обязана перестать числиться недоделанной.');
    }

    /**
     * Проблема, заведённая МЕЖДУ фазами, отменяет решение об очистке.
     *
     * Решение принимается в одной транзакции, а объект удаляется в другой;
     * между ними проходит время, и проблема, появившаяся в этом промежутке,
     * осталась бы без своей нагрузки.
     */
    public function testIssueRaisedBetweenPhasesCancelsThePruneDecision(): void
    {
        $record = $this->seedRaw('page-1', new \DateTimeImmutable('-400 days'));

        // Решение уже принято прежним прогоном.
        $this->connection->executeStatement(
            'UPDATE ingest_raw_records SET payload_pruned_at = now() WHERE id = :id',
            ['id' => $record['id']],
        );

        $this->em->persist(new NormalizationIssue(
            $this->companyId,
            $record['id'],
            null,
            NormalizationIssueKind::MAPPER_FAILURE,
            [],
        ));
        $this->em->flush();
        $this->em->clear();

        $result = ($this->action)(new PruneRawRecordsCommand(olderThanDays: 365, limit: 100, execute: true));

        self::assertSame(0, $result->bytesFreed);
        self::assertTrue($this->storage->exists($record['path']), 'Доказательство обязано уцелеть.');

        $this->em->clear();
        $cancelled = $this->rawRecords->findByIdAndCompany($record['id'], $this->companyId);
        self::assertNotNull($cancelled);
        self::assertNull($cancelled->getPayloadPrunedAt(), 'Решение отменяется целиком, а не откладывается.');
    }

    /**
     * Решение отменяется только при ЖИВОМ объекте.
     *
     * Состояние «решение принято, объект уже удалён, коммит не прошёл»
     * достижимо. Слепая отмена вернула бы запись к виду «нагрузка есть» при
     * отсутствующем объекте: чтение падало бы ошибкой хранилища, а проблема
     * всё равно осталась бы без доказательства — только теперь молча.
     */
    public function testCancellationDoesNotResurrectAPayloadThatIsAlreadyGone(): void
    {
        $record = $this->seedRaw('page-1', new \DateTimeImmutable('-400 days'));

        // Прежний прогон удалил объект, но отметку удаления не закоммитил.
        $this->connection->executeStatement(
            'UPDATE ingest_raw_records SET payload_pruned_at = now() WHERE id = :id',
            ['id' => $record['id']],
        );
        $this->storage->delete($record['path']);

        // И только теперь появилась проблема, которой нужна эта нагрузка.
        $this->em->persist(new NormalizationIssue(
            $this->companyId,
            $record['id'],
            null,
            NormalizationIssueKind::MAPPER_FAILURE,
            [],
        ));
        $this->em->flush();
        $this->em->clear();

        ($this->action)(new PruneRawRecordsCommand(olderThanDays: 365, limit: 100, execute: true));

        $this->em->clear();
        $closed = $this->rawRecords->findByIdAndCompany($record['id'], $this->companyId);
        self::assertNotNull($closed);
        self::assertNotNull($closed->getPayloadPrunedAt(), 'Запись не должна утверждать, что нагрузка вернулась.');
        self::assertNotNull($closed->getPayloadDeletedAt(), 'Состояние закрывается честно, а не остаётся недоделанным.');
    }

    /**
     * Неустранимый объект не занимает очередь навсегда.
     *
     * Очередь незавершённых сортируется по времени ПОПЫТКИ: без этой отметки
     * неудачная попытка ключ сортировки не меняет, и запись, которую нельзя
     * удалить, вечно занимала бы начало.
     */
    public function testFailedDeletionYieldsItsPlaceInTheQueue(): void
    {
        $stubborn = $this->seedRaw('page-1', new \DateTimeImmutable('-402 days'));
        // Вторая запись СВЕЖЕЕ, поэтому в очереди она вторая: без отметки
        // попытки первая осталась бы впереди навсегда и до этой очередь не
        // дошла бы никогда.
        $next = $this->seedRaw('page-2', new \DateTimeImmutable('-401 days'));

        $this->connection->executeStatement(
            'UPDATE ingest_raw_records SET payload_pruned_at = now() WHERE id IN (:ids)',
            ['ids' => [$stubborn['id'], $next['id']]],
            ['ids' => Connection::PARAM_STR_ARRAY],
        );
        $this->em->clear();

        $action = new PruneRawRecordsAction(
            $this->rawRecords,
            new class($this->storage, $stubborn['path']) implements ObjectStorageInterface {
                public function __construct(
                    private readonly ObjectStorageInterface $inner,
                    private readonly string $undeletable,
                ) {
                }

                public function write(string $path, string $contents): StoredObject
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
                    if ($path === $this->undeletable) {
                        throw new \RuntimeException('storage is unavailable');
                    }

                    $this->inner->delete($path);
                }
            },
            $this->em,
            self::getContainer()->get(ClockInterface::class),
            new NullLogger(),
        );

        // Лимит РОВНО в одну запись: с большим лимитом обе записи попали бы
        // в один прогон, и голодание было бы нечем показать.
        $result = $action(new PruneRawRecordsCommand(olderThanDays: 365, limit: 1, execute: true));
        self::assertSame(1, $result->orphanedObjects);

        $this->em->clear();
        $attempted = $this->rawRecords->findByIdAndCompany($stubborn['id'], $this->companyId);
        self::assertNotNull($attempted);
        self::assertNotNull(
            $attempted->getPayloadDeletionAttemptedAt(),
            'Без отметки попытки запись вечно занимала бы начало очереди.',
        );

        // ВТОРОЙ прогон, тем же лимитом. Именно он и есть утверждение: место в
        // очереди достаётся следующей записи, а не снова неустранимой.
        $second = $action(new PruneRawRecordsCommand(olderThanDays: 365, limit: 1, execute: true));

        self::assertSame(0, $second->orphanedObjects, 'Неустранимая запись обязана уступить место.');
        self::assertSame(1, $second->pendingRetries);

        $this->em->clear();
        $served = $this->rawRecords->findByIdAndCompany($next['id'], $this->companyId);
        self::assertNotNull($served);
        self::assertNotNull($served->getPayloadDeletedAt(), 'Вторая запись обязана быть доведена до конца.');
        self::assertFalse($this->storage->exists($next['path']));
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
     * Проблема на чужое сырьё не заводится вовсе.
     *
     * Удержание ищется по паре `(компания, сырьё)` — ровно по ключу той
     * блокировки, которой протокол сериализует retention и разбор. Проблема с
     * чужим `companyId` этой блокировки не берёт: она осталась бы вне
     * протокола и при этом удерживала бы нагрузку СОСЕДНЕГО арендатора,
     * которому о ней ничего не известно.
     */
    public function testIssueForAForeignRawRecordIsRejectedAndHoldsNothing(): void
    {
        $record = $this->seedRaw('page-1', new \DateTimeImmutable('-400 days'));

        $issues = self::getContainer()->get(RecordNormalizationIssueAction::class);

        $issues(new RecordNormalizationIssueCommand(
            companyId: Uuid::uuid7()->toString(),
            rawRecordId: $record['id'],
            operationGroupId: null,
            kind: NormalizationIssueKind::MAPPER_FAILURE,
            details: [],
        ));

        self::assertSame(
            0,
            (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM ingest_normalization_issues WHERE raw_record_id = :id',
                ['id' => $record['id']],
            ),
            'Проблема без своей строки сырья не имеет права появиться.',
        );

        $result = ($this->action)(new PruneRawRecordsCommand(olderThanDays: 365, limit: 100, execute: true));

        self::assertSame(0, $result->heldByIssues, 'Чужая проблема не удерживает нагрузку.');
        self::assertSame(1, $result->prunedPayloads);
        self::assertFalse($this->storage->exists($record['path']));
    }

    /**
     * Пачка проблем не блокирует чужие строки и не растёт по числу запросов.
     *
     * Два утверждения одного решения. Чужая строка не должна блокироваться
     * даже на мгновение — это межтенантный побочный эффект, задерживающий
     * чужой ingestion. А владение при этом проверяется отдельным запросом
     * именно потому, что блокировать нужно в одном глобальном порядке: фильтр
     * по компании внутри блокирующего запроса этот порядок разрушил бы.
     */
    public function testBatchOfIssuesNeitherTouchesForeignRowsNorScalesQueries(): void
    {
        $mine = [
            $this->seedRaw('page-1', new \DateTimeImmutable('-400 days')),
            $this->seedRaw('page-2', new \DateTimeImmutable('-401 days')),
            $this->seedRaw('page-3', new \DateTimeImmutable('-402 days')),
        ];

        $foreignCompanyId = Uuid::uuid7()->toString();
        $foreignRawRecordId = Uuid::uuid7()->toString();
        $this->connection->executeStatement(
            "INSERT INTO ingest_raw_records
                 (id, company_id, connection_ref, shop_ref, source, resource_type, external_id,
                  storage_path, hash, byte_size, fetched_at, last_seen_at, sync_job_id,
                  normalization_status, created_at, updated_at)
             VALUES
                 (:id, :company, 'conn-1', 'shop-main', 'ozon', 'prune_fixture', 'foreign',
                  'company/ozon/shop/foreign.ndjson.gz', 'foreign-hash', 128,
                  now(), now(), :job, 'done', now(), now())",
            [
                'id' => $foreignRawRecordId,
                'company' => $foreignCompanyId,
                'job' => Uuid::uuid7()->toString(),
            ],
        );

        $issues = self::getContainer()->get(RecordNormalizationIssueAction::class);

        $commands = [];
        foreach ($mine as $record) {
            $commands[] = new RecordNormalizationIssueCommand(
                companyId: $this->companyId,
                rawRecordId: $record['id'],
                operationGroupId: null,
                kind: NormalizationIssueKind::MAPPER_FAILURE,
                details: [],
            );
        }

        // Чужая строка под НАШЕЙ компанией: ни проблемы, ни блокировки.
        $commands[] = new RecordNormalizationIssueCommand(
            companyId: $this->companyId,
            rawRecordId: $foreignRawRecordId,
            operationGroupId: null,
            kind: NormalizationIssueKind::MAPPER_FAILURE,
            details: [],
        );

        $issues->recordMany($commands);

        self::assertSame(
            3,
            (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM ingest_normalization_issues WHERE company_id = :c',
                ['c' => $this->companyId],
            ),
            'Проблемы своих записей обязаны появиться…',
        );

        self::assertSame(
            0,
            (int) $this->connection->fetchOne(
                'SELECT COUNT(*) FROM ingest_normalization_issues WHERE raw_record_id = :id',
                ['id' => $foreignRawRecordId],
            ),
            '…а чужой — нет.',
        );

        // И до блокировки дело не доходит: отбор владения — отдельный шаг,
        // и чужой идентификатор из него не выходит. Проверяется напрямую,
        // потому что внутри одной транзакции теста «заблокирована ли строка»
        // спросить нечем: своя же транзакция и держала бы этот замок.
        self::assertSame(
            [],
            $this->rawRecords->filterOwned([$this->companyId => [$foreignRawRecordId]]),
            'Чужая строка не должна попадать в блокирующий запрос вовсе.',
        );
    }

    /**
     * Прогноз обязан описывать ТОТ ЖЕ прогон, который потом выполнится.
     *
     * Незавершённое прошлых прогонов обслуживается первым и из общего лимита.
     * Пока dry-run считал только новых кандидатов, он врал дважды: молчал о
     * работе, которую execute сделает раньше всего, и обещал удалить
     * кандидата, до которого бюджет уже не дойдёт. Для необратимой операции
     * это худший сорт неточности — решение принимают именно по этим числам.
     */
    public function testDryRunPlansTheSameBoundedWorkAsExecute(): void
    {
        $pending = $this->seedRaw('page-1', new \DateTimeImmutable('-400 days'));
        $this->seedRaw('page-2', new \DateTimeImmutable('-401 days'));

        // Решение по первой записи принято прежним прогоном: объект на месте.
        $this->connection->executeStatement(
            'UPDATE ingest_raw_records SET payload_pruned_at = now() WHERE id = :id',
            ['id' => $pending['id']],
        );
        $this->em->clear();

        $plan = ($this->action)(new PruneRawRecordsCommand(olderThanDays: 365, limit: 1, execute: false));

        self::assertSame(1, $plan->pendingRetries, 'Незавершённое обязано быть видно.');
        self::assertGreaterThan(0, $plan->pendingBytes);
        self::assertSame(
            0,
            $plan->candidates,
            'Бюджет уже израсходован на backlog: обещать новых кандидатов нельзя.',
        );

        $done = ($this->action)(new PruneRawRecordsCommand(olderThanDays: 365, limit: 1, execute: true));

        self::assertSame($plan->pendingRetries, $done->pendingRetries);
        self::assertSame($plan->candidates, $done->candidates);
        self::assertSame(0, $done->prunedPayloads, 'Новых решений в этом прогоне быть не могло.');
        self::assertFalse($this->storage->exists($pending['path']), 'Backlog обязан быть доведён до конца.');
    }

    /**
     * Хранилище может не ответить и на вопрос «жив ли объект».
     *
     * Выпустить это исключение наружу значило бы откатить весь чанк вместе с
     * уже засчитанными попытками: запись сохранила бы старейший приоритет и
     * снова заняла бы начало очереди — то самое голодание, ради которого
     * отметка попытки и заведена. Соседняя запись при этом теряла бы свой
     * прогон ни за что.
     */
    public function testStorageFailureWhileCheckingAHeldPayloadDoesNotRollBackTheChunk(): void
    {
        $held = $this->seedRaw('page-1', new \DateTimeImmutable('-400 days'));
        $neighbour = $this->seedRaw('page-2', new \DateTimeImmutable('-401 days'));

        $this->connection->executeStatement(
            'UPDATE ingest_raw_records SET payload_pruned_at = now() WHERE id IN (:ids)',
            ['ids' => [$held['id'], $neighbour['id']]],
            ['ids' => Connection::PARAM_STR_ARRAY],
        );

        // Проблема появилась ПОСЛЕ решения: именно она заставляет спросить
        // хранилище, жив ли ещё объект.
        $this->em->persist(new NormalizationIssue(
            companyId: $this->companyId,
            rawRecordId: $held['id'],
            operationGroupId: null,
            kind: NormalizationIssueKind::MAPPER_FAILURE,
            details: [],
        ));
        $this->em->flush();
        $this->em->clear();

        $action = new PruneRawRecordsAction(
            $this->rawRecords,
            new class($this->storage, $held['path']) implements ObjectStorageInterface {
                public function __construct(
                    private readonly ObjectStorageInterface $inner,
                    private readonly string $unanswerable,
                ) {
                }

                public function write(string $path, string $contents): StoredObject
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
                    if ($path === $this->unanswerable) {
                        throw new \RuntimeException('storage is unavailable');
                    }

                    return $this->inner->exists($path);
                }

                public function delete(string $path): void
                {
                    $this->inner->delete($path);
                }
            },
            $this->em,
            self::getContainer()->get(ClockInterface::class),
            new NullLogger(),
        );

        $result = $action(new PruneRawRecordsCommand(olderThanDays: 365, limit: 100, execute: true));

        self::assertSame(1, $result->orphanedObjects, 'Неотвеченный вопрос обязан давать ненулевой код возврата.');

        $this->em->clear();

        $undecided = $this->rawRecords->findByIdAndCompany($held['id'], $this->companyId);
        self::assertNotNull($undecided);
        self::assertNotNull($undecided->getPayloadPrunedAt(), 'Решение остаётся в силе.');
        self::assertNull($undecided->getPayloadDeletedAt(), 'Ничего не удалено — отвечать было некому.');
        self::assertNotNull(
            $undecided->getPayloadDeletionAttemptedAt(),
            'Попытка обязана быть засчитана, иначе запись вечно первая в очереди.',
        );

        self::assertFalse(
            $this->storage->exists($neighbour['path']),
            'Соседняя запись обязана быть обработана: сбой одной не откатывает чанк.',
        );
    }

    /**
     * Дефект программы не притворяется сбоем хранилища.
     *
     * `TypeError`, `AssertionError` и прочие `\Error` следующим прогоном не
     * лечатся. Поймать их наравне с недоступностью хранилища значило бы
     * записать `warning`, оставить запись в вечном pending и спрятать поломку
     * за счётчиком, который никогда не сойдётся.
     */
    public function testProgrammingErrorInStorageIsNotSwallowedAsARetry(): void
    {
        $record = $this->seedRaw('page-1', new \DateTimeImmutable('-400 days'));

        $action = new PruneRawRecordsAction(
            $this->rawRecords,
            new class($this->storage) implements ObjectStorageInterface {
                public function __construct(private readonly ObjectStorageInterface $inner)
                {
                }

                public function write(string $path, string $contents): StoredObject
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
                    throw new \TypeError('storage adapter is broken');
                }
            },
            $this->em,
            self::getContainer()->get(ClockInterface::class),
            new NullLogger(),
        );

        $this->expectException(\TypeError::class);

        try {
            $action(new PruneRawRecordsCommand(olderThanDays: 365, limit: 100, execute: true));
        } finally {
            // Объект обязан уцелеть: до удаления дело не дошло.
            self::assertTrue($this->storage->exists($record['path']));
        }
    }

    /**
     * Удержание среди УЖЕ ПОМЕЧЕННОГО backlog видно в прогнозе.
     *
     * Запрос удержаний смотрит только на непомеченные записи, поэтому backlog
     * выпадал из него целиком: dry-run обещал освободить место, которое
     * execute не освободит — решение по этим записям будет отменено.
     */
    public function testHeldBacklogIsVisibleInTheForecast(): void
    {
        $held = $this->seedRaw('page-1', new \DateTimeImmutable('-400 days'));

        $this->connection->executeStatement(
            'UPDATE ingest_raw_records SET payload_pruned_at = now() WHERE id = :id',
            ['id' => $held['id']],
        );

        $this->em->persist(new NormalizationIssue(
            companyId: $this->companyId,
            rawRecordId: $held['id'],
            operationGroupId: null,
            kind: NormalizationIssueKind::MAPPER_FAILURE,
            details: [],
        ));
        $this->em->flush();
        $this->em->clear();

        $plan = ($this->action)(new PruneRawRecordsCommand(olderThanDays: 365, limit: 100, execute: false));

        self::assertSame(1, $plan->pendingRetries, 'Запись в backlog есть…');
        self::assertSame(1, $plan->heldByIssues, '…и она удержана — прогноз обязан это сказать.');
        self::assertSame(0, $plan->pendingBytes, 'Обещать освобождение её объёма нельзя: он не освободится.');

        $done = ($this->action)(new PruneRawRecordsCommand(olderThanDays: 365, limit: 100, execute: true));

        self::assertSame($plan->heldByIssues, $done->heldByIssues, 'Одна и та же запись не считается дважды.');
        self::assertSame(0, $done->heldAfterPlanning, 'Удержание было известно плану, а не появилось позже.');
        self::assertTrue($this->storage->exists($held['path']), 'Доказательство обязано уцелеть.');
    }

    /**
     * Чтение опирается на СВЕЖУЮ отметку, а не на карту идентичности.
     *
     * Сущность могла быть загружена задолго до того, как retention закоммитил
     * своё решение. Поверив её полям, чтение полезло бы в хранилище — и либо
     * упало бы невнятной ошибкой, либо вернуло данные, которых по решению уже
     * быть не должно.
     */
    public function testReadingUsesFreshPruneMarksInsteadOfTheIdentityMap(): void
    {
        $record = $this->seedRaw('page-1', new \DateTimeImmutable('-400 days'));

        // Сущность попадает в карту идентичности ДО появления отметки.
        self::assertNotNull($this->rawRecords->findByIdAndCompany($record['id'], $this->companyId));

        $this->connection->executeStatement(
            'UPDATE ingest_raw_records SET payload_pruned_at = now() WHERE id = :id',
            ['id' => $record['id']],
        );

        // Объект НАМЕРЕННО оставлен на месте: иначе тест краснел бы от сбоя
        // хранилища, а не от прочитанной отметки.
        self::assertTrue($this->storage->exists($record['path']));

        /** @var ReadRawRecordAction $read */
        $read = self::getContainer()->get(ReadRawRecordAction::class);

        $this->expectException(RawPayloadPrunedException::class);
        iterator_to_array($read($record['id'], $this->companyId), false);
    }

    /**
     * Проблема на сырьё с УЖЕ УДАЛЁННОЙ нагрузкой — это `error`.
     *
     * Retention промолчал не по злому умыслу: проблемы в момент удаления ещё
     * не существовало, и посчитать её потерянной он не мог. Здесь последний и
     * единственный наблюдатель — значит здесь и кричать.
     */
    public function testIssueOnAnAlreadyDeletedPayloadIsReportedAsAnError(): void
    {
        $record = $this->seedRaw('page-1', new \DateTimeImmutable('-400 days'));

        $this->connection->executeStatement(
            'UPDATE ingest_raw_records SET payload_pruned_at = now(), payload_deleted_at = now() WHERE id = :id',
            ['id' => $record['id']],
        );

        $logger = new class extends AbstractLogger {
            /** @var list<array{level: mixed, message: string}> */
            public array $records = [];

            /**
             * @param mixed[] $context
             */
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string) $message];
            }
        };

        $action = new RecordNormalizationIssueAction($this->em, $this->rawRecords, $logger);

        $action(new RecordNormalizationIssueCommand(
            companyId: $this->companyId,
            rawRecordId: $record['id'],
            operationGroupId: null,
            kind: NormalizationIssueKind::MAPPER_FAILURE,
            details: [],
        ));

        $errors = array_values(array_filter(
            $logger->records,
            static fn (array $entry): bool => LogLevel::ERROR === $entry['level'],
        ));

        self::assertCount(1, $errors, 'Безвозвратная потеря доказательства обязана быть инцидентом, а не заметкой.');
        self::assertStringContainsString('already deleted', $errors[0]['message']);
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
