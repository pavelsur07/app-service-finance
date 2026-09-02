<?php

declare(strict_types=1);

namespace App\Ingestion\Application;

use App\Ingestion\Entity\IngestRawRecord;
use App\Ingestion\Exception\RawPayloadPrunedException;
use App\Ingestion\Exception\RawRecordNotFoundException;
use App\Ingestion\Infrastructure\Storage\RawNdjsonCodec;
use App\Ingestion\Repository\IngestRawRecordRepository;
use App\Shared\Service\Storage\ObjectStorageInterface;
use Doctrine\ORM\EntityManagerInterface;
use Webmozart\Assert\Assert;

final readonly class ReadRawRecordAction
{
    public function __construct(
        private IngestRawRecordRepository $rawRecordRepository,
        private ObjectStorageInterface $objectStorage,
        private RawNdjsonCodec $ndjsonCodec,
        private EntityManagerInterface $entityManager,
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

        // Внятный отказ вместо сбоя хранилища. Запись пережила свою нагрузку
        // намеренно: указатели на неё остаются разрешимыми, и вызывающий
        // узнаёт, что данные вышли за окно хранения, а не что «что-то
        // сломалось».
        $this->assertPayloadKept($record);

        try {
            return $this->ndjsonCodec->decodeCompressedRows($this->objectStorage->read($record->getStoragePath()));
        } catch (\Throwable $exception) {
            // Retention мог сработать между проверкой и чтением. Отличить это
            // от настоящего сбоя хранилища можно только перечитав запись:
            // появившаяся отметка означает, что нагрузку удалили намеренно, и
            // отдавать инфраструктурную ошибку было бы враньём.
            $this->entityManager->refresh($record);
            $this->assertPayloadKept($record);

            throw $exception;
        }
    }

    private function assertPayloadKept(IngestRawRecord $record): void
    {
        $prunedAt = $record->getPayloadPrunedAt();
        if (null === $prunedAt) {
            return;
        }

        throw new RawPayloadPrunedException(sprintf('Raw payload was pruned by the retention policy on %s.', $prunedAt->format(\DATE_ATOM)));
    }
}
