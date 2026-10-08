<?php

declare(strict_types=1);

namespace App\Cash\MessageHandler\Import;

use App\Cash\Entity\Import\CashFileImportJob;
use App\Cash\Message\Import\CashFileImportMessage;
use App\Cash\Service\Import\File\CashFileImportService;
use App\Cash\Service\Import\ImportLogger;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class CashFileImportHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CashFileImportService $importService,
        private readonly ManagerRegistry $managerRegistry,
        private readonly ImportLogger $importLogger,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(CashFileImportMessage $message): void
    {
        $this->entityManager->beginTransaction();

        try {
            $job = $this->entityManager->find(
                CashFileImportJob::class,
                $message->getJobId(),
                LockMode::PESSIMISTIC_WRITE
            );
            if (!$job instanceof CashFileImportJob) {
                $this->entityManager->commit();

                return;
            }

            if (CashFileImportJob::STATUS_QUEUED !== $job->getStatus()) {
                $this->entityManager->commit();
                // Повторная доставка уже взятой задачи: молчаливый выход здесь
                // прятал 66 зависших в processing задач на проде.
                $this->logger->warning('Cash file import skipped: job is not queued', [
                    'jobId' => $message->getJobId(),
                    'status' => $job->getStatus(),
                ]);

                return;
            }

            $job->start();
            $this->entityManager->flush();
            $this->entityManager->commit();
        } catch (\Throwable $exception) {
            $this->entityManager->rollback();

            throw $exception;
        }

        $companyId = (string) $job->getCompany()->getId();
        $logContext = ['jobId' => $message->getJobId(), 'companyId' => $companyId];
        $this->logger->info('Cash file import started', $logContext);

        $importException = null;

        try {
            $this->importService->import($job);
        } catch (\Throwable $exception) {
            $importException = $exception;
        }

        // Ошибка БД внутри импорта закрывает EM: без сброса fail() не сохранить,
        // сообщение уходило в retry, а retry пропускал задачу в processing навсегда.
        $entityManager = $this->entityManager;
        if (!$entityManager->isOpen()) {
            $this->managerRegistry->resetManager();
            $resetManager = $this->managerRegistry->getManager();
            \assert($resetManager instanceof EntityManagerInterface);
            $entityManager = $resetManager;
        }

        $freshJob = $entityManager->find(CashFileImportJob::class, $message->getJobId());
        if (!$freshJob instanceof CashFileImportJob) {
            return;
        }

        if (null === $importException) {
            $freshJob->finishOk();
            $freshJob->setErrorMessage(null);
            $entityManager->flush();
            $this->logger->info('Cash file import finished', $logContext + ['status' => $freshJob->getStatus()]);

            return;
        }

        // Текст исключения в лог не идёт: у DBAL в нём SQL с данными строк выписки.
        // Невалидный вход (формат файла, чужой счёт) — warning, остальное — инцидент.
        $level = $importException instanceof \DomainException || $importException instanceof \InvalidArgumentException
            ? LogLevel::WARNING
            : LogLevel::ERROR;
        $this->logger->log($level, 'Cash file import failed', $logContext + [
            'exceptionClass' => $importException::class,
            'exceptionAt' => sprintf('%s:%d', $importException->getFile(), $importException->getLine()),
        ]);

        $message = sprintf(
            '[%s] %s at %s:%d',
            $importException::class,
            $importException->getMessage(),
            $importException->getFile(),
            $importException->getLine()
        );
        $message = mb_substr($message, 0, 2000);

        $freshJob->fail($message);
        $importLog = $freshJob->getImportLog();
        if (null !== $importLog && null === $importLog->getFinishedAt()) {
            $this->importLogger->finish($importLog);
        }
        $entityManager->flush();

        // После фиксации failed: сбой пересчёта не должен вернуть задачу в processing.
        try {
            $this->importService->recalculateCommittedRows($freshJob);
        } catch (\Throwable $recalcException) {
            $this->logger->error('Cash file import: balance recalculation after failure failed', $logContext + [
                'exceptionClass' => $recalcException::class,
            ]);
        }
    }
}
