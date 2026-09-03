<?php

declare(strict_types=1);

namespace App\Ingestion\Application;

use App\Ingestion\DTO\RawBatch;
use App\Ingestion\Entity\IngestRawRecord;
use App\Ingestion\Exception\RawRecordNotFoundException;
use App\Ingestion\Exception\RawStorageException;
use App\Ingestion\Infrastructure\Storage\RawNdjsonCodec;
use App\Ingestion\Infrastructure\Storage\RawStoragePathBuilder;
use App\Ingestion\Repository\IngestRawRecordRepository;
use App\Shared\Service\Storage\ObjectStorageInterface;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;

final readonly class StoreRawBatchAction
{
    public function __construct(
        private IngestRawRecordRepository $rawRecordRepository,
        private ObjectStorageInterface $objectStorage,
        private RawNdjsonCodec $ndjsonCodec,
        private RawStoragePathBuilder $pathBuilder,
        private EntityManagerInterface $entityManager,
        private ManagerRegistry $managerRegistry,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Вернуть нагрузку, если её нет, и только ПОТОМ снять отметку.
     *
     * Порядок важен: отметка, снятая раньше записи объекта, означала бы
     * запись, утверждающую, что нагрузка на месте, когда её ещё нет.
     */
    private function restorePayload(IngestRawRecord $record, string $ndjson): void
    {
        if (null !== $record->getPayloadPrunedAt() || !$this->objectStorage->exists($record->getStoragePath())) {
            $compressed = gzencode($ndjson, 6);
            if (false === $compressed) {
                throw new RawStorageException('Failed to gzip raw payload.');
            }

            $this->objectStorage->write($record->getStoragePath(), $compressed);
        }

        $record->markPayloadRestored();
        $record->markSeen();
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
            return [$this->reuse($batch, $latestRecord, $ndjson)];
        }

        $existingRecord = $this->rawRecordRepository->findOneByCompanySourceExternalIdAndHash(
            $batch->companyId,
            $batch->source,
            $batch->resourceType,
            $batch->externalId,
            $hash,
        );

        if (null !== $existingRecord) {
            return [$this->reuse($batch, $existingRecord, $ndjson)];
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
            // Объект остаётся: конкурент создал строку на то же сырьё, и
            // восстановление ниже пользуется ЕГО путём. Наш собственный путь
            // при этом совпадает с чужим — он строится из хеша содержимого.
            return [$this->recoverConcurrentDuplicate($batch, $hash, $ndjson, $exception)];
        } catch (\Throwable $exception) {
            // Объект записан, строки нет и не будет.
            //
            // Компенсация живёт ЗДЕСЬ, а не у вызывающего: только это место
            // знает путь сразу после записи, до `flush()`. Вызывающий получает
            // путь из возвращённой записи, то есть уже после успешного
            // `flush()`, и о падении внутри него узнать неоткуда — сирота
            // оставалась бы навсегда, потому что retention ищет кандидатов
            // среди СТРОК.
            $this->discardOrphanedObject($storedObject->path);

            throw $exception;
        }

        return [$record];
    }

    /**
     * Убрать объект, чьей строки не появилось.
     *
     * Не удалось — не беда для данных, но место занято, и путь обязан уйти в
     * лог: найти такой объект по базе невозможно, строки-то нет.
     */
    private function discardOrphanedObject(string $storagePath): void
    {
        try {
            $this->objectStorage->delete($storagePath);
        } catch (\Exception $exception) {
            $this->logger->error('Raw object was left in storage without its record; it has to be removed by hand.', [
                'storagePath' => $storagePath,
                // Класс, а не сообщение: в тексте ошибок хранилища встречаются
                // URL с учётными данными.
                'exceptionClass' => $exception::class,
            ]);
        }
    }

    /**
     * Повторная встреча того же сырья: подтвердить, при необходимости вернуть
     * нагрузку и снять отметку retention.
     *
     * Всё это — ПОД БЛОКИРОВКОЙ строки, потому что retention правит ту же
     * строку и тот же объект. Без общей блокировки шаги переплетались:
     * восстановление снимало отметку, видя ещё существующий объект, а
     * retention следом удалял его — оставалась запись, которая утверждает,
     * что нагрузка на месте, и чтение падало ошибкой хранилища.
     *
     * Порядок внутри тоже важен: объект пишется ДО снятия отметки. Иначе
     * отметка снялась бы раньше, чем данные появились.
     */
    private function reuse(RawBatch $batch, IngestRawRecord $record, string $ndjson): IngestRawRecord
    {
        $reused = $record;

        $this->entityManager->wrapInTransaction(
            function () use ($batch, $record, $ndjson, &$reused): void {
                $locked = $this->rawRecordRepository->findOneForUpdate($batch->companyId, $record->getId());

                // Строки нет — продолжать НЕЛЬЗЯ.
                //
                // Прежний откат на прочитанную ранее сущность выглядел
                // безобидно, а был тем же классом тихой потери: объект писался
                // без блокировки, Doctrine выполняла UPDATE по отсутствующей
                // строке, задевая ноль строк, и вызывающий получал «сохранено»
                // при записи, которой нет. В хранилище оставалась сирота.
                if (null === $locked) {
                    throw new RawRecordNotFoundException(sprintf('Raw record %s disappeared before its payload could be restored.', $record->getId()));
                }

                $this->restorePayload($locked, $ndjson);

                $reused = $locked;
            },
        );

        return $reused;
    }

    private function recoverConcurrentDuplicate(
        RawBatch $batch,
        string $hash,
        string $ndjson,
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

        // ПОД БЛОКИРОВКОЙ, как и обычная повторная встреча.
        //
        // Прежде здесь стояло рассуждение «запись только что создана
        // конкурентом, значит свежая и кандидатом retention быть не может».
        // Кодом оно не обеспечено: ветка не смотрит на `lastSeenAt`, а
        // историческая выгрузка попадает под retention-предикат сразу. Без
        // блокировки шаги переплетались ровно так же, как в `reuse()`:
        // восстановление писало объект и снимало отметки в памяти, retention
        // следом удалял объект, а flush закреплял запись, утверждающую, что
        // нагрузка на месте.
        $recovered = $existingRecord;

        $entityManager->wrapInTransaction(function () use ($batch, $repository, $existingRecord, $ndjson, &$recovered): void {
            $locked = $repository->findOneForUpdate($batch->companyId, $existingRecord->getId());

            if (null === $locked) {
                throw new RawRecordNotFoundException(sprintf('Raw record %s disappeared before its payload could be restored.', $existingRecord->getId()));
            }

            $this->restorePayload($locked, $ndjson);

            $recovered = $locked;
        });

        return $recovered;
    }
}
