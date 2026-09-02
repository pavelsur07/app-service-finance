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
 * Удаление ПОЛЕЗНОЙ НАГРУЗКИ сырья, вышедшего за окно хранения.
 *
 * Почему приложением, а не lifecycle-правилом провайдера. Правило хранилища
 * видит только объекты и снесёт их, оставив строки `ingest_raw_records`
 * нетронутыми: `ReadRawRecordAction` найдёт запись, пойдёт за объектом и
 * упадёт с невнятной ошибкой хранилища. Отметку о том, что нагрузка удалена
 * намеренно, может поставить только приложение.
 *
 * Почему строка ОСТАЁТСЯ. Удаление строки вместе с объектом порождало класс
 * гонок, каждая из которых закрывалась правкой очередной подсистемы: запись
 * исчезала между проверкой и созданием `NormalizationIssue`, а дедуп при
 * часовом опросе обновлял `lastSeenAt` у уже удалённой строки, и свежая
 * выгрузка терялась молча. Дорого стоит объект, а не сотня байт метаданных,
 * поэтому удаляется объект, а строка получает отметку и живёт дальше:
 * указатели разрешаются, дедупу есть что обновлять, а
 * `StoreRawBatchAction::repairMissingObject()` вернёт нагрузку, если та же
 * выгрузка приедет снова.
 *
 * Порядок — отметка, потом объект. Обратный оставил бы при падении запись,
 * которая утверждает, что нагрузка на месте, тогда как её уже нет. При
 * падении между шагами остаётся осиротевший объект: он занимает место, но
 * ничего не ломает, и его путь уходит в лог ещё до коммита.
 */
