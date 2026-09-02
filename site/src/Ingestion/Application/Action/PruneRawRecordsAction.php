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
            $this->logger->warning('Raw records kept beyond retention as evidence for unresolved issues.', [
                'records' => $held,
                'notSeenSince' => $notSeenSince->format(\DATE_ATOM),
            ]);
        }

        if (!$command->execute || [] === $records) {
            $this->logger->info('Raw record prune finished.', $this->context($command, $notSeenSince, $result));

            return $result;
        }

        $ids = array_map(static fn (IngestRawRecord $record): string => $record->getId(), $records);

        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $result = $this->pruneChunk($result, $chunk, $notSeenSince);
        }

        $this->logger->info('Raw record prune finished.', $this->context($command, $notSeenSince, $result));

        return $result;
    }

    /**
     * @param list<string> $chunk идентификаторы кандидатов
     */
    private function pruneChunk(PruneRawRecordsResult $result, array $chunk, \DateTimeImmutable $notSeenSince): PruneRawRecordsResult
    {
        /** @var array<string, array{path: string, bytes: int}> $marked */
        $marked = [];

        // Кандидаты перечитываются под блокировкой И перепроверяются условием.
        //
        // Между выборкой и этим моментом дедуп мог обновить `lastSeenAt`, а
        // нормализация — завести проблему на запись. Удалить её после этого
        // значило бы необратимо потерять свежее сырьё или единственное
        // доказательство, а восстановить его неоткуда.
        $now = $this->clock->now()->setTimezone(new \DateTimeZone(date_default_timezone_get()));

        $this->entityManager->wrapInTransaction(function () use ($chunk, $notSeenSince, $now, &$marked): void {
            $locked = $this->rawRecordRepository->findManyPrunableForUpdate($chunk, $notSeenSince);

            // Удержание перепроверяется УЖЕ ПОД блокировкой и отдельным
            // запросом: `FOR UPDATE` защищает строку сырья, но не отсутствие
            // строки в таблице проблем, и условие внутри блокирующей выборки
            // осталось бы снимком её собственного момента.
            $held = array_flip($this->rawRecordRepository->filterHeldByUnresolvedIssues(
                array_map(static fn (IngestRawRecord $record): string => $record->getId(), $locked),
            ));

            foreach ($locked as $record) {
                if (isset($held[$record->getId()])) {
                    continue;
                }

                $marked[$record->getId()] = [
                    'path' => $record->getStoragePath(),
                    'bytes' => $record->getByteSize(),
                ];

                $record->markPayloadPruned($now);
            }

            // Пути пишутся в лог ДО коммита. Если процесс умрёт между коммитом
            // и удалением объектов, обработчик ошибки не выполнится и путь
            // исчез бы вместе со строкой; в логе он остаётся, и осиротевший
            // объект можно найти и убрать.
            if ([] !== $marked) {
                $this->logger->info('Raw objects are about to be deleted.', [
                    'storagePaths' => array_column($marked, 'path'),
                ]);
            }
        });

        $skipped = count($chunk) - count($marked);
        if ($skipped > 0) {
            // Не ошибка: запись перестала быть кандидатом, пока мы шли к ней.
            // Но и не пустяк — молча пропущенное удаление выглядело бы как
            // сделанное.
            $this->logger->info('Raw records stopped being prunable between selection and deletion.', [
                'records' => $skipped,
            ]);
        }

        $orphaned = 0;
        $freed = 0;

        foreach ($marked as $rawRecordId => $object) {
            try {
                $this->objectStorage->delete($object['path']);
                $freed += $object['bytes'];
            } catch (\Throwable $exception) {
                // Запись уже помечена, значит она не утверждает, что нагрузка
                // на месте. Объект остался и занимает место — путь уходит в
                // лог, чтобы его можно было убрать вручную. Его размер в
                // освобождённые не идёт: место занято, и отчёт не должен
                // утверждать обратное.
                ++$orphaned;
                $this->logger->error('Raw object left behind after its payload was marked pruned.', [
                    'rawRecordId' => $rawRecordId,
                    'storagePath' => $object['path'],
                    // Класс, а не сообщение: в тексте ошибок хранилища
                    // встречаются URL с учётными данными.
                    'exceptionClass' => $exception::class,
                ]);
            }
        }

        return $result->with(
            prunedPayloads: count($marked),
            bytesFreed: $freed,
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
            'prunedPayloads' => $result->prunedPayloads,
            'bytesFreed' => $result->bytesFreed,
            'heldByIssues' => $result->heldByIssues,
            'orphanedObjects' => $result->orphanedObjects,
        ];
    }
}
