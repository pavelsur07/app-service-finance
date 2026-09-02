<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Application;

use App\Ingestion\Application\Action\PruneRawRecordsAction;
use App\Ingestion\Application\Command\PruneRawRecordsCommand;
use App\Ingestion\DTO\RawBatch;
use App\Ingestion\Entity\NormalizationIssue;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Enum\NormalizationIssueKind;
use App\Ingestion\Facade\RawStorageFacade;
use App\Ingestion\Repository\IngestRawRecordRepository;
use App\Shared\Service\Storage\ObjectStorageInterface;
use App\Tests\Support\Kernel\IntegrationTestCase;
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
     * видит только объекты и оставило бы строку висячим указателем, на котором
     * ReadRawRecordAction падает.
     */
    public function testDeletesTheRecordAndItsObjectTogether(): void
    {
        $record = $this->seedRaw('page-1', new \DateTimeImmutable('-400 days'));
        $path = $record['path'];

        self::assertTrue($this->storage->exists($path));

        $result = ($this->action)(new PruneRawRecordsCommand(olderThanDays: 365, limit: 100, execute: true));

        self::assertSame(1, $result->candidates);
        self::assertSame(1, $result->deleted);
        self::assertGreaterThan(0, $result->bytesFreed);
        self::assertSame(0, $result->orphanedObjects);

        self::assertNull($this->rawRecords->findByIdAndCompany($record['id'], $this->companyId));
        self::assertFalse($this->storage->exists($path), 'Объект обязан уйти вместе со строкой.');
    }

    public function testDryRunTouchesNothing(): void
    {
        $record = $this->seedRaw('page-1', new \DateTimeImmutable('-400 days'));

        $result = ($this->action)(new PruneRawRecordsCommand(olderThanDays: 365, limit: 100, execute: false));

        self::assertSame(1, $result->candidates, 'Кандидат виден…');
        self::assertSame(0, $result->deleted, '…но не тронут.');

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
        $this->seedRaw('page-1', new \DateTimeImmutable('-2 days'));

        $result = ($this->action)(new PruneRawRecordsCommand(olderThanDays: 365, limit: 100, execute: true));

        self::assertSame(0, $result->candidates);
        self::assertSame(0, $result->deleted);
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

        self::assertSame(1, $released->deleted);
        self::assertSame(0, $released->heldByIssues);
        self::assertFalse($this->storage->exists($record['path']));
    }

    /**
     * Упавшее удаление объекта не оставляет висячего указателя и не молчит:
     * запись уходит, объект остаётся сиротой, счётчик это показывает, а путь
     * уходит в лог.
     *
     * Тест проверяет именно ЭТО, а не порядок операций: при сбое удаления
     * объекта оба порядка дают одинаковый результат. Сам порядок (строка, потом
     * объект) выбран разбором отказов, а не доказан тестом — различает их
     * только падение процесса МЕЖДУ двумя шагами, которое в наборе не
     * воспроизвести. Зафиксировано как ограничение проверки.
     */
    public function testFailedObjectDeletionIsReportedAndLeavesNoRecordBehind(): void
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
            self::getContainer()->get(\Psr\Clock\ClockInterface::class),
            new \Psr\Log\NullLogger(),
        );

        $result = $action(new PruneRawRecordsCommand(olderThanDays: 365, limit: 100, execute: true));

        self::assertSame(1, $result->deleted);
        self::assertSame(1, $result->orphanedObjects);

        $this->em->clear();
        self::assertNull(
            $this->rawRecords->findByIdAndCompany($record['id'], $this->companyId),
            'Указатель не должен пережить объект — иначе чтение сырья падает.',
        );
    }

    /**
     * @return array{id: string, path: string}
     */
    private function seedRaw(string $externalId, \DateTimeImmutable $lastSeenAt): array
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
            fetchedAt: $lastSeenAt,
            rows: [['posting_number' => 'posting-1']],
        ));

        $record = $records[0];
        $record->markSeen($lastSeenAt);
        $this->em->flush();

        return ['id' => $record->getId(), 'path' => $record->getStoragePath()];
    }
}
