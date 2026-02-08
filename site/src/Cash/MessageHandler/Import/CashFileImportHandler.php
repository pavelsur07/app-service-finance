<?php

namespace App\Cash\MessageHandler\Import;

use App\Cash\Entity\Import\CashFileImportJob;
use App\Cash\Message\Import\CashFileImportMessage;
use App\Cash\Service\Import\File\CashFileImportService;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class CashFileImportHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CashFileImportService $importService,
    ) {
    }

    public function __invoke(CashFileImportMessage $message): void
    {
        $runId = bin2hex(random_bytes(4));
        $this->entityManager->beginTransaction();

        try {
            $job = $this->entityManager->find(
                CashFileImportJob::class,
                $message->getJobId(),
                LockMode::PESSIMISTIC_WRITE
            );
            if (!$job instanceof CashFileImportJob) {
                $jobReference = $this->entityManager->getReference(CashFileImportJob::class, $message->getJobId());
                $this->debugMark($jobReference, 'job_not_found', $runId);
                $this->entityManager->commit();

                return;
            }

            $this->debugMark($job, 'handler_enter', $runId);

            if (CashFileImportJob::STATUS_QUEUED !== $job->getStatus()) {
                $this->debugMark($job, sprintf('skip_not_queued status=%s', $job->getStatus()), $runId);
                $this->entityManager->commit();

                return;
            }

            $this->debugMark($job, 'queued_confirmed', $runId);

            $job->start();
            $this->entityManager->flush();
            $this->debugMark($job, 'job_started_committed', $runId);
            $this->entityManager->commit();
        } catch (\Throwable $exception) {
            $this->entityManager->rollback();

            throw $exception;
        }

        $importException = null;

        try {
            $this->debugMark($job, 'before_import', $runId);
            $this->importService->import($job);
        } catch (\Throwable $exception) {
            $importException = $exception;
        }

        $freshJob = $this->entityManager->find(CashFileImportJob::class, $message->getJobId());
        if (!$freshJob instanceof CashFileImportJob) {
            $this->debugMark($job, 'job_not_found', $runId);
            return;
        }

        if (null === $importException) {
            $this->debugMark($freshJob, 'after_import', $runId);
            $freshJob->finishOk();
            $freshJob->setErrorMessage(null);
            $this->entityManager->flush();

            return;
        }

        $this->debugMark(
            $freshJob,
            sprintf('import_exception [%s] %s', $importException::class, $importException->getMessage()),
            $runId
        );
        $debugTimestamp = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $message = sprintf(
            'DBG:import_exception %s [%s] %s at %s:%d',
            $debugTimestamp,
            $importException::class,
            $importException->getMessage(),
            $importException->getFile(),
            $importException->getLine()
        );
        $message = mb_substr($message, 0, 2000);

        $freshJob->fail($message);
        $this->entityManager->flush();
    }

    private function debugMark(CashFileImportJob $job, string $stage, string $runId): void
    {
        $timestamp = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $line = sprintf('DBG:%s %s run=%s', $stage, $timestamp, $runId);
        $existingMessage = $job->getErrorMessage();
        $message = $existingMessage ? $existingMessage . "\n" . $line : $line;
        if (mb_strlen($message) > 2000) {
            $message = mb_substr($message, -2000);
        }
        $job->setErrorMessage($message);
        $this->entityManager->flush();
    }
}
