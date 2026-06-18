<?php

declare(strict_types=1);

namespace App\Tests\Integration\Ingestion\Repository;

use App\Ingestion\Entity\IngestRawRecord;
use App\Ingestion\Enum\IngestSource;
use App\Ingestion\Repository\IngestRawRecordRepository;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class IngestRawRecordRepositoryTest extends IntegrationTestCase
{
    public function testFindStuckPendingReturnsOnlyPendingRecordsOlderThanThreshold(): void
    {
        $companyId = Uuid::uuid7()->toString();
        $oldPending = $this->newRawRecord($companyId, 'old-pending', '2026-06-18 09:00:00');
        $olderPending = $this->newRawRecord($companyId, 'older-pending', '2026-06-18 08:00:00');
        $freshPending = $this->newRawRecord($companyId, 'fresh-pending', '2026-06-18 10:30:00');
        $doneOld = $this->newRawRecord($companyId, 'done-old', '2026-06-18 07:00:00');
        $doneOld->markNormalizationDone();

        foreach ([$oldPending, $olderPending, $freshPending, $doneOld] as $record) {
            $this->em->persist($record);
        }
        $this->em->flush();
        $this->em->clear();

        /** @var IngestRawRecordRepository $repository */
        $repository = self::getContainer()->get(IngestRawRecordRepository::class);

        self::assertSame(
            [$olderPending->getId(), $oldPending->getId()],
            array_map(
                static fn (IngestRawRecord $record): string => $record->getId(),
                $repository->findStuckPending(new \DateTimeImmutable('2026-06-18 10:00:00'), 10),
            ),
        );
        self::assertSame(
            [$olderPending->getId()],
            array_map(
                static fn (IngestRawRecord $record): string => $record->getId(),
                $repository->findStuckPending(new \DateTimeImmutable('2026-06-18 10:00:00'), 1),
            ),
        );
    }

    private function newRawRecord(string $companyId, string $externalId, string $fetchedAt): IngestRawRecord
    {
        return new IngestRawRecord(
            companyId: $companyId,
            connectionRef: 'connection-1',
            shopRef: 'shop-1',
            source: IngestSource::OZON,
            resourceType: 'test-resource',
            externalId: $externalId,
            storagePath: 'ingestion/test/'.$externalId.'.json',
            hash: hash('sha256', $externalId),
            byteSize: 100,
            fetchedAt: new \DateTimeImmutable($fetchedAt),
            syncJobId: 'sync-job-1',
        );
    }
}
