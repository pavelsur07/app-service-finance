<?php

declare(strict_types=1);

namespace App\Cash\Application;

use App\Cash\Entity\Import\CashFileImportJob;
use App\Cash\Infrastructure\Messenger\ImportQueueProbeInterface;
use App\Cash\Service\Import\ImportLogger;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Закрывает задания файлового импорта, чьё сообщение Messenger потеряно: `queued` дольше порога,
 * сообщения с ID задания нет ни в failed-транспорте, ни в живой очереди (stream и отложенные
 * повторы Redis). Очередь недоступна — отказ. Закрытие обратимо (`CashFileImportJob::failAsLost()`):
 * сообщение, которое всё же доставят, запустит задание.
 *
 * Транзакцией владеет вызывающая консольная команда: в режиме execute кандидаты берутся
 * под FOR UPDATE.
 */
final class CloseLostCashFileImportJobsAction
{
    public function __construct(
        private readonly Connection $connection,
        private readonly EntityManagerInterface $entityManager,
        private readonly ImportLogger $importLogger,
        private readonly ImportQueueProbeInterface $queueProbe,
    ) {
    }

    /**
     * @return array{candidates: int, closed: int}
     */
    public function __invoke(string $companyId, \DateTimeImmutable $createdBefore, bool $execute, ?int $expectedCount = null): array
    {
        if ($execute && !$this->connection->isTransactionActive()) {
            throw new \LogicException('Закрытие заданий выполняется внутри транзакции вызывающей команды.');
        }

        $ids = $this->connection->fetchFirstColumn(
            <<<'SQL'
                SELECT j.id::text
                FROM cash_file_import_jobs j
                WHERE j.company_id = :company
                  AND j.status = :queued
                  AND j.created_at < :before
                  AND NOT EXISTS (
                      SELECT 1 FROM messenger_messages m WHERE strpos(m.body, j.id::text) > 0
                  )
                ORDER BY j.id
                SQL.($execute ? ' FOR UPDATE OF j' : ''),
            [
                'company' => $companyId,
                'queued' => CashFileImportJob::STATUS_QUEUED,
                'before' => $createdBefore->format('Y-m-d H:i:s'),
            ],
        );

        $ids = array_map('strval', $ids);
        $queued = $this->queueProbe->findQueued($ids);
        $ids = array_values(array_diff($ids, $queued));

        if (!$execute) {
            return ['candidates' => \count($ids), 'closed' => 0];
        }
        if ($expectedCount !== \count($ids)) {
            throw new \DomainException(\sprintf('Число заданий изменилось: ожидалось %d, найдено %d. Повторите dry-run.', $expectedCount, \count($ids)));
        }

        foreach ($ids as $id) {
            $job = $this->entityManager->find(CashFileImportJob::class, $id);
            \assert($job instanceof CashFileImportJob);
            $job->failAsLost();
            $importLog = $job->getImportLog();
            if (null !== $importLog && null === $importLog->getFinishedAt()) {
                $this->importLogger->finish($importLog);
            }
        }

        return ['candidates' => \count($ids), 'closed' => \count($ids)];
    }
}
