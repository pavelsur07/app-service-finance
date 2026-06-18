<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Action;

use App\Ingestion\Application\Command\MarkJobFailedCommand;
use App\Ingestion\Exception\SyncJobNotFoundException;
use App\Ingestion\Repository\SyncJobRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class MarkJobFailedAction
{
    public function __construct(
        private SyncJobRepository $syncJobRepository,
        private MaybeFinalizeParentAction $maybeFinalizeParentAction,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(MarkJobFailedCommand $command): void
    {
        $job = $this->syncJobRepository->findByIdAndCompany($command->jobId, $command->companyId);
        if (null === $job) {
            throw new SyncJobNotFoundException('Sync job was not found.');
        }

        $job->markFailed($command->reason);
        $this->entityManager->flush();

        if (null !== $job->getParentJobId()) {
            ($this->maybeFinalizeParentAction)($job->getParentJobId(), $command->companyId);
            $this->entityManager->flush();
        }
    }
}
