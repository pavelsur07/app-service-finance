<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Action;

use App\Ingestion\Application\Command\PruneRawRecordsCommand;
use App\Ingestion\Application\DTO\PruneRawRecordsResult;
use App\Ingestion\Entity\IngestRawRecord;
use App\Ingestion\Repository\IngestRawRecordRepository;
use App\Shared\Service\Storage\ObjectStorageInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Удаление сырья, вышедшего за окно хранения.
 *
 * Почему приложением, а не lifecycle-правилом провайдера. Правило хранилища
 * видит только объекты и снесёт их, оставив строки `ingest_raw_records`
 * нетронутыми. Такая строка — висячий указатель: `ReadRawRecordAction` найдёт
 * запись, пойдёт за объектом и упадёт. Удалять нужно ПАРУ, и делать это может
 * только тот, кто знает про обе половины.
 *
 * Порядок удаления — строка, потом объект. Обратный порядок даёт ровно тот
 * висячий указатель, ради которого всё и затевалось: объекта уже нет, а
 * запись ещё есть. При падении между операциями остаётся осиротевший объект —
 * он занимает место, но ничего не ломает, и его путь уходит в лог.
 */
final readonly class PruneRawRecordsAction
{
    /**
     * Записей на одну транзакцию.
     *
     * Прогон дробится намеренно: одна транзакция на весь лимит держала бы
     * блокировки минутами и при падении откатывала бы всю проделанную работу.
     */
    private const CHUNK = 100;

    public function __construct(
        private IngestRawRecordRepository $rawRecordRepository,
        private ObjectStorageInterface $objectStorage,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(PruneRawRecordsCommand $command): PruneRawRecordsResult
    {
        // Зона приложения — то соглашение, в котором живёт схема: колонки
        // времени без зоны, и UTC-момент сравнивался бы со сдвигом.
        $now = $this->clock->now()->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        $notSeenSince = $now->modify(sprintf('-%d days', $command->olderThanDays));

        $records = $this->rawRecordRepository->findPrunable($notSeenSince, $command->limit);
        $held = $this->rawRecordRepository->countHeldByUnresolvedIssues($notSeenSince);

        $result = new PruneRawRecordsResult(
            candidates: count($records),
            heldByIssues: $held,
        );

        if ($held > 0) {
            // Не ошибка: у состояния есть операция, переводящая его в хорошее —
            // разобрать проблему. Но молчать нельзя, иначе объём хранилища
            // растёт по причине, которую никто не видит.
            $this->logger->warning('Raw records kept beyond retention as evidence for unresolved issues.', [
                'records' => $held,
                'notSeenSince' => $notSeenSince->format(\DATE_ATOM),
            ]);
        }

        if (!$command->execute || [] === $records) {
            $this->logger->info('Raw record prune finished.', $this->context($command, $notSeenSince, $result));

            return $result;
        }

        foreach (array_chunk($records, self::CHUNK) as $chunk) {
            $result = $this->pruneChunk($result, $chunk);
        }

        $this->logger->info('Raw record prune finished.', $this->context($command, $notSeenSince, $result));

        return $result;
    }

    /**
     * @param list<IngestRawRecord> $chunk
     */
    private function pruneChunk(PruneRawRecordsResult $result, array $chunk): PruneRawRecordsResult
    {
        // Пути и объёмы снимаются ДО удаления: после detach сущности читать
        // нечего, а именно путь нужен, чтобы убрать объект следом.
        $paths = [];
        $bytes = 0;

        foreach ($chunk as $record) {
            $paths[$record->getId()] = $record->getStoragePath();
            $bytes += $record->getByteSize();
        }

        $this->entityManager->wrapInTransaction(function () use ($chunk): void {
            foreach ($chunk as $record) {
                $this->rawRecordRepository->remove($record);
            }
        });

        $orphaned = 0;

        foreach ($paths as $rawRecordId => $path) {
            try {
                $this->objectStorage->delete($path);
            } catch (\Throwable $exception) {
                // Строка уже удалена, значит указатель не повис. Объект
                // остался и занимает место — путь уходит в лог, чтобы его
                // можно было убрать вручную.
                ++$orphaned;
                $this->logger->error('Raw object left behind after its record was pruned.', [
                    'rawRecordId' => $rawRecordId,
                    'storagePath' => $path,
                    // Класс, а не сообщение: в тексте ошибок хранилища
                    // встречаются URL с учётными данными.
                    'exceptionClass' => $exception::class,
                ]);
            }
        }

        return $result->with(
            deleted: count($chunk),
            bytesFreed: $bytes,
            orphanedObjects: $orphaned,
        );
    }

    /**
     * @return array<string, int|string>
     */
    private function context(
        PruneRawRecordsCommand $command,
        \DateTimeImmutable $notSeenSince,
        PruneRawRecordsResult $result,
    ): array {
        return [
            'mode' => $command->execute ? 'execute' : 'dry-run',
            'olderThanDays' => $command->olderThanDays,
            'notSeenSince' => $notSeenSince->format(\DATE_ATOM),
            'candidates' => $result->candidates,
            'deleted' => $result->deleted,
            'bytesFreed' => $result->bytesFreed,
            'heldByIssues' => $result->heldByIssues,
            'orphanedObjects' => $result->orphanedObjects,
        ];
    }
}
