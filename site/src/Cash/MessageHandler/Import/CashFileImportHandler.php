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
                $this->entityManager->commit();

                return;
            }

            if (CashFileImportJob::STATUS_QUEUED !== $job->getStatus()) {
                $this->entityManager->commit();

                return;
            }

            $job->start();
            $this->entityManager->flush();
            $this->entityManager->commit();
        } catch (\Throwable $exception) {
            $this->entityManager->rollback();

            throw $exception;
        }

        try {
            $this->importService->import($job);
            $job->finishOk();
            $job->setErrorMessage(null);
            $this->entityManager->flush();
        } catch (\Throwable $exception) {
            $message = sprintf(
                '[%s] %s at %s:%d',
                $exception::class,
                $exception->getMessage(),
                $exception->getFile(),
                $exception->getLine()
            );
            $message = mb_substr($message, 0, 2000);

            $job->fail($message);
            $this->entityManager->flush();
        }
    }
}
