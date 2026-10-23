<?php

declare(strict_types=1);

namespace App\Cash\Application;

use App\Cash\Entity\Import\CashFileImportJob;
use App\Cash\Entity\Import\ImportLog;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Закрывает журналы импорта ДДС, оставшиеся без `finished_at`: импорт начат раньше порога, и
 * журнал не ждёт живого задания файлового импорта (`queued`/`processing` закроет само задание
 * или ремонт потерянных заданий). Время завершения — `finished_at` задания, если оно есть,
 * иначе время старта: настоящее неизвестно. Счётчики журнала не меняются.
 *
 * Транзакцией владеет вызывающая консольная команда: в режиме execute кандидаты берутся
 * под FOR UPDATE.
 */
final class CloseOpenImportLogsAction
{
    public function __construct(
        private readonly Connection $connection,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param \DateTimeImmutable $startedBefore граница в UTC — так `ImportLogger` пишет `started_at`
     *
     * @return array{candidates: int, closed: int}
     */
    public function __invoke(string $companyId, \DateTimeImmutable $startedBefore, bool $execute, ?int $expectedCount = null): array
    {
        if ($execute && !$this->connection->isTransactionActive()) {
            throw new \LogicException('Закрытие журналов выполняется внутри транзакции вызывающей команды.');
        }

        /** @var list<array{id: string, finished_at: string}> $rows */
        $rows = $this->connection->fetchAllAssociative(
            <<<'SQL'
                SELECT l.id::text AS id,
                       COALESCE(
                           (SELECT MAX(j.finished_at) FROM cash_file_import_jobs j WHERE j.import_log_id = l.id),
                           l.started_at
                       )::text AS finished_at
                FROM import_log l
                WHERE l.company_id = :company
                  AND l.finished_at IS NULL
                  AND l.started_at < :before
                  AND NOT EXISTS (
                      SELECT 1 FROM cash_file_import_jobs j
                      WHERE j.import_log_id = l.id AND j.status IN (:queued, :processing)
                  )
                ORDER BY l.id
                SQL.($execute ? ' FOR UPDATE OF l' : ''),
            [
                'company' => $companyId,
                'before' => $startedBefore->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                'queued' => CashFileImportJob::STATUS_QUEUED,
                'processing' => CashFileImportJob::STATUS_PROCESSING,
            ],
        );

        if (!$execute) {
            return ['candidates' => \count($rows), 'closed' => 0];
        }
        if ($expectedCount !== \count($rows)) {
            throw new \DomainException(\sprintf('Число журналов изменилось: ожидалось %d, найдено %d. Повторите dry-run.', $expectedCount, \count($rows)));
        }

        foreach ($rows as $row) {
            $log = $this->entityManager->find(ImportLog::class, $row['id']);
            \assert($log instanceof ImportLog);
            $log->setFinishedAt(new \DateTimeImmutable($row['finished_at']));
        }

        return ['candidates' => \count($rows), 'closed' => \count($rows)];
    }
}
