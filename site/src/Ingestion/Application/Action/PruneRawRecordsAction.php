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
        foreach ($chunk as $rawRecordId) {
            $result = $this->pruneOne($result, $rawRecordId, $notSeenSince);
        }

        return $result;
    }

    /**
     * Одна запись — одна транзакция, и удаление объекта ВНУТРИ неё.
     *
     * Раньше отметка коммитилась, а объект удалялся после: в этот промежуток
     * повторная загрузка успевала снять отметку, увидев ещё существующий
     * объект, — и retention удалял его следом. Оставалась запись, которая
     * утверждает, что нагрузка на месте, а чтение падало ошибкой хранилища.
     * Общая блокировка строки исключает это переплетение: дедуп ждёт, а
     * дождавшись, перечитывает состояние.
     *
     * Плата — сетевой вызов внутри транзакции. Он ограничен ОДНОЙ записью и
     * одним удалением: строки старше года, и спорить за них некому, кроме
     * той самой повторной загрузки, ради которой блокировка и берётся.
     */
    private function pruneOne(PruneRawRecordsResult $result, string $rawRecordId, \DateTimeImmutable $notSeenSince): PruneRawRecordsResult
    {
        $pruned = false;
        $orphaned = false;
        $bytes = 0;

        $this->entityManager->wrapInTransaction(function () use ($rawRecordId, $notSeenSince, &$pruned, &$orphaned, &$bytes): void {
            $locked = $this->rawRecordRepository->findManyPrunableForUpdate([$rawRecordId], $notSeenSince);
            if ([] === $locked) {
                // Запись перестала быть кандидатом, пока мы шли к ней: её
                // подтвердили заново или уже очистили.
                return;
            }

            $record = $locked[0];

            // Удержание перепроверяется УЖЕ ПОД блокировкой и отдельным
            // запросом: `FOR UPDATE` защищает строку сырья, но не отсутствие
            // строки в таблице проблем, и условие внутри блокирующей выборки
            // осталось бы снимком её собственного момента.
            if ([] !== $this->rawRecordRepository->filterHeldByUnresolvedIssues([$record->getId()])) {
                return;
            }

            $path = $record->getStoragePath();

            // Путь пишется в лог ДО удаления: если процесс умрёт между
            // удалением объекта и коммитом, откат вернёт отметку, а объекта
            // уже не будет — найти его можно будет только по этой записи.
            $this->logger->info('Raw object is about to be deleted.', ['storagePath' => $path]);

            $record->markPayloadPruned($this->clock->now()->setTimezone(new \DateTimeZone(date_default_timezone_get())));
            $pruned = true;
            $bytes = $record->getByteSize();

            try {
                $this->objectStorage->delete($path);
            } catch (\Throwable $exception) {
                // Отметка остаётся: запись не должна утверждать, что нагрузка
                // на месте. Объект занимает место — путь уходит в лог, чтобы
                // его можно было убрать вручную, а его размер в освобождённые
                // не идёт.
                $orphaned = true;
                $bytes = 0;
                $this->logger->error('Raw object left behind after its payload was marked pruned.', [
                    'rawRecordId' => $record->getId(),
                    'storagePath' => $path,
                    // Класс, а не сообщение: в тексте ошибок хранилища
                    // встречаются URL с учётными данными.
                    'exceptionClass' => $exception::class,
                ]);
            }
        });

        return $result->with(
            prunedPayloads: $pruned ? 1 : 0,
            bytesFreed: $bytes,
            orphanedObjects: $orphaned ? 1 : 0,
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
