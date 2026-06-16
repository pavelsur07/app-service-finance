<?php

declare(strict_types=1);

namespace App\Ingestion\Application;

use App\Ingestion\Exception\RawRecordNotFoundException;
use App\Ingestion\Infrastructure\Storage\RawNdjsonCodec;
use App\Ingestion\Repository\IngestRawRecordRepository;
use App\Shared\Service\Storage\ObjectStorageInterface;
use Webmozart\Assert\Assert;

final readonly class ReadRawRecordAction
{
    public function __construct(
        private IngestRawRecordRepository $rawRecordRepository,
        private ObjectStorageInterface $objectStorage,
        private RawNdjsonCodec $ndjsonCodec,
    ) {
    }

    /**
     * @return iterable<array<string, mixed>>
     */
    public function __invoke(string $rawRecordId, string $companyId): iterable
    {
        Assert::uuid($rawRecordId);
        Assert::uuid($companyId);

        $record = $this->rawRecordRepository->findOneByIdAndCompany($companyId, $rawRecordId);
        if (null === $record) {
            throw new RawRecordNotFoundException('Raw record not found for requested company.');
        }

        return $this->ndjsonCodec->decodeCompressedRows($this->objectStorage->read($record->getStoragePath()));
    }
}
