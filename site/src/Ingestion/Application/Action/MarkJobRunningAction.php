<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Action;

use App\Ingestion\Application\Command\MarkJobRunningCommand;
use App\Ingestion\Exception\SyncJobNotFoundException;
use App\Ingestion\Repository\SyncJobRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class MarkJobRunningAction
{
    public function __construct(
        private SyncJobRepository $syncJobRepository,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(MarkJobRunningCommand $command): void
    {
        $job = $this->syncJobRepository->findByIdAndCompany($command->jobId, $command->companyId);
        if (null === $job) {
            throw new SyncJobNotFoundException('Sync job was not found.');
        }

        if (null !== $command->cursorSnapshot && null === $job->getCursorSnapshot()) {
            $job->setCursorSnapshot($command->cursorSnapshot);
        }

        $job->markRunning();
        $this->entityManager->flush();
    }
}
