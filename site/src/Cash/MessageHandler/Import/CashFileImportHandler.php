<?php

namespace App\Cash\MessageHandler\Import;

use App\Cash\Entity\Import\CashFileImportJob;
use App\Cash\Entity\Import\ImportLog;
use App\Cash\Message\Import\CashFileImportMessage;
use App\Cash\Service\Import\ImportLogger;
use App\Cash\Service\Import\File\CashFileImportService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class CashFileImportHandler
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CashFileImportService $importService,
        private readonly ImportLogger $importLogger,
    ) {
    }

    public function __invoke(CashFileImportMessage $message): void
    {
        $job = $this->entityManager->find(CashFileImportJob::class, $message->getJobId());
        if (!$job instanceof CashFileImportJob) {
            throw new \InvalidArgumentException('Cash file import job not found.');
        }

        $job->start();
        $this->entityManager->flush();

        try {
            $this->importService->import($job);
            $job->finishOk();
            $this->entityManager->flush();
        } catch (\Throwable $exception) {
            $job->fail($exception->getMessage());
            $this->entityManager->flush();

            throw $exception;
        } finally {
            $importLog = $job->getImportLog();
            if ($importLog instanceof ImportLog) {
                $this->importLogger->finish($importLog);
            }
        }
    }
}
