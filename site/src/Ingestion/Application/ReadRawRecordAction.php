<?php

declare(strict_types=1);

namespace App\Ingestion\Application;

use App\Ingestion\Exception\RawPayloadPrunedException;
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

        // Внятный отказ вместо сбоя хранилища. Запись пережила свою нагрузку
        // намеренно: указатели на неё остаются разрешимыми, и вызывающий
        // узнаёт, что данные вышли за окно хранения, а не что «что-то
        // сломалось».
        //
        // Отметка читается СВЕЖИМ скалярным запросом, а не с сущности: та
        // могла прийти из карты идентичности, будучи загруженной задолго до
        // того, как retention закоммитил своё решение.
        //
        // Гарантия ровно такая: решение, закоммиченное ДО этой проверки,
        // всегда даёт отказ. Чтение, успевшее забрать байты раньше, вернёт
        // настоящие данные — и это не ложь, объект в тот момент существовал.
        // Линеаризовать сильнее (держать `FOR SHARE` до конца чтения) значило
        // бы блокировать retention на всё время сетевого чтения объекта.
        $this->assertPayloadKept($record->getId(), $this->prunedAt($companyId, $rawRecordId));

        try {
            return $this->ndjsonCodec->decodeCompressedRows($this->objectStorage->read($record->getStoragePath()));
        } catch (\Throwable $exception) {
            // Retention мог сработать между проверкой и чтением. Отличить это
            // от настоящего сбоя хранилища можно только перечитав отметку:
            // появившаяся означает, что нагрузку удалили намеренно, и отдавать
            // инфраструктурную ошибку было бы враньём.
            $this->assertPayloadKept($record->getId(), $this->prunedAt($companyId, $rawRecordId));

            throw $exception;
        }
    }

    private function prunedAt(string $companyId, string $rawRecordId): ?\DateTimeImmutable
    {
        $marks = $this->rawRecordRepository->payloadMarks($companyId, $rawRecordId);

        return $marks['prunedAt'] ?? null;
    }

    private function assertPayloadKept(string $rawRecordId, ?\DateTimeImmutable $prunedAt): void
    {
        if (null === $prunedAt) {
            return;
        }

        throw new RawPayloadPrunedException(sprintf('Raw payload of %s was pruned by the retention policy on %s.', $rawRecordId, $prunedAt->format(\DATE_ATOM)));
    }
}
