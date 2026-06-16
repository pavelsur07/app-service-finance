<?php

declare(strict_types=1);

namespace App\Ingestion\Application;

use App\Ingestion\DTO\RawBatch;
use App\Ingestion\Entity\IngestRawRecord;
use App\Ingestion\Exception\RawStorageException;
use App\Ingestion\Infrastructure\Storage\RawNdjsonCodec;
use App\Ingestion\Infrastructure\Storage\RawStoragePathBuilder;
use App\Ingestion\Repository\IngestRawRecordRepository;
use App\Shared\Service\Storage\ObjectStorageInterface;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

final readonly class StoreRawBatchAction
{
    public function __construct(
        private IngestRawRecordRepository $rawRecordRepository,
        private ObjectStorageInterface $objectStorage,
        private RawNdjsonCodec $ndjsonCodec,
        private RawStoragePathBuilder $pathBuilder,
        private EntityManagerInterface $entityManager,
        private ManagerRegistry $managerRegistry,
    ) {
    }

    /**
     * @return list<IngestRawRecord>
     */
    public function __invoke(RawBatch $batch): array
    {
        $ndjson = $this->ndjsonCodec->encodeRows($batch->rows);
        $hash = hash('sha256', $ndjson);

        $latestRecord = $this->rawRecordRepository->findLatestByCompanySourceExternalId(
            $batch->companyId,
            $batch->source,
            $batch->resourceType,
            $batch->externalId,
        );

        if (null !== $latestRecord && $latestRecord->getHash() === $hash) {
            $latestRecord->markSeen();
            $this->entityManager->flush();

            return [$latestRecord];
        }

        $existingRecord = $this->rawRecordRepository->findOneByCompanySourceExternalIdAndHash(
            $batch->companyId,
            $batch->source,
            $batch->resourceType,
            $batch->externalId,
            $hash,
        );

        if (null !== $existingRecord) {
            $existingRecord->markSeen();
            $this->entityManager->flush();

            return [$existingRecord];
        }

        $compressed = gzencode($ndjson, 6);
        if (false === $compressed) {
            throw new RawStorageException('Failed to gzip raw payload.');
        }

        $storagePath = $this->pathBuilder->build($batch, $hash);
        $storedObject = $this->objectStorage->write($storagePath, $compressed);

        $record = new IngestRawRecord(
            companyId: $batch->companyId,
            connectionRef: $batch->connectionRef,
            shopRef: $batch->shopRef,
            source: $batch->source,
            resourceType: $batch->resourceType,
            externalId: $batch->externalId,
            storagePath: $storedObject->path,
            hash: $hash,
            byteSize: $storedObject->byteSize,
            fetchedAt: $batch->fetchedAt,
            syncJobId: $batch->syncJobId,
        );

        $this->entityManager->persist($record);
        try {
            $this->entityManager->flush();
        } catch (UniqueConstraintViolationException $exception) {
            return [$this->recoverConcurrentDuplicate($batch, $hash, $exception)];
        }

        return [$record];
    }

    private function recoverConcurrentDuplicate(
        RawBatch $batch,
        string $hash,
        UniqueConstraintViolationException $exception,
    ): IngestRawRecord {
        $entityManager = $this->entityManager;
        $repository = $this->rawRecordRepository;

        if ($entityManager->isOpen()) {
            $entityManager->clear();
        } else {
            $resetManager = $this->managerRegistry->resetManager();
            if (!$resetManager instanceof EntityManagerInterface) {
                throw $exception;
            }

            $entityManager = $resetManager;
            $resetRepository = $entityManager->getRepository(IngestRawRecord::class);
            if (!$resetRepository instanceof IngestRawRecordRepository) {
                throw $exception;
            }

            $repository = $resetRepository;
        }

        $existingRecord = $repository->findOneByCompanySourceExternalIdAndHash(
            $batch->companyId,
            $batch->source,
            $batch->resourceType,
            $batch->externalId,
            $hash,
        );

        if (null === $existingRecord) {
            throw $exception;
        }

        $existingRecord->markSeen();
        $entityManager->flush();

        return $existingRecord;
    }
}
