<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Action;

use App\Ingestion\Application\Command\MarkJobCompletedCommand;
use App\Ingestion\Exception\SyncJobNotFoundException;
use App\Ingestion\Repository\SyncJobRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class MarkJobCompletedAction
{
    public function __construct(
        private SyncJobRepository $syncJobRepository,
        private MaybeFinalizeParentAction $maybeFinalizeParentAction,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(MarkJobCompletedCommand $command): void
    {
        $job = $this->syncJobRepository->findByIdAndCompany($command->jobId, $command->companyId);
        if (null === $job) {
            throw new SyncJobNotFoundException('Sync job was not found.');
        }

        $job->markCompleted();
        $this->entityManager->flush();

        if (null !== $job->getParentJobId()) {
            ($this->maybeFinalizeParentAction)($job->getParentJobId(), $command->companyId);
            $this->entityManager->flush();
        }
    }
}
