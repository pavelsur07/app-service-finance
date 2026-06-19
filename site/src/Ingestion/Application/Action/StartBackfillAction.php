<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Action;

use App\Ingestion\Application\Command\StartBackfillCommand;
use App\Ingestion\Entity\SyncJob;
use App\Ingestion\Enum\SyncJobKind;
use App\Ingestion\Exception\ActiveBackfillExistsException;
use App\Ingestion\Repository\SyncJobRepository;
use Doctrine\ORM\EntityManagerInterface;

final readonly class StartBackfillAction
{
    public function __construct(
        private SyncJobRepository $syncJobRepository,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(StartBackfillCommand $command): string
    {
        $activeJob = $this->syncJobRepository->findLatestForResource(
            $command->companyId,
            $command->connectionRef,
            $command->resourceType,
            $command->shopRef,
        );

        if (null !== $activeJob) {
            throw new ActiveBackfillExistsException('Backfill for requested resource is already active.');
        }

        $job = new SyncJob(
            companyId: $command->companyId,
            connectionRef: $command->connectionRef,
            source: $command->source,
            resourceType: $command->resourceType,
            kind: SyncJobKind::BACKFILL,
            windowFrom: $command->windowFrom,
            windowTo: $command->windowTo,
            shopRef: $command->shopRef,
        );

        $this->entityManager->persist($job);
        $this->entityManager->flush();

        return $job->getId();
    }
}
