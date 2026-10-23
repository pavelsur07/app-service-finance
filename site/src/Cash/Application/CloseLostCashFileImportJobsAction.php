<?php

declare(strict_types=1);

namespace App\Cash\Application;

use App\Cash\Entity\Import\CashFileImportJob;
use App\Cash\Service\Import\ImportLogger;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Закрывает задания файлового импорта, чьё сообщение Messenger потеряно: `queued` дольше порога
 * и сообщения с ID задания нет в failed-транспорте. Само задание такое уже не поднимется.
 *
 * Транзакцией владеет вызывающая консольная команда: в режиме execute кандидаты берутся
 * под FOR UPDATE.
 */
final class CloseLostCashFileImportJobsAction
{
    public const FAILURE_MESSAGE = 'Сообщение очереди потеряно: задание не было обработано и закрыто командой app:cash:close-lost-import-jobs. Загрузите файл заново.';

    public function __construct(
        private readonly Connection $connection,
        private readonly EntityManagerInterface $entityManager,
        private readonly ImportLogger $importLogger,
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

        if (!$execute) {
            return ['candidates' => \count($ids), 'closed' => 0];
        }
        if ($expectedCount !== \count($ids)) {
            throw new \DomainException(\sprintf('Число заданий изменилось: ожидалось %d, найдено %d. Повторите dry-run.', $expectedCount, \count($ids)));
        }

        foreach ($ids as $id) {
            $job = $this->entityManager->find(CashFileImportJob::class, (string) $id);
            \assert($job instanceof CashFileImportJob);
            $job->fail(self::FAILURE_MESSAGE);
            $importLog = $job->getImportLog();
            if (null !== $importLog && null === $importLog->getFinishedAt()) {
                $this->importLogger->finish($importLog);
            }
        }

        return ['candidates' => \count($ids), 'closed' => \count($ids)];
    }
}
