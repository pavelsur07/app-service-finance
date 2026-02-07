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
        $this->entityManager->beginTransaction();

        try {
            $job = $this->entityManager->find(
                CashFileImportJob::class,
                $message->getJobId(),
                LockMode::PESSIMISTIC_WRITE
            );
            if (!$job instanceof CashFileImportJob) {
                $this->entityManager->rollback();

                return;
            }

            $this->debugMark($job, 'handler_enter');

            if (CashFileImportJob::STATUS_QUEUED !== $job->getStatus()) {
                $this->entityManager->commit();

                return;
            }

            $this->debugMark($job, 'queued_confirmed');

            $job->start();
            $this->entityManager->flush();
            $this->debugMark($job, 'job_started_committed');
            $this->entityManager->commit();
        } catch (\Throwable $exception) {
            $this->entityManager->rollback();

            throw $exception;
        }

        $importException = null;

        try {
            $this->debugMark($job, 'before_import');
            $this->importService->import($job);
        } catch (\Throwable $exception) {
            $importException = $exception;
        }

        $freshJob = $this->entityManager->find(CashFileImportJob::class, $message->getJobId());
        if (!$freshJob instanceof CashFileImportJob) {
            return;
        }

        if (null === $importException) {
            $this->debugMark($freshJob, 'after_import');
            $freshJob->finishOk();
            $freshJob->setErrorMessage(null);
            $this->entityManager->flush();

            return;
        }

        $this->debugMark($freshJob, 'import_exception');
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

    private function debugMark(CashFileImportJob $job, string $stage): void
    {
        $timestamp = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $job->setErrorMessage(sprintf('DBG:%s %s', $stage, $timestamp));
        $this->entityManager->flush();
    }
}