final readonly class PruneRawRecordsAction
{
    /**
     * Записей на транзакцию в ПЕРВОЙ фазе.
     *
     * Прогон дробится намеренно: одна транзакция на весь лимит держала бы
     * блокировки минутами и при падении откатывала бы всю проделанную работу.
     */
    private const CHUNK = 100;

    /**
     * Записей на транзакцию во ВТОРОЙ фазе.
     *
     * Меньше первой: внутри транзакции идут сетевые вызовы к хранилищу, и
     * держать блокировку сотнями удалений незачем.
     */
    private const DELETION_CHUNK = 25;

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
        $now = $this->applicationTime();
        $notSeenSince = $now->modify(sprintf('-%d days', $command->olderThanDays));

        $records = $this->rawRecordRepository->findPrunable($notSeenSince, $command->limit);
        $held = $this->rawRecordRepository->countHeldByUnresolvedIssues($notSeenSince);

        $result = new PruneRawRecordsResult(
            candidates: count($records),
            // Объём считается ВСЕГДА, в том числе на dry-run: ради этого числа
            // он и запускается перед Production Gate — решение принимается по
            // освобождаемому месту, а не по числу строк.
            candidateBytes: array_sum(array_map(
                static fn (IngestRawRecord $record): int => $record->getByteSize(),
                $records,
            )),
            heldByIssues: $held,
        );

        if ($held > 0) {
            // Не ошибка: у состояния есть операция, переводящая его в хорошее —
            // разобрать проблему. Но молчать нельзя, иначе объём хранилища
            // растёт по причине, которую никто не видит.
            $this->logger->warning('Raw payloads kept beyond retention as evidence for unresolved issues.', [
                'records' => $held,
                'notSeenSince' => $notSeenSince->format(\DATE_ATOM),
            ]);
        }

        if (!$command->execute) {
            $this->logger->info('Raw payload prune finished.', $this->context($command, $notSeenSince, $result));

            return $result;
        }

        // ФАЗА 1: решение. Отметка коммитится ДО любого обращения к хранилищу.
        foreach (array_chunk(
            array_map(static fn (IngestRawRecord $record): string => $record->getId(), $records),
            self::CHUNK,
        ) as $chunk) {
            $result = $result->with(prunedPayloads: $this->markChunk($chunk, $notSeenSince));
        }

        // ФАЗА 2: исполнение. Берутся ВСЕ записи с принятым решением, включая
        // оставшиеся от прежних прогонов: без этого объект, до которого прогон
        // не дошёл, остался бы в хранилище навсегда — кандидатов ищут среди
        // непомеченных, и такая строка туда уже не попадает.
        foreach (array_chunk(
            array_map(
                static fn (IngestRawRecord $record): string => $record->getId(),
                $this->rawRecordRepository->findPendingPayloadDeletion($command->limit),
            ),
            self::DELETION_CHUNK,
        ) as $chunk) {
            $result = $this->deleteChunk($result, $chunk);
        }

        $this->logger->info('Raw payload prune finished.', $this->context($command, $notSeenSince, $result));

        return $result;
    }

    /**
     * Фаза 1: пометить кандидатов чанком, одной транзакцией и без сети.
     *
     * @param list<string> $chunk
     */
    private function markChunk(array $chunk, \DateTimeImmutable $notSeenSince): int
    {
        $marked = 0;

        $this->entityManager->wrapInTransaction(function () use ($chunk, $notSeenSince, &$marked): void {
            $locked = $this->rawRecordRepository->findManyPrunableForUpdate($chunk, $notSeenSince);

            // Удержание перепроверяется УЖЕ ПОД блокировкой и отдельным
            // запросом: `FOR UPDATE` защищает строку сырья, но не отсутствие
            // строки в таблице проблем, и условие внутри блокирующей выборки
            // осталось бы снимком её собственного момента.
            $held = array_flip($this->rawRecordRepository->filterHeldByUnresolvedIssues(
                array_map(static fn (IngestRawRecord $record): string => $record->getId(), $locked),
            ));

            $now = $this->applicationTime();

            foreach ($locked as $record) {
                if (isset($held[$record->getId()])) {
                    continue;
                }

                $record->markPayloadPruned($now);
                ++$marked;
            }
        });

        return $marked;
    }

    /**
     * Фаза 2: удалить объекты записей, решение по которым уже закоммичено.
     *
     * Блокировка нужна и здесь: повторная выгрузка могла вернуть нагрузку и
     * снять отметки, пока прогон шёл к хранилищу. Чанк меньше, чем в первой
     * фазе, потому что внутри транзакции идут сетевые вызовы, и держать
     * блокировку сотнями удалений незачем.
     *
     * @param list<string> $chunk
     */
    private function deleteChunk(PruneRawRecordsResult $result, array $chunk): PruneRawRecordsResult
    {
        $freed = 0;
        $orphaned = 0;

        $this->entityManager->wrapInTransaction(function () use ($chunk, &$freed, &$orphaned): void {
            $locked = $this->rawRecordRepository->findPendingPayloadDeletionForUpdate($chunk);
            if ([] === $locked) {
                return;
            }

            // Пути пишутся в лог ДО обращения к хранилищу: если процесс умрёт
            // посреди фазы, найти уже удалённые объекты можно будет только по
            // этой записи.
            $this->logger->info('Raw objects are about to be deleted.', [
                'storagePaths' => array_map(
                    static fn (IngestRawRecord $record): string => $record->getStoragePath(),
                    $locked,
                ),
            ]);

            $now = $this->applicationTime();

            foreach ($locked as $record) {
                try {
                    $this->objectStorage->delete($record->getStoragePath());
                    $record->markPayloadDeleted($now);
                    $freed += $record->getByteSize();
                } catch (\Throwable $exception) {
                    // Отметка решения остаётся, отметка удаления — нет:
                    // следующий прогон найдёт эту запись и повторит попытку.
                    // Объект пока занимает место, и его размер в освобождённые
                    // не идёт.
                    ++$orphaned;
                    $this->logger->error('Raw object was not deleted; its payload stays marked as pruned.', [
                        'rawRecordId' => $record->getId(),
                        'storagePath' => $record->getStoragePath(),
                        // Класс, а не сообщение: в тексте ошибок хранилища
                        // встречаются URL с учётными данными.
                        'exceptionClass' => $exception::class,
                    ]);
                }
            }
        });

        return $result->with(bytesFreed: $freed, orphanedObjects: $orphaned);
    }

    private function applicationTime(): \DateTimeImmutable
    {
        return $this->clock->now()->setTimezone(new \DateTimeZone(date_default_timezone_get()));
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
            'prunedPayloads' => $result->prunedPayloads,
            'bytesFreed' => $result->bytesFreed,
            'heldByIssues' => $result->heldByIssues,
            'orphanedObjects' => $result->orphanedObjects,
        ];
    }
}
