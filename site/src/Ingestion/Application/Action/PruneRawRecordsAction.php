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

        // Незавершённое СНАЧАЛА, и из общего бюджета прогона.
        //
        // Иначе очередь голодает: каждый прогон помечал бы до `limit` новых
        // записей, а до накопленного backlog очередь доходила бы всё позже.
        $budget = $command->limit;
        $pending = $this->rawRecordRepository->findPendingPayloadDeletion($budget);

        foreach (array_chunk(
            array_map(static fn (IngestRawRecord $record): string => $record->getId(), $pending),
            self::DELETION_CHUNK,
        ) as $chunk) {
            $result = $this->deleteChunk($result, $chunk);
        }

        $budget -= count($pending);
        if ($budget <= 0) {
            $this->logger->info('Raw payload prune finished.', $this->context($command, $notSeenSince, $result));

            return $result;
        }

        // ФАЗА 1: решение. Отметка коммитится ДО любого обращения к хранилищу.
        $decided = [];

        foreach (array_chunk(
            array_slice(
                array_map(static fn (IngestRawRecord $record): string => $record->getId(), $records),
                0,
                $budget,
            ),
            self::CHUNK,
        ) as $chunk) {
            [$marked, $lateHolds] = $this->markChunk($chunk, $notSeenSince);
            $decided = [...$decided, ...$marked];
            $result = $result->with(prunedPayloads: count($marked), heldByIssues: $lateHolds);
        }

        // ФАЗА 2: исполнение решений, принятых ТОЛЬКО ЧТО.
        foreach (array_chunk($decided, self::DELETION_CHUNK) as $chunk) {
            $result = $this->deleteChunk($result, $chunk);
        }

        $this->logger->info('Raw payload prune finished.', $this->context($command, $notSeenSince, $result));

        return $result;
    }

    /**
     * Фаза 1: пометить кандидатов чанком, одной транзакцией и без сети.
     *
     * @param list<string> $chunk
     *
     * @return array{0: list<string>, 1: int} помеченные идентификаторы и число поздно обнаруженных удержаний
     */
    private function markChunk(array $chunk, \DateTimeImmutable $notSeenSince): array
    {
        $marked = [];
        $lateHolds = 0;

        $this->entityManager->wrapInTransaction(function () use ($chunk, $notSeenSince, &$marked, &$lateHolds): void {
            $locked = $this->rawRecordRepository->findManyPrunableForUpdate($chunk, $notSeenSince);

            // Удержание перепроверяется УЖЕ ПОД блокировкой и отдельным
            // запросом: `FOR UPDATE` защищает строку сырья, но не отсутствие
            // строки в таблице проблем, и условие внутри блокирующей выборки
            // осталось бы снимком её собственного момента.
            $held = array_flip($this->rawRecordRepository->filterHeldByUnresolvedIssues(
                array_map(static fn (IngestRawRecord $record): string => $record->getId(), $locked),
            ));

            $lateHolds = count($held);
            $now = $this->applicationTime();

            foreach ($locked as $record) {
                if (isset($held[$record->getId()])) {
                    continue;
                }

                $record->markPayloadPruned($now);
                $marked[] = $record->getId();
            }
        });

        if ($lateHolds > 0) {
            $this->logger->warning('Raw payloads became evidence for issues after they were selected.', [
                'records' => $lateHolds,
            ]);
        }

        return [$marked, $lateHolds];
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
        $cancelled = 0;
        $evidenceLost = 0;

        $this->entityManager->wrapInTransaction(function () use ($chunk, &$freed, &$orphaned, &$cancelled, &$evidenceLost): void {
            $locked = $this->rawRecordRepository->findPendingPayloadDeletionForUpdate($chunk);
            if ([] === $locked) {
                return;
            }

            // Удержание перепроверяется и ЗДЕСЬ, а не только при решении.
            // Между фазами проходит время, и проблема, заведённая в этом
            // промежутке, осталась бы без своей нагрузки.
            $held = array_flip($this->rawRecordRepository->filterHeldByUnresolvedIssues(
                array_map(static fn (IngestRawRecord $record): string => $record->getId(), $locked),
            ));

            $now = $this->applicationTime();
            $pending = [];

            foreach ($locked as $record) {
                if (!isset($held[$record->getId()])) {
                    $pending[] = $record;

                    continue;
                }

                // Отменять решение можно ТОЛЬКО если нагрузка ещё на месте.
                //
                // Состояние «решение принято, объект уже удалён, коммит не
                // прошёл» достижимо, и слепая отмена вернула бы запись к виду
                // «нагрузка есть» при отсутствующем объекте: чтение падало бы
                // ошибкой хранилища, а проблема всё равно осталась бы без
                // доказательства — только теперь молча.
                if ($this->objectStorage->exists($record->getStoragePath())) {
                    $record->markPayloadRestored();
                    ++$cancelled;

                    continue;
                }

                $record->markPayloadDeleted($now);
                ++$evidenceLost;
            }

            if ([] === $pending) {
                return;
            }

            // Пути пишутся в лог ДО обращения к хранилищу: если процесс умрёт
            // посреди фазы, найти уже удалённые объекты можно будет только по
            // этой записи.
            $this->logger->info('Raw objects are about to be deleted.', [
                'storagePaths' => array_map(
                    static fn (IngestRawRecord $record): string => $record->getStoragePath(),
                    $pending,
                ),
            ]);

            foreach ($pending as $record) {
                // Попытка засчитывается независимо от исхода: очередь
                // незавершённых сортируется по ней, и без отметки неустранимый
                // объект вечно занимал бы её начало.
                $record->markPayloadDeletionAttempted($now);

                try {
                    // Наличие проверяется ДО удаления, чтобы не отчитаться об
                    // освобождённом месте дважды: повтор после сбоя коммита
                    // подтверждает отсутствие, а не освобождает что-то ещё.
                    $existed = $this->objectStorage->exists($record->getStoragePath());

                    $this->objectStorage->delete($record->getStoragePath());
                    $record->markPayloadDeleted($now);

                    if ($existed) {
                        $freed += $record->getByteSize();
                    }
                } catch (\Throwable $exception) {
                    // Отметка решения остаётся, отметка удаления — нет:
                    // следующий прогон найдёт эту запись и повторит попытку.
                    //
                    // Уровень WARNING, а не ERROR: состояние повторяемо и
                    // лечится следующим прогоном, а будить человека на
                    // самолечащемся сбое — ровно тот ложный алерт, который
                    // обесценивает канал. Видимость даёт ненулевой код
                    // возврата команды.
                    ++$orphaned;
                    $this->logger->warning('Raw object was not deleted; its payload stays marked as pruned and will be retried.', [
                        'rawRecordId' => $record->getId(),
                        'storagePath' => $record->getStoragePath(),
                        // Класс, а не сообщение: в тексте ошибок хранилища
                        // встречаются URL с учётными данными.
                        'exceptionClass' => $exception::class,
                    ]);
                }
            }
        });

        if ($cancelled > 0) {
            $this->logger->warning('Prune decision cancelled: an issue now needs this payload as evidence.', [
                'records' => $cancelled,
            ]);
        }

        if ($evidenceLost > 0) {
            // Не самолечится: нагрузки уже нет, и проблему придётся разбирать
            // без неё. Молчать об этом нельзя.
            $this->logger->error('Issue needs a payload that was already deleted; the record is closed as pruned.', [
                'records' => $evidenceLost,
            ]);
        }

        return $result->with(
            bytesFreed: $freed,
            heldByIssues: $cancelled + $evidenceLost,
            orphanedObjects: $orphaned,
        );
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
